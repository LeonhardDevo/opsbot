<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Legal\LegalRepository;
use App\Locale\WithLocale;
use App\Models\LegalAcceptance;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Privacy\RetentionPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class EmployeeNotice
{
    public const string DOCUMENT = 'employee-notice';

    public function __construct(
        private Auditor $auditor,
        private LegalRepository $legal,
        private RetentionPolicy $retention,
    ) {}

    public function version(): string
    {
        return $this->legal->metadata(self::DOCUMENT, 'en')->version ?? '1.0';
    }

    /** @return array<string, int> */
    public function retentionValues(?Workspace $workspace = null): array
    {
        return [
            'ai_queries_days' => $this->retention->days('ai_queries', $workspace),
            'questions_days' => $this->retention->default('unanswered_questions'),
            'audit_days' => $this->retention->days('audit_logs', $workspace),
            'link_minutes' => (int) config('opsbot.share_links.ttl_minutes', 30),
        ];
    }

    public function shortHtml(string $locale, ?Workspace $workspace = null): string
    {
        $values = $this->retentionValues($workspace);

        return WithLocale::run($locale, function () use ($values): string {
            $lines = [
                '<b>'.e((string) __('employees.notice.title')).'</b>',
                e((string) __('employees.notice.intro')),
                '',
                '<b>'.e((string) __('employees.notice.stored_title')).'</b>',
                '• '.e((string) __('employees.notice.stored_questions')),
                '• '.e((string) __('employees.notice.stored_records')),
                '',
                '<b>'.e((string) __('employees.notice.visible_title')).'</b>',
                '• '.e((string) __('employees.notice.visible_to')),
                '',
                '<b>'.e((string) __('employees.notice.retention_title')).'</b>',
                '• '.e(trans_choice('employees.notice.retention_questions', $values['ai_queries_days'], ['count' => $values['ai_queries_days']])),
                '• '.e(trans_choice('employees.notice.retention_queue', $values['questions_days'], ['count' => $values['questions_days']])),
                '• '.e((string) __('employees.notice.retention_records')),
                '• '.e(trans_choice('employees.notice.retention_audit', $values['audit_days'], ['count' => $values['audit_days']])),
                '',
                e((string) __('employees.notice.ai_warning')),
                e((string) __('employees.notice.rights')),
                e((string) __('employees.notice.commands')),
            ];

            return mb_substr(implode("\n", $lines), 0, 1500);
        });
    }

    public function acknowledged(User $user, int $workspaceId, ?string $version = null): bool
    {
        return LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('document', self::DOCUMENT)
            ->where('version', $version ?? $this->version())
            ->exists();
    }

    public function acknowledge(User $user, int $workspaceId, string $version, ?TelegramAccount $account, ?string $locale = null): bool
    {
        if ($version !== $this->version()) {
            throw new OpsbotException('notice.version_mismatch');
        }

        return DB::transaction(function () use ($user, $workspaceId, $version, $account, $locale): bool {
            if ($account !== null) {
                $account->forceFill(['notice_version' => $version, 'notice_acked_at' => now()])->save();
            }

            if ($this->acknowledged($user, $workspaceId, $version)) {
                return false;
            }

            try {
                DB::transaction(fn () => LegalAcceptance::query()->create([
                    'user_id' => $user->id,
                    'workspace_id' => $workspaceId,
                    'document' => self::DOCUMENT,
                    'version' => $version,
                    'accepted_at' => now(),
                    'ip' => null,
                    'user_agent' => 'telegram',
                    'locale' => $locale ?? $user->preferredLocale(),
                ]));
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            $this->auditor->record(
                'legal.employee_notice_acknowledged',
                $user,
                metadata: ['version' => $version],
                actor: $user,
                workspace: $workspaceId,
                subjectLabel: $user->displayName(),
                source: Auditor::SOURCE_BOT,
            );

            return true;
        });
    }
}
