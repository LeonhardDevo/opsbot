<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Billing\PlanLimits;
use App\Domain\Opsbot\OnboardingService;
use App\Domain\Opsbot\Presenters\BotPresenter;
use App\Domain\Opsbot\StarterPacks;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Models\TelegramBot;
use App\Models\WorkspaceMembership;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingSetupController extends OpsController
{
    public function show(OnboardingService $onboarding, StarterPacks $packs, PlanLimits $limits): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'workspace.manage');
        $workspace = $actor->workspace;

        return Inertia::render('onboarding/Setup', [
            'steps' => $onboarding->steps($workspace),
            'bot' => BotPresenter::present(TelegramBot::query()->with('connector')->where('workspace_id', $workspace->id)->first()),
            'starterPacks' => $packs->all($workspace, App::getLocale()),
            'departments' => $this->departmentRefs($workspace),
            'employeesCount' => WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('kind', MemberKind::TelegramOnly->value)
                ->where('status', '!=', MemberStatus::Offboarded->value)
                ->count(),
            'limits' => $limits->usageFor($workspace, ['seats', 'documents', 'storage_mb', 'ai_queries_monthly']),
            'driver' => config('opsbot.telegram.driver') === 'fake' ? 'fake' : 'auto',
        ]);
    }

    public function skip(Request $request, OnboardingService $onboarding): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'workspace.manage');

        $data = $request->validate(['step' => ['required', 'string', Rule::in(['bot', 'knowledge', 'team'])]]);
        $onboarding->skip($actor->workspace, $actor->user, (string) $data['step']);

        return back();
    }

    public function finish(OnboardingService $onboarding): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'workspace.manage');

        $onboarding->finish($actor->workspace, $actor->user);
        Flash::success('flash.onboarding.finished');

        return redirect()->route('dashboard');
    }
}
