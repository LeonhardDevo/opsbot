<?php

declare(strict_types=1);

use App\Domain\Opsbot\Support\SignedLinks;
use App\Enums\ArchiveReason;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('documents');
    Http::fake(['ai.test/v1/documents/*/chunks/*' => Http::response([
        'before' => ['chunk_id' => 11, 'content' => 'Before text.'],
        'chunk' => ['chunk_id' => 12, 'page' => 2, 'section_path' => ['Damage', 'Steps'], 'content' => 'Take three photos.'],
        'after' => null,
    ])]);
});

it('opens a signed link with the highlighted excerpt and audits once per ten minutes', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'ru']);
    $department = $this->department($workspace, 'residential');
    $this->inDepartment($employee, $department);
    $document = Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'title' => 'Damage', 'version' => '1.0', 'department_ids' => [$department->id]]);

    $url = SignedLinks::document($document->id, $employee->id, 12, 2);

    $response = $this->get($url)->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn ($page) => $page
            ->component('share/DocumentViewer', false)
            ->where('document', ['id' => $document->id, 'title' => 'Damage', 'version' => '1.0', 'effective_date' => '2026-01-01', 'departments' => ['Residential']])
            ->where('excerpt', ['chunk_id' => 12, 'page' => 2, 'section' => 'Damage › Steps', 'before' => 'Before text.', 'content' => 'Take three photos.', 'after' => null])
            ->where('state', 'ok')
            ->where('successor', null)
            ->where('locale', 'ru')
            ->where('workspaceName', $workspace->name));

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $this->get($url)->assertOk();
    $log = AuditLog::query()->where('action', 'document.viewed_via_bot')->sole();
    expect($log->actor_id)->toBe($employee->id)
        ->and($log->metadata)->toMatchArray(['chunk_id' => 12, 'page' => 2, 'version' => '1.0', 'source' => 'bot']);

    $file = $response->viewData('page')['props']['downloadUrl'];
    Storage::disk('documents')->put($document->path, '%PDF-1.4 doc');
    $this->get($file)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('shows a localized expired page after thirty minutes', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $document = Document::factory()->approved()->create(['workspace_id' => $workspace->id]);
    $url = SignedLinks::document($document->id, $employee->id);

    $this->travel(31)->minutes();

    $this->get($url)->assertForbidden()->assertInertia(fn ($page) => $page->component('share/LinkError', false)->where('reason', 'expired'));
});

it('forbids tampered links, other users and offboarded employees', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $restricted = $this->department($workspace, 'restricted');
    $document = Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'department_ids' => [$restricted->id]]);
    $open = Document::factory()->approved()->create(['workspace_id' => $workspace->id]);

    $this->get(SignedLinks::document($document->id, $employee->id))->assertForbidden()->assertInertia(fn ($page) => $page->where('reason', 'forbidden'));
    $this->get(str_replace('u='.$employee->id, 'u=999', SignedLinks::document($open->id, $employee->id)))->assertForbidden();

    WorkspaceMembership::query()->where('user_id', $employee->id)->update(['status' => MemberStatus::Offboarded->value]);
    $this->get(SignedLinks::document($open->id, $employee->id))->assertForbidden();

    $this->get(SignedLinks::document(999999, $employee->id))->assertNotFound()->assertInertia(fn ($page) => $page->where('reason', 'not_found'));
});

it('treats drafts and future-dated documents as not found', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $draft = Document::factory()->indexed()->create(['workspace_id' => $workspace->id]);
    $future = Document::factory()->approved('2099-01-01')->create(['workspace_id' => $workspace->id]);

    $this->get(SignedLinks::document($draft->id, $employee->id))->assertNotFound();
    $this->get(SignedLinks::document($future->id, $employee->id))->assertNotFound();
});

it('points archived documents to their visible successor', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $old = Document::factory()->archived()->create(['workspace_id' => $workspace->id, 'title' => 'Old', 'archive_reason' => ArchiveReason::Superseded, 'effective_date' => '2025-01-01']);
    Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'title' => 'New', 'supersedes_document_id' => $old->id]);

    $response = $this->get(SignedLinks::document($old->id, $employee->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'archived')->where('successor.title', 'New')->where('excerpt', null));

    expect($response->viewData('page')['props']['successor']['url'])->toContain('signature=')
        ->and(AuditLog::query()->where('action', 'document.viewed_via_bot')->count())->toBe(0);
});
