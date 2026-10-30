<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BotErrorCode;
use App\Enums\BotStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $tg_bot_id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $token
 * @property string|null $webhook_secret
 * @property BotStatus $status
 * @property string|null $mode
 * @property bool|null $can_join_groups
 * @property BotErrorCode|null $last_error
 * @property CarbonInterface|null $last_error_at
 * @property CarbonInterface|null $last_seen_at
 * @property int|null $connected_by
 * @property CarbonInterface|null $connected_at
 * @property CarbonInterface|null $disconnected_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $connector
 */
class TelegramBot extends Model
{
    public const string PLATFORM_KEY = 'platform';

    public const string KEY_PATTERN = '/^(platform|[1-9][0-9]{0,18})$/';

    protected $fillable = [
        'workspace_id', 'tg_bot_id', 'username', 'first_name', 'token', 'webhook_secret', 'status', 'mode',
        'can_join_groups', 'last_error', 'last_error_at', 'last_seen_at', 'connected_by', 'connected_at',
        'disconnected_at',
    ];

    protected $hidden = ['token', 'webhook_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tg_bot_id' => 'integer',
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'status' => BotStatus::class,
            'last_error' => BotErrorCode::class,
            'can_join_groups' => 'boolean',
            'last_error_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** @return HasMany<TelegramAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    public function key(): string
    {
        return (string) $this->id;
    }

    public function isActive(): bool
    {
        return $this->status === BotStatus::Active;
    }

    public function tokenHint(): ?string
    {
        $token = $this->token;

        if (! is_string($token) || $token === '') {
            return null;
        }

        return '…'.substr($token, -4);
    }

    public static function botIdFromToken(string $token): int
    {
        return (int) explode(':', $token, 2)[0];
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }
}
