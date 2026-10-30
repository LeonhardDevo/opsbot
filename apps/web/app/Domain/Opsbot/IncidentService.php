<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Notifications\OpsNotifier;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\LocalTime;
use App\Domain\Opsbot\Support\WorkspaceCounters;
use App\Enums\IncidentCategory;
use App\Enums\IncidentEventType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\MemberStatus;
use App\Enums\RoutingCategory;
use App\Enums\Severity;
use App\Locale\WithLocale;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Outbox\Topics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class IncidentService
{
    public const int DESCRIPTION_MAX = 2000;

    public function __construct(
        private WorkspaceCounters $counters,
        private DepartmentScope $scope,
        private RoutingResolver $routing,
        private Auditor $auditor,
        private OpsNotifier $notifier,
    ) {}

    public static function defaultSeverity(IncidentCategory $category): Severity
    {
        return match ($category) {
            IncidentCategory::Injury => Severity::High,
            IncidentCategory::Damage, IncidentCategory::Complaint, IncidentCategory::LostItem => Severity::Medium,
            IncidentCategory::NoAccess, IncidentCategory::Other => Severity::Low,
        };
    }

    public static function title(IncidentCategory $category, string $description, string $locale): string
    {
        $label = WithLocale::run($locale, fn (): string => $category->label());
        $excerpt = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $description)), 80, '');

        return Str::limit($label.' — '.$excerpt, 160, '');
    }

    /**
     * @param  array{category: string, severity?: string|null, description: string, client_ref?: string|null, department_id?: int|null, assignee_id?: int|null}  $data
     *
     * @throws OpsbotException
     */
    public function create(
        Actor $actor,
        array $data,
        IncidentSource $source,
        ?int $aiQueryId = null,
        ?string $actionId = null,
        string $auditSource = Auditor::SOURCE_WEB,
    ): Incident {
        if (! $actor->can('incidents.create')) {
            throw OpsbotException::forbidden();
        }

        $category = IncidentCategory::from($data['category']);
        $severity = isset($data['severity']) ? Severity::from($data['severity']) : self::defaultSeverity($category);
        $description = Str::limit(trim($data['description']), self::DESCRIPTION_MAX, '');

        if ($description === '') {
            throw new OpsbotException('incidents.description_required');
        }

        $departmentId = $data['department_id'] ?? $this->scope->primaryDepartmentId($actor->user, $actor->workspaceId());

        if ($departmentId !== null && ! Department::query()->forWorkspace($actor->workspace)->whereKey($departmentId)->exists()) {
            throw new OpsbotException('departments.invalid');
        }

        $assignee = null;

        if (isset($data['assignee_id']) && $actor->can('incidents.manage')) {
            $assignee = $this->activeMember($actor->workspace, (int) $data['assignee_id']);
        }

        $assignee ??= $this->routing->resolve($actor->workspace, RoutingCategory::from($category->value), $departmentId)['user'];

        return DB::transaction(function () use ($actor, $category, $severity, $description, $data, $departmentId, $assignee, $source, $aiQueryId, $actionId, $auditSource): Incident {
            $incident = new Incident;
            $incident->fill([
                'workspace_id' => $actor->workspaceId(),
                'number' => $this->counters->next($actor->workspaceId(), WorkspaceCounters::INCIDENT),
                'category' => $category,
                'severity' => $severity,
                'status' => IncidentStatus::Open,
                'title' => self::title($category, $description, $actor->workspace->locale),
                'description' => $description,
                'client_ref' => isset($data['client_ref']) && trim((string) $data['client_ref']) !== '' ? Str::limit(trim((string) $data['client_ref']), 120, '') : null,
                'department_id' => $departmentId,
                'reporter_id' => $actor->id(),
                'assignee_id' => $assignee?->id,
                'source' => $source,
                'ai_query_id' => $aiQueryId,
                'ai_query_action_id' => $actionId,
            ]);
            $incident->save();

            $this->event($incident, $actor->user, IncidentEventType::Created, null, IncidentStatus::Open->value);

            $this->auditor->record(
                'incident.created',
                $incident,
                metadata: array_filter([
                    'category' => $category->value,
                    'severity' => $severity->value,
                    'assignee_id' => $assignee?->id,
                    'ai_query_id' => $aiQueryId,
                ], fn (mixed $value): bool => $value !== null),
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: DisplayNumber::incident($incident->number).' '.$incident->title,
                source: $auditSource,
            );

            if ($assignee !== null && $assignee->id !== $actor->id()) {
                $this->notifyCreated($incident, $assignee, $actor->user);
            }

            return $incident;
        });
    }

    /** @return list<IncidentStatus> */
    public static function allowedTransitions(IncidentStatus $from, bool $assignee, bool $manage): array
    {
        $allowed = [];

        if ($from === IncidentStatus::Open && ($manage || $assignee)) {
            $allowed[] = IncidentStatus::InProgress;
        }

        if ($from === IncidentStatus::Resolved && $manage) {
            $allowed[] = IncidentStatus::InProgress;
        }

        if (in_array($from, [IncidentStatus::Open, IncidentStatus::InProgress], true) && ($manage || $assignee)) {
            $allowed[] = IncidentStatus::Resolved;
        }

        if (in_array($from, [IncidentStatus::Open, IncidentStatus::InProgress, IncidentStatus::Resolved], true) && $manage) {
            $allowed[] = IncidentStatus::Closed;
        }

        return $allowed;
    }

    public static function commentRequired(IncidentStatus $from, IncidentStatus $to): bool
    {
        return ($from === IncidentStatus::Resolved && $to === IncidentStatus::InProgress)
            || (in_array($from, [IncidentStatus::Open, IncidentStatus::InProgress], true) && $to === IncidentStatus::Closed);
    }

    /** @return list<IncidentStatus> */
    public function transitionsFor(Actor $actor, Incident $incident): array
    {
        if (! $this->scope->canViewIncident($actor, $incident)) {
            return [];
        }

        return self::allowedTransitions(
            $incident->status,
            $incident->assignee_id === $actor->id(),
            $this->scope->canManageIncident($actor, $incident),
        );
    }

    /**
     * @param  array{status?: string|null, severity?: string|null, category?: string|null, assignee_id?: int|null, comment?: string|null}  $data
     *
     * @throws OpsbotException
     */
    public function update(Actor $actor, Incident $incident, array $data, string $auditSource = Auditor::SOURCE_WEB): Incident
    {
        if (! $this->scope->canViewIncident($actor, $incident)) {
            throw OpsbotException::notFound();
        }

        $fields = array_intersect_key($data, array_flip(['severity', 'category', 'assignee_id']));

        if ($fields !== [] && ! $this->scope->canManageIncident($actor, $incident)) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $incident, $data, $fields, $auditSource): Incident {
            $incident = Incident::query()->forWorkspace($actor->workspace)->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';
            $comment = $comment === '' ? null : Str::limit($comment, self::DESCRIPTION_MAX, '');

            $this->applyFields($actor, $incident, $fields, $auditSource);

            $statusChanged = false;

            if (isset($data['status']) && $data['status'] !== $incident->status->value) {
                $this->transition($actor, $incident, IncidentStatus::from($data['status']), $comment, $auditSource);
                $statusChanged = true;
            }

            if ($comment !== null && ! $statusChanged) {
                $this->addComment($actor, $incident, $comment, $auditSource);
            }

            return $incident;
        });
    }

    /** @throws OpsbotException */
    public function transition(Actor $actor, Incident $incident, IncidentStatus $to, ?string $comment, string $auditSource = Auditor::SOURCE_WEB): Incident
    {
        $from = $incident->status;

        if (! in_array($to, $this->transitionsFor($actor, $incident), true)) {
            throw OpsbotException::forbidden('incidents.transition_forbidden');
        }

        if (self::commentRequired($from, $to) && ($comment === null || trim($comment) === '')) {
            throw new OpsbotException('incidents.comment_required');
        }

        if ($to === IncidentStatus::InProgress && $incident->assignee_id === null) {
            $incident->assignee_id = $actor->id();
            $this->event($incident, $actor->user, IncidentEventType::Assigned, null, (string) $actor->id());
        }

        $incident->status = $to;
        $incident->resolved_at = $to === IncidentStatus::Resolved ? now() : ($to === IncidentStatus::InProgress ? null : $incident->resolved_at);
        $incident->closed_at = $to === IncidentStatus::Closed ? now() : null;
        $incident->save();

        $this->event($incident, $actor->user, IncidentEventType::StatusChanged, $from->value, $to->value, $comment);

        $this->auditor->record(
            'incident.status_changed',
            $incident,
            ['status' => [$from->value, $to->value]],
            $comment !== null ? ['comment' => Str::limit($comment, 200)] : [],
            actor: $actor->user,
            workspace: $actor->workspace,
            subjectLabel: DisplayNumber::incident($incident->number).' '.$incident->title,
            source: $auditSource,
        );

        $reporter = $incident->reporter;

        if ($reporter !== null && $reporter->id !== $actor->id() && in_array($to, [IncidentStatus::InProgress, IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            $assignee = $incident->assignee_id !== null ? User::query()->find($incident->assignee_id) : null;

            $this->notifier->notify(Topics::INCIDENT_STATUS_CHANGED, $reporter, $incident->workspace_id, fn (string $locale): array => array_filter([
                'incident' => [
                    'id' => $incident->id,
                    'display_number' => DisplayNumber::incident($incident->number),
                    'status' => $to->value,
                    'assignee_name' => $assignee?->displayName(),
                ],
                'note_excerpt' => $comment !== null ? Str::limit($comment, 300) : null,
            ], fn (mixed $value): bool => $value !== null));
        }

        return $incident;
    }

    /** @throws OpsbotException */
    public function comment(Actor $actor, Incident $incident, string $body, string $auditSource = Auditor::SOURCE_WEB): IncidentEvent
    {
        if (! $this->scope->canViewIncident($actor, $incident)) {
            throw OpsbotException::notFound();
        }

        $body = Str::limit(trim($body), self::DESCRIPTION_MAX, '');

        if ($body === '') {
            throw new OpsbotException('incidents.comment_required');
        }

        return DB::transaction(fn (): IncidentEvent => $this->addComment($actor, $incident, $body, $auditSource));
    }

    public function notifyCreated(Incident $incident, User $assignee, ?User $reporter): void
    {
        $workspace = Workspace::query()->findOrFail($incident->workspace_id);
        $department = $incident->department_id !== null ? Department::query()->forWorkspace($workspace)->find($incident->department_id) : null;

        $this->notifier->notify(Topics::INCIDENT_CREATED, $assignee, $workspace->id, fn (string $locale): array => [
            'incident' => [
                'id' => $incident->id,
                'display_number' => DisplayNumber::incident($incident->number),
                'category' => $incident->category->value,
                'severity' => $incident->severity->value,
                'description_excerpt' => Str::limit($incident->description, 300),
                'client_ref' => $incident->client_ref,
                'department_name' => $department?->name,
                'reporter_name' => $reporter?->displayName(),
                'created_local' => LocalTime::dateTime($incident->created_at, $workspace->zone(), $locale),
            ],
            'web_url' => OpsNotifier::url('/incidents/'.$incident->id),
        ]);
    }

    /** @param  array{severity?: string|null, category?: string|null, assignee_id?: int|null}  $fields */
    private function applyFields(Actor $actor, Incident $incident, array $fields, string $auditSource): void
    {
        $label = DisplayNumber::incident($incident->number).' '.$incident->title;

        if (isset($fields['severity']) && $fields['severity'] !== $incident->severity->value) {
            $from = $incident->severity->value;
            $incident->severity = Severity::from($fields['severity']);
            $this->event($incident, $actor->user, IncidentEventType::SeverityChanged, $from, $incident->severity->value);
            $this->auditor->record('incident.updated', $incident, ['severity' => [$from, $incident->severity->value]], actor: $actor->user, workspace: $actor->workspace, subjectLabel: $label, source: $auditSource);
        }

        if (isset($fields['category']) && $fields['category'] !== $incident->category->value) {
            $from = $incident->category->value;
            $incident->category = IncidentCategory::from($fields['category']);
            $this->event($incident, $actor->user, IncidentEventType::CategoryChanged, $from, $incident->category->value);
            $this->auditor->record('incident.updated', $incident, ['category' => [$from, $incident->category->value]], actor: $actor->user, workspace: $actor->workspace, subjectLabel: $label, source: $auditSource);
        }

        if (array_key_exists('assignee_id', $fields) && $fields['assignee_id'] !== $incident->assignee_id) {
            $from = $incident->assignee_id;
            $assignee = $fields['assignee_id'] !== null ? $this->activeMember($actor->workspace, (int) $fields['assignee_id']) : null;
            $incident->assignee_id = $assignee?->id;
            $this->event($incident, $actor->user, IncidentEventType::Assigned, $from !== null ? (string) $from : null, $assignee !== null ? (string) $assignee->id : null);
            $this->auditor->record('incident.assigned', $incident, ['assignee_id' => [$from, $assignee?->id]], actor: $actor->user, workspace: $actor->workspace, subjectLabel: $label, source: $auditSource);
        }

        $incident->save();
    }

    private function addComment(Actor $actor, Incident $incident, string $body, string $auditSource): IncidentEvent
    {
        $event = $this->event($incident, $actor->user, IncidentEventType::Comment, null, null, $body);
        $incident->touch();

        $this->auditor->record(
            'incident.commented',
            $incident,
            metadata: ['event_id' => $event->id],
            actor: $actor->user,
            workspace: $actor->workspace,
            subjectLabel: DisplayNumber::incident($incident->number).' '.$incident->title,
            source: $auditSource,
        );

        return $event;
    }

    private function event(Incident $incident, ?User $actor, IncidentEventType $type, ?string $from, ?string $to, ?string $body = null): IncidentEvent
    {
        return IncidentEvent::query()->create([
            'workspace_id' => $incident->workspace_id,
            'incident_id' => $incident->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'from_value' => $from,
            'to_value' => $to,
            'body' => $body,
        ]);
    }

    /** @throws OpsbotException */
    private function activeMember(Workspace $workspace, int $userId): User
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        if ($membership === null || $membership->status !== MemberStatus::Active) {
            throw new OpsbotException('incidents.assignee_invalid');
        }

        return User::query()->findOrFail($userId);
    }
}
