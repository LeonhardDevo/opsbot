<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Opsbot\TelegramBotService;
use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Support\Flash;
use App\Telegram\TelegramLinker;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class IntegrationsController extends Controller
{
    public function show(Request $request, CurrentWorkspace $current, TelegramBotService $bots): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        $delivery = $bots->deliveryBot($workspace);
        $account = $delivery !== null
            ? TelegramAccount::query()->where('user_id', $user->id)->where('bot_key', $delivery['key'])->first()
            : null;

        return Inertia::render('settings/Integrations', [
            'telegram' => [
                'linked' => $account !== null,
                'username' => $account?->username,
                'linked_at' => $account?->linked_at->toIso8601String(),
                'bot_username' => $delivery['username'] ?? null,
            ],
            'botUsername' => $delivery['username'] ?? null,
        ]);
    }

    public function link(Request $request, TelegramLinker $linker, CurrentWorkspace $current, TelegramBotService $bots): JsonResponse
    {
        $this->authorize('integrations.manage');
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        if ($bots->deliveryBot($workspace) === null) {
            return response()->json(['message' => __('notifications.telegram.not_configured')], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $link = $linker->issueLink($user, $workspace);

        return response()->json([
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601String(),
            'bot_username' => $link['bot_username'],
        ]);
    }

    public function unlink(Request $request, TelegramLinker $linker, CurrentWorkspace $current): RedirectResponse
    {
        $this->authorize('integrations.manage');

        /** @var User $user */
        $user = $request->user();

        if ($linker->unlink($user, $current->get())) {
            Flash::success('notifications.telegram.unlinked');
        }

        return back();
    }
}
