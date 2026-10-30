<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string $locale
 * @property CarbonInterface|null $erased_at
 * @property bool $is_platform_admin
 * @property int|null $current_workspace_id
 * @property CarbonInterface|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read WorkspaceMembership|null $membership
 * @property-read TelegramAccount|null $telegramAccount
 * @property-read Collection<int, TelegramAccount> $telegramAccounts
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => 'en',
        'is_platform_admin' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'current_workspace_id' => 'integer',
            'erased_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Workspace, $this, WorkspaceMembership, 'membership'> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'kind', 'status'])
            ->wherePivot('status', '!=', MemberStatus::Offboarded->value)
            ->withTimestamps();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /** @return HasOne<TelegramAccount, $this> */
    public function telegramAccount(): HasOne
    {
        return $this->hasOne(TelegramAccount::class)->oldestOfMany();
    }

    /** @return HasMany<TelegramAccount, $this> */
    public function telegramAccounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    public function telegramAccountFor(string $botKey): ?TelegramAccount
    {
        return $this->telegramAccounts()->where('bot_key', $botKey)->first();
    }

    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    public function displayName(): string
    {
        if ($this->erased_at !== null) {
            return (string) __('employees.former_employee', ['id' => $this->id]);
        }

        return $this->name;
    }

    /** @return array{id: int, name: string} */
    public function toRef(): array
    {
        return ['id' => $this->id, 'name' => $this->displayName()];
    }

    public function belongsToWorkspace(Workspace|int $workspace): bool
    {
        return $this->roleIn($workspace) !== null;
    }

    public function roleIn(Workspace|int $workspace): ?WorkspaceRole
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        $role = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $this->id)
            ->where('status', '!=', MemberStatus::Offboarded->value)
            ->toBase()
            ->value('role');

        return is_string($role) ? WorkspaceRole::tryFrom($role) : null;
    }

    public function membershipIn(Workspace|int $workspace): ?WorkspaceMembership
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $this->id)
            ->first();
    }

    public function preferredLocale(): string
    {
        return in_array($this->locale, ['en', 'ru'], true) ? $this->locale : 'en';
    }
}
