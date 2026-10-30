<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Presenters\BotPresenter;
use App\Domain\Opsbot\TelegramBotService;
use App\Http\Requests\Opsbot\BotTokenRequest;
use App\Locale\WithLocale;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class BotController extends OpsController
{
    public const array COMMANDS = ['start', 'help', 'lang', 'whoami', 'history', 'tasks', 'incident', 'ask_manager', 'queue', 'privacy', 'unlink'];

    public function index(): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'bot.manage');

        $bot = TelegramBot::query()->with('connector')->where('workspace_id', $actor->workspaceId())->first();

        return Inertia::render('bot/Index', [
            'bot' => BotPresenter::present($bot),
            'platformBotUsername' => TelegramBotService::platformUsername(),
            'commands' => array_map(fn (string $command): array => [
                'command' => '/'.$command,
                'description' => (string) __('bot.commands.'.$command),
            ], self::COMMANDS),
            'telegramMode' => in_array(config('opsbot.telegram.mode'), ['webhook', 'polling', 'disabled'], true) ? config('opsbot.telegram.mode') : 'webhook',
            'driver' => config('opsbot.telegram.driver') === 'fake' ? 'fake' : 'auto',
        ]);
    }

    public function store(BotTokenRequest $request, TelegramBotService $bots): RedirectResponse
    {
        return $this->connect($request, $bots);
    }

    public function updateToken(BotTokenRequest $request, TelegramBotService $bots): RedirectResponse
    {
        return $this->connect($request, $bots);
    }

    public function destroy(TelegramBotService $bots): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'bot.manage');

        $bots->disconnect($actor->workspace, $actor->user);
        Flash::success('flash.bot.disconnect_requested');

        return back();
    }

    public function testMessage(TelegramBotService $bots, Outbox $outbox): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'bot.manage');

        $bot = $bots->activeBot($actor->workspace);

        if ($bot === null) {
            throw new OpsbotException('bot.not_connected');
        }

        $linked = TelegramAccount::query()->where('bot_key', $bot->key())->where('user_id', $actor->id())->exists();

        if (! $linked) {
            throw new OpsbotException('bot.actor_not_linked');
        }

        $text = WithLocale::run($actor->locale(), fn (): string => (string) __('bot.test_message_html', ['workspace' => e($actor->workspace->name)]));

        $outbox->publish(Topics::TELEGRAM_NOTIFY, [
            'workspace_id' => $actor->workspaceId(),
            'bot_key' => $bot->key(),
            'user_id' => $actor->id(),
            'locale' => $actor->locale(),
            'template' => 'opsbot.test_message',
            'text_html' => $text,
            'buttons' => [],
            'reference' => ['type' => 'opsbot.test_message', 'id' => (string) Str::uuid()],
        ], $actor->workspaceId());

        Flash::success('flash.bot.test_message_sent');

        return back();
    }

    private function connect(BotTokenRequest $request, TelegramBotService $bots): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'bot.manage');

        $result = $bots->connect($actor->workspace, $actor->user, (string) $request->validated('token'));

        if ($result->ok) {
            Flash::success('flash.bot.connected');
        } else {
            Flash::error('errors.bot.'.($result->error->value ?? 'network'));
        }

        return back();
    }
}
