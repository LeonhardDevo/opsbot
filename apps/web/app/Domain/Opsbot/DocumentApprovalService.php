<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Notifications\OpsNotifier;
use App\Domain\Opsbot\Support\LocalTime;
use App\Enums\ApprovalStatus;
use App\Enums\ArchiveReason;
use App\Enums\DocumentStatus;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Knowledge\DocumentService;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Outbox\Topics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DocumentApprovalService
{
    public const int REVIEW_DAYS = 365;

    public const int REVIEW_RENOTIFY_DAYS = 30;

    public function __construct(
        private DocumentService $documents,
        private Auditor $auditor,
        private OpsNotifier $notifier,
    ) {}

    /** @throws OpsbotException */
    public function approve(Actor $actor, Document $document, ?string $effectiveDate = null, ?string $reviewDueAt = null): Document
    {
        if (! $actor->can('knowledge.approve') || $document->workspace_id !== $actor->workspaceId()) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $document, $effectiveDate, $reviewDueAt): Document {
            $document = Document::query()->forWorkspace($actor->workspace)->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->approval_status !== ApprovalStatus::Draft) {
                throw new OpsbotException('documents.not_draft');
            }

            if ($document->status !== DocumentStatus::Indexed) {
                throw new OpsbotException('documents.not_indexed');
            }

            $today = $actor->workspace->today();
            $effective = CarbonImmutable::parse($effectiveDate ?? $today);
            $review = $reviewDueAt !== null ? CarbonImmutable::parse($reviewDueAt) : $effective->addDays(self::REVIEW_DAYS);

            $document->fill([
                'approval_status' => ApprovalStatus::Approved,
                'approved_by' => $actor->id(),
                'approved_at' => now(),
                'effective_date' => $effective->toDateString(),
                'review_due_at' => $review->toDateString(),
                'review_notified_on' => null,
            ])->save();

            $this->documents->publishMetadataChanged($document);

            $this->auditor->record(
                'document.approved',
                $document,
                ['approval_status' => [ApprovalStatus::Draft->value, ApprovalStatus::Approved->value]],
                ['effective_date' => $effective->toDateString(), 'review_due_at' => $review->toDateString()],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: $document->title,
            );

            if ($document->supersedes_document_id !== null && $effective->toDateString() <= $today) {
                $predecessor = Document::query()->forWorkspace($actor->workspace)->whereKey($document->supersedes_document_id)->lockForUpdate()->first();

                if ($predecessor !== null && $predecessor->approval_status !== ApprovalStatus::Archived) {
                    $this->archiveDocument($predecessor, $actor->user, ArchiveReason::Superseded, Auditor::SOURCE_WEB);
                }
            }

            return $document;
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array{approved: int, skipped: int}
     *
     * @throws OpsbotException
     */
    public function approveBulk(Actor $actor, array $ids, ?string $effectiveDate = null): array
    {
        if (! $actor->can('knowledge.approve')) {
            throw OpsbotException::forbidden();
        }

        $documents = Document::query()->forWorkspace($actor->workspace)->whereIn('id', $ids)->orderBy('id')->get();
        $approved = 0;

        foreach ($documents as $document) {
            try {
                $this->approve($actor, $document, $effectiveDate);
                $approved++;
            } catch (OpsbotException) {
                continue;
            }
        }

        return ['approved' => $approved, 'skipped' => count(array_unique($ids)) - $approved];
    }

    /** @throws OpsbotException */
    public function archive(Actor $actor, Document $document): Document
    {
        if (! $actor->can('knowledge.approve') || $document->workspace_id !== $actor->workspaceId()) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $document): Document {
            $document = Document::query()->forWorkspace($actor->workspace)->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->approval_status === ApprovalStatus::Archived) {
                throw new OpsbotException('documents.already_archived');
            }

            return $this->archiveDocument($document, $actor->user, ArchiveReason::Manual, Auditor::SOURCE_WEB);
        });
    }

    /** @return array{review_notices: int, archived: int} */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $archived = 0;
        $notices = 0;

        $workspaceIds = Document::query()
            ->withoutGlobalScopes()
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            $workspace = Workspace::query()->find((int) $workspaceId);

            if ($workspace === null) {
                continue;
            }

            $today = $now->setTimezone($workspace->zone())->toDateString();
            $archived += $this->archiveSuperseded($workspace, $today);
            $notices += $this->reviewNotices($workspace, $today);
        }

        return ['review_notices' => $notices, 'archived' => $archived];
    }

    private function archiveSuperseded(Workspace $workspace, string $today): int
    {
        $successors = Document::query()
            ->forWorkspace($workspace)
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->whereNotNull('supersedes_document_id')
            ->whereDate('effective_date', '<=', $today)
            ->get();

        $count = 0;

        foreach ($successors as $successor) {
            DB::transaction(function () use ($workspace, $successor, &$count): void {
                $predecessor = Document::query()
                    ->forWorkspace($workspace)
                    ->whereKey($successor->supersedes_document_id)
                    ->lockForUpdate()
                    ->first();

                if ($predecessor !== null && $predecessor->approval_status !== ApprovalStatus::Archived) {
                    $this->archiveDocument($predecessor, null, ArchiveReason::Superseded, Auditor::SOURCE_SYSTEM);
                    $count++;
                }
            });
        }

        return $count;
    }

    private function reviewNotices(Workspace $workspace, string $today): int
    {
        $threshold = CarbonImmutable::parse($today)->subDays(self::REVIEW_RENOTIFY_DAYS)->toDateString();

        $documents = Document::query()
            ->forWorkspace($workspace)
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->whereNotNull('review_due_at')
            ->whereDate('review_due_at', '<=', $today)
            ->where(fn ($query) => $query->whereNull('review_notified_on')->orWhereDate('review_notified_on', '<=', $threshold))
            ->orderBy('id')
            ->get();

        $count = 0;

        foreach ($documents as $document) {
            DB::transaction(function () use ($workspace, $document, $today, &$count): void {
                foreach ($this->reviewRecipients($workspace, $document) as $recipient) {
                    $this->notifier->notify(Topics::DOCUMENT_REVIEW_DUE, $recipient, $workspace->id, fn (string $locale): array => [
                        'document' => [
                            'id' => $document->id,
                            'title' => $document->title,
                            'version' => $document->version,
                            'review_due_local' => LocalTime::date($document->review_due_at, $locale),
                        ],
                        'web_url' => OpsNotifier::url('/knowledge/'.$document->id),
                    ]);
                    $count++;
                }

                $document->forceFill(['review_notified_on' => $today])->save();
            });
        }

        return $count;
    }

    /** @return list<User> */
    private function reviewRecipients(Workspace $workspace, Document $document): array
    {
        if ($document->approved_by !== null) {
            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $document->approved_by)
                ->first();

            if ($membership !== null && $membership->status === MemberStatus::Active && $membership->role->allows('knowledge.approve')) {
                $approver = User::query()->find($document->approved_by);

                if ($approver !== null) {
                    return [$approver];
                }
            }
        }

        return array_values($workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->wherePivot('status', MemberStatus::Active->value)
            ->orderBy('users.id')
            ->get()
            ->all());
    }

    private function archiveDocument(Document $document, ?User $actor, ArchiveReason $reason, string $source): Document
    {
        $from = $document->approval_status;
        $document->fill([
            'approval_status' => ApprovalStatus::Archived,
            'archived_at' => now(),
            'archived_by' => $actor?->id,
            'archive_reason' => $reason,
        ])->save();

        $this->documents->publishMetadataChanged($document);

        $this->auditor->record(
            'document.archived',
            $document,
            ['approval_status' => [$from->value, ApprovalStatus::Archived->value]],
            ['reason' => $reason->value],
            actor: $actor,
            workspace: $document->workspace_id,
            subjectLabel: $document->title,
            source: $source,
        );

        return $document;
    }
}
