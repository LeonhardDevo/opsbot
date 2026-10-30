<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Notifications\OpsNotifier;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\LocalTime;
use App\Domain\Opsbot\Support\WorkspaceCounters;
use App\Enums\DismissReason;
use App\Enums\MemberStatus;
use App\Enums\QuestionReason;
use App\Enums\QuestionStatus;
use App\Enums\RefusalReason;
use App\Enums\RoutingCategory;
use App\Knowledge\DocumentService;
use App\Models\AiQuery;
use App\Models\Department;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Outbox\Topics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EscalationService
{
    public const int ANSWER_MAX = 2000;

    public function __construct(
        private WorkspaceCounters $counters,
        private DepartmentScope $scope,
        private RoutingResolver $routing,
        private Auditor $auditor,
        private OpsNotifier $notifier,
        private DocumentService $documents,
    ) {}

    public static function reasonFor(AiQuery $query, ?QuestionReason $requested = null): QuestionReason
    {
        if ($requested !== null) {
            return $requested;
        }

        return match ($query->refusal_reason) {
            RefusalReason::LowConfidence => QuestionReason::LowConfidence,
            RefusalReason::NotFound => QuestionReason::NotFound,
            RefusalReason::LimitReached => QuestionReason::LimitReached,
            default => $query->refused ? QuestionReason::NotFound : QuestionReason::UserRequest,
        };
    }

    /** @throws OpsbotException */
    public function escalate(Actor $actor, AiQuery $query, ?QuestionReason $reason = null, string $auditSource = Auditor::SOURCE_WEB): UnansweredQuestion
    {
        if ($query->workspace_id !== $actor->workspaceId() || $query->user_id !== $actor->id()) {
            throw OpsbotException::forbidden();
        }

        if (! $actor->can('assistant.use')) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $query, $reason, $auditSource): UnansweredQuestion {
            $locked = AiQuery::query()->forWorkspace($actor->workspace)->whereKey($query->id)->lockForUpdate()->firstOrFail();

            $existing = UnansweredQuestion::query()->forWorkspace($actor->workspace)->where('ai_query_id', $locked->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $departmentId = $this->scope->primaryDepartmentId($actor->user, $actor->workspaceId());
            $assignee = $this->routing->resolve($actor->workspace, RoutingCategory::Question, $departmentId)['user'];

            $question = UnansweredQuestion::query()->create([
                'workspace_id' => $actor->workspaceId(),
                'number' => $this->counters->next($actor->workspaceId(), WorkspaceCounters::QUESTION),
                'ai_query_id' => $locked->id,
                'question' => (string) $locked->question,
                'employee_user_id' => $actor->id(),
                'department_id' => $departmentId,
                'assignee_user_id' => $assignee?->id,
                'reason' => self::reasonFor($locked, $reason),
                'status' => QuestionStatus::Open,
            ]);

            $locked->forceFill(['escalated_question_id' => $question->id])->save();

            $this->auditor->record(
                'question.escalated',
                $question,
                metadata: ['ai_query_id' => $locked->id, 'reason' => $question->reason->value, 'assignee_id' => $assignee?->id],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: DisplayNumber::question($question->number),
                source: $auditSource,
            );

            if ($assignee !== null) {
                $this->notifyEscalated($question, $assignee, $actor->user);
            }

            return $question;
        });
    }

    /** @throws OpsbotException */
    public function answer(Actor $actor, UnansweredQuestion $question, string $text, string $auditSource = Auditor::SOURCE_WEB): UnansweredQuestion
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::ANSWER_MAX) {
            throw new OpsbotException('questions.answer_length');
        }

        if (! $this->scope->canAnswerQuestion($actor, $question)) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $question, $text, $auditSource): UnansweredQuestion {
            $question = UnansweredQuestion::query()->forWorkspace($actor->workspace)->whereKey($question->id)->lockForUpdate()->firstOrFail();

            if ($question->status !== QuestionStatus::Open) {
                throw new OpsbotException('questions.not_open');
            }

            $question->fill([
                'status' => QuestionStatus::Answered,
                'answer_text' => $text,
                'answered_by' => $actor->id(),
                'answered_at' => now(),
            ])->save();

            $this->auditor->record(
                'question.answered',
                $question,
                ['status' => [QuestionStatus::Open->value, QuestionStatus::Answered->value]],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: DisplayNumber::question($question->number),
                source: $auditSource,
            );

            $employee = $question->employee;

            if ($employee->erased_at === null) {
                $this->notifier->notify(Topics::QUESTION_ANSWERED, $employee, $question->workspace_id, fn (): array => [
                    'query_id' => $question->ai_query_id,
                    'question_id' => $question->id,
                    'question_excerpt' => Str::limit($question->question, 500),
                    'text_html' => nl2br(e($text), false),
                    'manager_name' => $actor->user->displayName(),
                ]);
            }

            return $question;
        });
    }

    /** @throws OpsbotException */
    public function dismiss(Actor $actor, UnansweredQuestion $question, DismissReason $reason, string $auditSource = Auditor::SOURCE_WEB): UnansweredQuestion
    {
        if (! $this->scope->canAnswerQuestion($actor, $question)) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $question, $reason, $auditSource): UnansweredQuestion {
            $question = UnansweredQuestion::query()->forWorkspace($actor->workspace)->whereKey($question->id)->lockForUpdate()->firstOrFail();

            if ($question->status !== QuestionStatus::Open) {
                throw new OpsbotException('questions.not_open');
            }

            $question->fill([
                'status' => QuestionStatus::Dismissed,
                'dismissed_reason' => $reason,
                'dismissed_by' => $actor->id(),
                'dismissed_at' => now(),
            ])->save();

            $this->auditor->record(
                'question.dismissed',
                $question,
                ['status' => [QuestionStatus::Open->value, QuestionStatus::Dismissed->value]],
                ['reason' => $reason->value],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: DisplayNumber::question($question->number),
                source: $auditSource,
            );

            return $question;
        });
    }

    /** @throws OpsbotException */
    public function convert(Actor $actor, UnansweredQuestion $question, ?string $title = null, string $auditSource = Auditor::SOURCE_WEB): UnansweredQuestion
    {
        if (! $actor->can('knowledge.manage') || ! $this->scope->canViewQuestion($actor, $question)) {
            throw OpsbotException::forbidden();
        }

        if (! $actor->privileged() && ! $this->scope->canAnswerQuestion($actor, $question)) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $question, $title, $auditSource): UnansweredQuestion {
            $question = UnansweredQuestion::query()->forWorkspace($actor->workspace)->whereKey($question->id)->lockForUpdate()->firstOrFail();

            if ($question->status !== QuestionStatus::Answered) {
                throw new OpsbotException('questions.not_answered');
            }

            $answeredBy = $question->answerer;
            $title = trim((string) $title);
            $title = $title !== '' ? $title : (string) __('questions.faq.title', ['question' => Str::limit($question->question, 200, '')]);
            $date = LocalTime::date($question->answered_at, $actor->workspace->locale) ?? '';

            $body = implode("\n\n", [
                '# '.$title,
                '## '.(string) __('questions.faq.question'),
                $question->question,
                '## '.(string) __('questions.faq.answer'),
                (string) $question->answer_text,
                (string) __('questions.faq.source', ['name' => $answeredBy?->displayName() ?? '', 'date' => $date]),
            ]);

            $document = $this->documents->createFaqDraft(
                $actor->workspace,
                $actor->user,
                $title,
                $body,
                $question->department_id !== null ? [$question->department_id] : [],
                $actor->workspace->locale,
            );

            $question->fill(['status' => QuestionStatus::ConvertedToDoc, 'document_id' => $document->id])->save();

            $this->auditor->record(
                'question.converted_to_doc',
                $question,
                ['status' => [QuestionStatus::Answered->value, QuestionStatus::ConvertedToDoc->value]],
                ['document_id' => $document->id],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: DisplayNumber::question($question->number),
                source: $auditSource,
            );

            return $question;
        });
    }

    /** @throws OpsbotException */
    public function reassign(Actor $actor, UnansweredQuestion $question, int $assigneeId): UnansweredQuestion
    {
        if (! $this->scope->canAnswerQuestion($actor, $question)) {
            throw OpsbotException::forbidden();
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $actor->workspaceId())
            ->where('user_id', $assigneeId)
            ->first();

        if ($membership === null || $membership->status !== MemberStatus::Active || ! $membership->role->allows('questions.answer')) {
            throw new OpsbotException('questions.assignee_invalid');
        }

        return DB::transaction(function () use ($actor, $question, $assigneeId): UnansweredQuestion {
            $from = $question->assignee_user_id;
            $question->assignee_user_id = $assigneeId;
            $question->save();

            $this->auditor->record(
                'question.escalated',
                $question,
                ['assignee_user_id' => [$from, $assigneeId]],
                ['reassigned' => true],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: DisplayNumber::question($question->number),
            );

            $assignee = User::query()->find($assigneeId);

            if ($assignee !== null && $assignee->id !== $actor->id() && $question->status === QuestionStatus::Open) {
                $this->notifyEscalated($question, $assignee, $question->employee);
            }

            return $question;
        });
    }

    public function notifyEscalated(UnansweredQuestion $question, User $assignee, ?User $employee): void
    {
        $workspace = Workspace::query()->findOrFail($question->workspace_id);
        $department = $question->department_id !== null ? Department::query()->forWorkspace($workspace)->find($question->department_id) : null;

        $this->notifier->notify(Topics::QUESTION_ESCALATED, $assignee, $workspace->id, fn (string $locale): array => [
            'question' => [
                'id' => $question->id,
                'display_number' => DisplayNumber::question($question->number),
                'question_excerpt' => Str::limit($question->question, 500),
                'employee_name' => $employee?->displayName(),
                'department_name' => $department?->name,
                'reason' => $question->reason->value,
                'asked_local' => LocalTime::dateTime($question->created_at, $workspace->zone(), $locale),
            ],
            'web_url' => OpsNotifier::url('/unanswered?focus='.$question->id),
        ]);
    }
}
