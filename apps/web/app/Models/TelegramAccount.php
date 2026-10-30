<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $workspace_id
 * @property string $bot_key
 * @property int|null $telegram_bot_id
 * @property int $telegram_user_id
 * @property int $chat_id
 * @property string|null $username
 * @property string|null $language_code
 * @property CarbonInterface $linked_at
 * @property CarbonInterface|null $blocked_at
 * @property string|null $notice_version
 * @property CarbonInterface|null $notice_acked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Workspace|null $workspace
 * @property-read TelegramBot|null $bot
 */
class TelegramAccount extends Model
{
    protected $fillable = [
        'user_id', 'workspace_id', 'bot_key', 'telegram_bot_id', 'telegram_user_id', 'chat_id', 'username',
        'language_code', 'linked_at', 'blocked_at', 'notice_version', 'notice_acked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'chat_id' => 'integer',
            'telegram_bot_id' => 'integer',
            'linked_at' => 'datetime',
            'blocked_at' => 'datetime',
            'notice_acked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<TelegramBot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function isPlatform(): bool
    {
        return $this->bot_key === TelegramBot::PLATFORM_KEY;
    }
}
