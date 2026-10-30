<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\AnalyticsQueries;
use App\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AssistantSettingsController extends OpsController
{
    public function edit(AnalyticsQueries $analytics): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'settings.assistant');
        $workspace = $actor->workspace;

        $kpis = $analytics->kpis($workspace->id, AnalyticsQueries::window($workspace, 30), null);

        return Inertia::render('settings/Assistant', [
            'settings' => $workspace->settings()->toContract(),
            'refusalRate30d' => $kpis['refused_pct'],
        ]);
    }

    public function update(Request $request, Auditor $auditor): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'settings.assistant');

        $data = $request->validate([
            'min_confidence' => ['required', 'numeric', 'between:0.2,0.8'],
            'quiet_hours_start' => ['required', 'string', 'date_format:H:i'],
            'quiet_hours_end' => ['required', 'string', 'date_format:H:i'],
            'reminders_enabled' => ['required', 'boolean'],
        ]);

        $settings = $actor->workspace->settings();
        $before = $settings->toContract();

        $settings->fill([
            'min_confidence' => round((float) $data['min_confidence'], 2),
            'quiet_hours_start' => (string) $data['quiet_hours_start'],
            'quiet_hours_end' => (string) $data['quiet_hours_end'],
            'reminders_enabled' => (bool) $data['reminders_enabled'],
        ])->save();

        $changes = Auditor::diff($before, $settings->refresh()->toContract());

        if ($changes !== []) {
            $auditor->record('ops_settings.updated', $actor->workspace, $changes, actor: $actor->user, workspace: $actor->workspace, subjectLabel: $actor->workspace->name);
        }

        Flash::success('flash.settings.assistant_saved');

        return back();
    }
}
