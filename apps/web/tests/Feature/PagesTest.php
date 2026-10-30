<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\User;

it('renders the marketing pages', function (): void {
    $this->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Landing', false)->where('canRegister', true)->has('plans', 4)->where('plans.0.key', 'free'));
    $this->get(route('security'))->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Security', false));
    $this->get(route('legal', 'terms'))->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Legal', false)->where('doc.key', 'terms'));
    $this->get(route('legal', 'privacy'))->assertInertia(fn ($page) => $page->where('doc.key', 'privacy'));
    $this->get('/legal/no-such-document')->assertNotFound();
    $this->get('/legal/Not_A_Key')->assertNotFound();
});

it('redirects guests from the app to login', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/dashboard', '/knowledge', '/assistant', '/audit', '/settings/members', '/admin/ai', '/onboarding/workspace']);

it('requires a verified email for the app', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

it('renders the dashboard for a new workspace', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Document::factory()->count(3)->create(['workspace_id' => $workspace->id, 'size_bytes' => 1048576]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->where('today', [])
            ->where('kpis', [
                'questions_7d' => ['value' => 0, 'delta' => null],
                'answered_rate_7d' => null,
                'open_incidents' => 0,
                'overdue_tasks' => 0,
                'unanswered_open' => 0,
            ])
            ->has('questionsSeries', 14)
            ->where('recentIncidents', [])
            ->where('myTasks', [])
            ->where('unanswered', [])
            ->where('bot', null)
            ->where('reviewDue', ['count' => 0, 'top' => []])
            ->has('onboarding.steps', 4)
            ->where('limitsWarning', []));
});

it('redirects onboarding to the dashboard for users who already have a workspace', function (): void {
    $this->signInMember();

    $this->get(route('onboarding.workspace'))->assertRedirect(route('dashboard'));
    $this->get(route('onboarding.workspace', ['new' => 1]))->assertOk();
});
