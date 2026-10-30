<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Audit\Auditor;
use App\Billing\Markets\MarketResolver;
use App\Enums\BotStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Knowledge\DocumentService;
use App\Models\Document;
use App\Models\OpsSettings;
use App\Models\OutboxMessage;
use App\Models\Subscription;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class WorkspaceService
{
    public function __construct(
        private Auditor $auditor,
        private CurrentWorkspace $currentWorkspace,
        private Outbox $outbox,
        private MarketResolver $markets,
    ) {}

    /** @param  array{name: string, timezone: string, currency?: string|null, country?: string|null, locale?: string|null}  $data */
    public function create(User $owner, array $data): Workspace
    {
        $country = isset($data['country']) && $data['country'] !== '' ? strtoupper((string) $data['country']) : null;
        $currency = $country !== null
            ? $this->markets->forCountry($country)->currency
            : strtoupper((string) ($data['currency'] ?? 'USD'));
        $gateway = $this->markets->forCountry($country, $currency)->gateway;

        $workspace = DB::transaction(function () use ($owner, $data, $country, $currency, $gateway): Workspace {
            $firstWorkspace = ! WorkspaceMembership::query()->where('user_id', $owner->id)->where('role', WorkspaceRole::Owner->value)->exists();

            $workspace = Workspace::query()->create([
                'name' => $data['name'],
                'slug' => self::uniqueSlug($data['name']),
                'timezone' => $data['timezone'],
                'currency' => $currency,
                'country' => $country,
                'locale' => $data['locale'] ?? $owner->locale,
                'created_by' => $owner->id,
            ]);

            $workspace->addMember($owner, WorkspaceRole::Owner);
            OpsSettings::query()->create(['workspace_id' => $workspace->id]);

            if ($firstWorkspace) {
                Subscription::query()->create([
                    'workspace_id' => $workspace->id,
                    'gateway' => $gateway,
                    'currency' => $currency,
                    'plan' => (string) config('billing.trial.plan', 'pro'),
                    'status' => SubscriptionStatus::Trialing,
                    'interval' => 'month',
                    'trial_ends_at' => now()->addDays((int) config('billing.trial.days', 14)),
                    'current_period_end' => now()->addDays((int) config('billing.trial.days', 14)),
                ]);
            }

            $this->auditor->record('workspace.created', $workspace, actor: $owner, workspace: $workspace, subjectLabel: $workspace->name);

            return $workspace;
        });

        $this->currentWorkspace->switchTo($owner, $workspace);

        return $workspace;
    }

    /** @param  array{name?: string, timezone?: string, currency?: string, country?: string|null, locale?: string}  $data */
    public function update(Workspace $workspace, User $actor, array $data): Workspace
    {
        $fields = ['name', 'timezone', 'currency', 'country', 'locale'];

        if (isset($data['country']) && $data['country'] !== '') {
            $data['country'] = strtoupper((string) $data['country']);
            $data['currency'] = $this->markets->forCountry($data['country'])->currency;
        }
        $before = $workspace->only($fields);

        if (array_key_exists('country', $data) && $data['country'] === null) {
            unset($data['country']);
        }

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $workspace->fill(array_intersect_key($data, array_flip($fields)));

        if ($workspace->isDirty()) {
            DB::transaction(function () use ($workspace, $actor, $before, $fields): void {
                $workspace->save();
                $this->auditor->record(
                    'workspace.updated',
                    $workspace,
                    Auditor::diff($before, $workspace->only($fields)),
                    actor: $actor,
                    subjectLabel: $workspace->name,
                );
            });
        }

        return $workspace;
    }

    public function delete(Workspace $workspace, ?User $actor): void
    {
        $disks = Document::query()->forWorkspace($workspace)->distinct()->pluck('disk')->all();

        DB::transaction(function () use ($workspace, $actor): void {
            $this->auditor->record('workspace.deleted', $workspace, actor: $actor, subjectLabel: $workspace->name);

            OutboxMessage::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('dispatched_at')
                ->delete();

            $this->outbox->publish(Topics::WORKSPACE_PURGE, ['workspace_id' => $workspace->id], $workspace->id);

            $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->first();

            if ($bot !== null) {
                $bot->forceFill(['token' => null, 'webhook_secret' => null, 'status' => BotStatus::Disconnected])->save();
                $this->outbox->publish(Topics::TELEGRAM_BOT_DISCONNECT, [
                    'bot_key' => $bot->key(),
                    'telegram_bot_id' => $bot->id,
                    'workspace_id' => $workspace->id,
                ], $workspace->id);
            }

            TelegramAccount::query()->where('workspace_id', $workspace->id)->delete();
            $workspace->delete();
        });

        foreach (array_unique([DocumentService::disk(), ...$disks]) as $disk) {
            Storage::disk((string) $disk)->deleteDirectory((string) $workspace->id);
        }

        $this->currentWorkspace->forget();
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug(Str::limit($name, 60, '')) ?: 'workspace';
        $slug = $base;

        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
