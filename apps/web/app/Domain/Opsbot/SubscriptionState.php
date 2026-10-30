<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Enums\SubscriptionState as State;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SubscriptionState
{
    public function for(Workspace $workspace, ?CarbonInterface $now = null): State
    {
        return self::of($workspace->subscription, $now);
    }

    public static function of(?Subscription $subscription, ?CarbonInterface $now = null): State
    {
        if ($subscription === null || $subscription->status !== SubscriptionStatus::PastDue) {
            return State::Ok;
        }

        $pausedAt = self::pausedAt($subscription);

        if ($pausedAt === null) {
            return State::Grace;
        }

        $now ??= CarbonImmutable::now();

        return $now->greaterThan($pausedAt) ? State::Paused : State::Grace;
    }

    public static function pausedAt(?Subscription $subscription): ?CarbonImmutable
    {
        $since = $subscription->past_due_since ?? $subscription?->payment_failed_at;

        if ($since === null) {
            return null;
        }

        return CarbonImmutable::instance($since)->addDays((int) config('billing.grace_days', 7));
    }
}
