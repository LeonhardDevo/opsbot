<?php

declare(strict_types=1);

namespace App\Outbox;

use LogicException;

final class SecretInPayload extends LogicException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("Outbox payloads must not carry secrets (found at [{$path}]).");
    }
}
