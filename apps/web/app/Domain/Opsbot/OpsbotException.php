<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use RuntimeException;

final class OpsbotException extends RuntimeException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(
        public readonly string $key,
        public readonly array $replace = [],
        public readonly int $status = 422,
    ) {
        parent::__construct("OpsBot rule violated: {$key}");
    }

    /** @param  array<string, mixed>  $replace */
    public static function forbidden(string $key = 'forbidden', array $replace = []): self
    {
        return new self($key, $replace, 403);
    }

    /** @param  array<string, mixed>  $replace */
    public static function notFound(string $key = 'not_found', array $replace = []): self
    {
        return new self($key, $replace, 404);
    }

    public function errorKey(): string
    {
        return 'errors.'.$this->key;
    }

    public function userMessage(?string $locale = null): string
    {
        return (string) __($this->errorKey(), $this->replace, $locale);
    }
}
