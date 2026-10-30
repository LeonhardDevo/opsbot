<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

final class PlanLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $limitKey, public readonly int $limit)
    {
        parent::__construct("Plan limit [{$limitKey}] of {$limit} reached.");
    }

    public function errorKey(): string
    {
        return 'errors.limits.'.$this->limitKey;
    }

    public function userMessage(): string
    {
        return (string) __($this->errorKey(), ['count' => $this->limit]);
    }
}
