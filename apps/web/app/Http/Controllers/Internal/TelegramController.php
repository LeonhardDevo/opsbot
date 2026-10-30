<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Audit\Auditor;
use App\Domain\Opsbot\EmployeeLinkService;
use App\Domain\Opsbot\EmployeeNotice;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Telegram\InternalActors;
use App\Domain\Opsbot\Telegram\TelegramActionHandler;
use App\Domain\Opsbot\Telegram\TelegramContextBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\TelegramActionRequest;
use App\Http\Requests\Internal\TelegramLinkRequest;
use App\Locale\Locales;
use App\Locale\WithLocale;
use App\Models\EmployeeLink;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Telegram\TelegramLinker;
use App\Telegram\TelegramLinkException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TelegramController extends Controller
{
    public function __construct(private readonly TelegramContextBuilder $contexts) {}

    public function link(TelegramLinkRequest $request, TelegramLinker $linker, EmployeeLinkService $links, EmployeeNotice $notice): JsonResponse
    {
        $botKey = (string) $request->validated('bot_key');
        $token = (string) $request->validated('token');
        $identity = [
            'telegram_user_id' => (int) $request->validated('telegram_user_id'),
            'chat_id' => (int) $request->validated('chat_id'),
            'username' => $request->validated('username'),
            'language_code' => $request->validated('language_code'),
        ];

        try {
            if (str_starts_with($token, EmployeeLink::PREFIX)) {
                $result = $links->redeem($botKey, substr($token, strlen(EmployeeLink::PREFIX)), $identity);
                $account = $result['account'];
            } else {
                $account = $linker->link($botKey, $token, $identity);
            }
        } catch (TelegramLinkException $exception) {
            return response()->json(['error' => $exception->error()], $exception->status());
        }

        $user = $account->user;
        $workspace = $account->workspace;

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->displayName(), 'locale' => in_array($user->locale, ['ru', 'en'], true) ? $user->locale : null],
            'workspace' => $workspace !== null ? ['id' => $workspace->id, 'name' => $workspace->name] : null,
            'notice_required' => $workspace !== null && ! $notice->acknowledged($user, $workspace->id),
        ]);
    }

    public function context(Request $request): JsonResponse
    {
        $botKey = $request->query('bot_key');
        $telegramUserId = $request->query('telegram_user_id');
        $workspaceId = $request->query('workspace_id');
        $userId = $request->query('user_id');

        if (is_string($botKey) && is_numeric($telegramUserId)) {
            if (! TelegramBot::isValidKey($botKey)) {
                return $this->invalid('bot_key');
            }

            $account = $this->contexts->account($botKey, (int) $telegramUserId);
        } elseif (is_numeric($workspaceId) && is_numeric($userId)) {
            $account = $this->contexts->accountForWorkspaceUser((int) $workspaceId, (int) $userId);
        } else {
            return $this->invalid('telegram_user_id');
        }

        $context = $account !== null ? $this->contexts->build($account) : null;

        if ($context === null) {
            return response()->json(['error' => 'not_linked'], 404);
        }

        return response()->json($context);
    }

    public function unlink(Request $request, TelegramLinker $linker): Response
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string', 'regex:'.TelegramBot::KEY_PATTERN],
            'telegram_user_id' => ['required', 'integer', 'min:1'],
        ]);

        $account = $this->contexts->account((string) $data['bot_key'], (int) $data['telegram_user_id']);

        if ($account !== null) {
            $linker->unlinkAccount($account, $account->user, Auditor::SOURCE_BOT);
        }

        return response()->noContent();
    }

    public function noticeAck(Request $request, EmployeeNotice $notice): Response|JsonResponse
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string', 'regex:'.TelegramBot::KEY_PATTERN],
            'telegram_user_id' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'string', 'max:20'],
        ]);

        $account = $this->contexts->account((string) $data['bot_key'], (int) $data['telegram_user_id']);
        $resolved = $account !== null ? $this->contexts->resolve($account) : null;

        if ($account === null || $resolved === null) {
            return response()->json(['error' => 'not_linked'], 404);
        }

        try {
            $notice->acknowledge($account->user, $resolved['workspace']->id, (string) $data['version'], $account, $account->language_code !== null ? substr($account->language_code, 0, 2) : null);
        } catch (OpsbotException) {
            return response()->json(['error' => 'version_mismatch'], 409);
        }

        return response()->noContent();
    }

    public function chatBlocked(Request $request): Response
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string', 'regex:'.TelegramBot::KEY_PATTERN],
            'telegram_user_id' => ['required', 'integer', 'min:1'],
            'blocked' => ['required', 'boolean'],
        ]);

        TelegramAccount::query()
            ->where('bot_key', (string) $data['bot_key'])
            ->where('telegram_user_id', (int) $data['telegram_user_id'])
            ->update(['blocked_at' => (bool) $data['blocked'] ? now() : null]);

        return response()->noContent();
    }

    public function action(TelegramActionRequest $request, InternalActors $actors, TelegramActionHandler $handler): JsonResponse
    {
        $type = (string) $request->validated('type');

        if (! TelegramActionHandler::supports($type)) {
            return response()->json(['ok' => false, 'error' => 'unknown_action'], 404);
        }

        try {
            ['actor' => $actor] = $actors->fromTelegram($request->validated('bot_key'), $request->validated('telegram_user_id'));
        } catch (OpsbotException $exception) {
            $locale = $this->locale($request->validated('locale'));

            return response()->json([
                'ok' => false,
                'error' => $exception->key,
                'message' => $exception->userMessage($locale),
                'alert' => true,
            ], $exception->status);
        }

        if ($request->validated('user_id') !== null && (int) $request->validated('user_id') !== $actor->id()) {
            return response()->json(['ok' => false, 'error' => 'user_mismatch'], 403);
        }

        $payload = $request->validated('payload');
        $outcome = WithLocale::run($actor->locale(), fn () => $handler->handle($actor, $type, is_array($payload) ? $payload : []));

        return response()->json($outcome->toTelegram());
    }

    private function invalid(string $field): JsonResponse
    {
        return response()->json(['error' => 'validation_failed', 'errors' => [
            $field => ['bot_key and telegram_user_id, or workspace_id and user_id are required.'],
        ]], 422);
    }

    private function locale(mixed $requested): string
    {
        $locale = is_string($requested) ? substr($requested, 0, 2) : null;

        return Locales::isSupported($locale) ? (string) $locale : Locales::fallback();
    }
}
