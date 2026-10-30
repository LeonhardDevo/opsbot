<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Models\OutboxMessage;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

final class Outbox
{
    public const string CHANNEL = 'outbox';

    private const array SECRET_KEYS = ['token', 'bot_token', 'api_key', 'secret', 'webhook_secret', 'password'];

    private const string BOT_TOKEN_PATTERN = '/^\d{5,16}:[A-Za-z0-9_-]{30,}$/';

    /** @param  array<string, mixed>  $payload */
    public function publish(
        string $topic,
        array $payload,
        ?int $workspaceId = null,
        ?CarbonInterface $availableAt = null,
    ): OutboxMessage {
        self::assertNoSecrets($payload);

        $message = new OutboxMessage;
        $message->topic = $topic;
        $message->payload = $payload;
        $message->workspace_id = $workspaceId;

        if ($availableAt !== null) {
            $message->available_at = $availableAt;
        }

        $message->save();

        $this->notifyAfterCommit($message->getConnection());

        return $message;
    }

    /** @param  array<array-key, mixed>  $data */
    public static function assertNoSecrets(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $location = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                throw new SecretInPayload($location);
            }

            if (is_string($value) && preg_match(self::BOT_TOKEN_PATTERN, $value) === 1) {
                throw new SecretInPayload($location);
            }

            if (is_array($value)) {
                self::assertNoSecrets($value, $location);
            }
        }
    }

    private function notifyAfterCommit(ConnectionInterface $connection): void
    {
        $notify = static fn (): bool => $connection->statement('NOTIFY '.self::CHANNEL);

        if ($connection instanceof Connection) {
            $connection->afterCommit($notify);

            return;
        }

        $notify();
    }
}
