<?php

declare(strict_types=1);

namespace App\Billing;

use App\Domain\Opsbot\SeatCounter;
use App\Enums\ApprovalStatus;
use App\Enums\BotStatus;
use App\Enums\LimitKey;
use App\Enums\RefusalReason;
use App\Models\AiQuery;
use App\Models\Document;
use App\Models\TelegramBot;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

final readonly class PlanLimits
{
    public function __construct(
        private PlanCatalog $catalog,
        private SeatCounter $seats,
    ) {}

    public function limit(Workspace $workspace, string $key): ?int
    {
        return $this->catalog->limit($workspace->planKey(), $key);
    }

    public function used(Workspace $workspace, string $key): int
    {
        return match (LimitKey::tryFrom($key)) {
            LimitKey::Seats => $this->seats->used($workspace->id),
            LimitKey::Documents => Document::query()
                ->forWorkspace($workspace)
                ->where('approval_status', '!=', ApprovalStatus::Archived->value)
                ->count(),
            LimitKey::StorageMb => (int) ceil(((int) Document::query()->forWorkspace($workspace)->sum('size_bytes')) / 1048576),
            LimitKey::AiQueriesMonthly => $this->aiQueriesThisMonth($workspace),
            LimitKey::MaxPagesPerDocument => (int) Document::query()
                ->forWorkspace($workspace)
                ->where('approval_status', '!=', ApprovalStatus::Archived->value)
                ->max('pages'),
            LimitKey::MaxFileMb => (int) ceil(((int) Document::query()
                ->forWorkspace($workspace)
                ->where('approval_status', '!=', ApprovalStatus::Archived->value)
                ->max('size_bytes')) / 1048576),
            LimitKey::Bots => TelegramBot::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', '!=', BotStatus::Disconnected->value)
                ->count(),
            null => throw new \InvalidArgumentException("No usage resolver for plan limit [{$key}]."),
        };
    }

    public function allows(Workspace $workspace, string $key, int $adding = 1): bool
    {
        $limit = $this->limit($workspace, $key);

        return $limit === null || $this->used($workspace, $key) + $adding <= $limit;
    }

    /** @throws PlanLimitExceeded */
    public function ensure(Workspace $workspace, string $key, int $adding = 1): void
    {
        if (! $this->allows($workspace, $key, $adding)) {
            throw new PlanLimitExceeded($key, (int) $this->limit($workspace, $key));
        }
    }

    /** @throws PlanLimitExceeded */
    public function ensureWithin(Workspace $workspace, string $key, int $value): void
    {
        $limit = $this->limit($workspace, $key);

        if ($limit !== null && $value > $limit) {
            throw new PlanLimitExceeded($key, $limit);
        }
    }

    public function exhausted(Workspace $workspace, string $key): bool
    {
        $limit = $this->limit($workspace, $key);

        return $limit !== null && $this->used($workspace, $key) >= $limit;
    }

    /** @return array<string, array{used: int, limit: int|null}> */
    public function usage(Workspace $workspace): array
    {
        $usage = [];

        foreach (LimitKey::cases() as $key) {
            $usage[$key->value] = ['used' => $this->used($workspace, $key->value), 'limit' => $this->limit($workspace, $key->value)];
        }

        return $usage;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{used: int, limit: int|null}>
     */
    public function usageFor(Workspace $workspace, array $keys): array
    {
        $usage = [];

        foreach ($keys as $key) {
            $usage[$key] = ['used' => $this->used($workspace, $key), 'limit' => $this->limit($workspace, $key)];
        }

        return $usage;
    }

    public static function monthStart(Workspace $workspace): CarbonImmutable
    {
        return CarbonImmutable::now($workspace->zone())->startOfMonth();
    }

    private function aiQueriesThisMonth(Workspace $workspace): int
    {
        return AiQuery::query()
            ->forWorkspace($workspace)
            ->where('created_at', '>=', self::monthStart($workspace)->utc())
            ->where(fn ($query) => $query->whereNull('refusal_reason')->orWhere('refusal_reason', '!=', RefusalReason::LimitReached->value))
            ->count();
    }
}
