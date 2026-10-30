<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Notifications;

use App\Events\DeliveryReported;
use App\Mail\OpsbotNotificationMail;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class EmailFallback
{
    public const array FALLBACK_ERRORS = ['telegram_not_linked', 'chat_blocked'];

    public function handle(DeliveryReported $event): void
    {
        $delivery = $event->delivery;

        if ($delivery->channel !== 'telegram' || $delivery->status !== 'failed') {
            return;
        }

        $topics = (array) config('opsbot.email_fallback_topics', []);

        if (! in_array($delivery->reference_type, $topics, true) || ! in_array((string) $delivery->error, self::FALLBACK_ERRORS, true)) {
            return;
        }

        if (! Str::isUuid($delivery->reference_id)) {
            return;
        }

        $message = OutboxMessage::query()->find($delivery->reference_id);

        if ($message === null || $message->topic !== $delivery->reference_type) {
            return;
        }

        $payload = $message->payload;
        $recipientId = $payload['recipient']['user_id'] ?? null;
        $user = is_numeric($recipientId) ? User::query()->find((int) $recipientId) : null;

        if ($user === null || $user->email === null || $user->erased_at !== null) {
            Log::info('opsbot.email_fallback.unreachable', ['topic' => $message->topic, 'outbox_id' => $message->id]);

            return;
        }

        Mail::to($user->email)
            ->locale($user->preferredLocale())
            ->send(new OpsbotNotificationMail($message->topic, $payload, $message->id, $message->workspace_id));
    }
}
