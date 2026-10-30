<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Billing\PlanLimits;
use App\Domain\Opsbot\Notifications\OpsNotifier;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Outbox\Topics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class LimitNotices
{
    public const array KEYS = ['seats', 'documents', 'storage_mb', 'ai_queries_monthly'];

    public function __construct(
        private PlanLimits $limits,
        private OpsNotifier $notifier,
    ) {}

    /** @return list<array{key: string, used: int, limit: int}> */
    public function warnings(Workspace $workspace): array
    {
        $ratio = (float) config('opsbot.limits.warning_ratio', 0.8);
        $warnings = [];

        foreach (self::KEYS as $key) {
            $limit = $this->limits->limit($workspace, $key);

            if ($limit === null || $limit <= 0) {
                continue;
            }

            $used = $this->limits->used($workspace, $key);

            if ($used >= $limit * $ratio) {
                $warnings[] = ['key' => $key, 'used' => $used, 'limit' => $limit];
            }
        }

        return $warnings;
    }

    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $hour = (int) config('opsbot.limits.notice_hour', 9);
        $sent = 0;

        foreach (Workspace::query()->orderBy('id')->cursor() as $workspace) {
            $local = $now->setTimezone($workspace->zone());

            if ($local->hour < $hour) {
                continue;
            }

            foreach (self::KEYS as $key) {
                $limit = $this->limits->limit($workspace, $key);

                if ($limit === null) {
                    continue;
                }

                $used = $this->limits->used($workspace, $key);

                if ($used < $limit) {
                    continue;
                }

                $sent += DB::transaction(function () use ($workspace, $key, $used, $limit, $local): int {
                    $inserted = DB::table('limit_notices')->insertOrIgnore([
                        'workspace_id' => $workspace->id,
                        'limit_key' => $key,
                        'notified_on' => $local->toDateString(),
                    ]);

                    if ($inserted === 0) {
                        return 0;
                    }

                    $recipients = $workspace->members()
                        ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
                        ->wherePivot('status', MemberStatus::Active->value)
                        ->orderBy('users.id')
                        ->get();

                    foreach ($recipients as $recipient) {
                        $this->notifier->notify(Topics::LIMITS_NOTICE, $recipient, $workspace->id, fn (): array => [
                            'limit_key' => $key,
                            'used' => $used,
                            'limit' => $limit,
                            'web_url' => OpsNotifier::url('/settings/billing'),
                        ]);
                    }

                    return 1;
                });
            }
        }

        return $sent;
    }
}
