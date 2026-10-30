<?php

declare(strict_types=1);

use App\Enums\ApprovalStatus;
use App\Enums\ArchiveReason;
use App\Enums\DocumentStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\Document;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('documents');
});

function pdfUpload(string $name = 'policy.pdf'): UploadedFile
{
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, $pdf);
}

function docxUpload(?string $padding = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hi</w:t></w:r></w:p></w:body></w:document>');
    if ($padding !== null) {
        $zip->addFromString('word/media/padding.bin', $padding);
    }
    $zip->close();

    return UploadedFile::fake()->createWithContent('handbook.docx', (string) file_get_contents($path));
}

it('lists documents with filters, counts, limits and upload constraints', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $finance = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Finance', 'code' => 'finance']);
    Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'title' => 'Refund policy', 'department_ids' => [$finance->id]]);
    Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Travel rules']);
    Document::factory()->create(['title' => 'Foreign doc']);

    $this->get(route('knowledge.index', ['q' => 'refund']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('knowledge/Index', false)
            ->has('documents.data', 1)
            ->where('documents.data.0.title', 'Refund policy')
            ->where('documents.data.0.status', 'indexed')
            ->where('documents.data.0.approval_status', 'approved')
            ->where('documents.data.0.departments', [['id' => $finance->id, 'name' => 'Finance', 'code' => 'finance']])
            ->where('documents.meta.total', 1)
            ->where('filters', ['q' => 'refund'])
            ->where('departments', [['id' => $finance->id, 'name' => 'Finance', 'code' => 'finance']])
            ->where('counts', ['draft' => 1, 'approved' => 1, 'archived' => 0, 'review_overdue' => 0])
            ->where('limits.documents', ['used' => 2, 'limit' => 15])
            ->has('starterPacks', 1)
            ->where('starterPacks.0.key', 'cleaning')
            ->where('starterPacks.0.documents_count', 8)
            ->where('accept', ['.pdf', '.docx', '.txt', '.md', '.markdown', '.html', '.htm', '.png', '.jpg', '.jpeg']));

    $this->get(route('knowledge.index', ['approval' => 'draft']))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Travel rules'));

    $this->get(route('knowledge.index', ['department_id' => $finance->id]))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Refund policy'));
});

it('hides documents of other departments from managers and shows overdue reviews', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $mine = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Mine', 'code' => 'mine']);
    $other = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other', 'code' => 'other']);
    DepartmentMember::query()->create(['workspace_id' => $workspace->id, 'department_id' => $mine->id, 'user_id' => $manager->id, 'is_primary' => true]);

    Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Mine', 'department_ids' => [$mine->id]]);
    Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Other', 'department_ids' => [$other->id]]);
    Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Everyone']);
    Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Admins only', 'roles' => ['admin']]);
    $overdue = Document::factory()->approved('2025-01-01')->create(['workspace_id' => $workspace->id, 'title' => 'Old rule', 'review_due_at' => '2025-06-01']);

    $titles = collect($this->get(route('knowledge.index'))->viewData('page')['props']['documents']['data'])->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['Everyone', 'Mine', 'Old rule']);

    $this->get(route('knowledge.index', ['review' => 'overdue']))
        ->assertInertia(fn ($page) => $page->has('documents.data', 1)
            ->where('documents.data.0.id', $overdue->id)
            ->where('documents.data.0.review_overdue', true));
});

it('uploads a draft to the private disk, queues extraction via the outbox and audits', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $finance = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Finance', 'code' => 'finance']);

    $this->post(route('knowledge.store'), [
        'file' => pdfUpload(),
        'title' => 'Expense policy',
        'department_ids' => [$finance->id],
        'roles' => ['admin', 'employee'],
        'tags' => ['policy', 'money'],
        'version' => '2.1',
        'language' => 'en',
    ])->assertRedirect()->assertSessionHas('success');

    $document = Document::query()->sole();

    expect($document->workspace_id)->toBe($workspace->id)
        ->and($document->uploaded_by)->toBe($user->id)
        ->and($document->title)->toBe('Expense policy')
        ->and($document->mime)->toBe('application/pdf')
        ->and($document->status)->toBe(DocumentStatus::Queued)
        ->and($document->approval_status)->toBe(ApprovalStatus::Draft)
        ->and($document->department_ids)->toBe([$finance->id])
        ->and($document->roles)->toBe(['admin', 'employee'])
        ->and($document->upload_rev)->toBe(1)
        ->and($document->path)->toStartWith($workspace->id.'/');

    Storage::disk('documents')->assertExists($document->path);

    $message = OutboxMessage::query()->topic('document.uploaded')->sole();
    expect($message->workspace_id)->toBe($workspace->id)
        ->and($message->payload)->toEqual([
            'document_id' => $document->id,
            'workspace_id' => $workspace->id,
            'upload_rev' => 1,
            'title' => 'Expense policy',
            'mime' => 'application/pdf',
            'size_bytes' => $document->size_bytes,
            'ai_external_allowed' => false,
            'limits' => ['max_pages_per_document' => 30],
            'metadata' => [
                'department_ids' => [$finance->id],
                'roles' => ['admin', 'employee'],
                'tags' => ['policy', 'money'],
                'version' => '2.1',
                'effective_date' => null,
                'approval_status' => 'draft',
                'language' => 'en',
            ],
        ]);

    expect(AuditLog::query()->where('action', 'document.uploaded')->value('subject_label'))->toBe('Expense policy');
});

it('accepts docx, markdown and text, defaulting the title to the file name', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('knowledge.store'), ['file' => docxUpload()])->assertSessionHasNoErrors();
    $this->post(route('knowledge.store'), ['file' => UploadedFile::fake()->createWithContent('Notes.md', "# Заголовок\n\nТекст правил.")])->assertSessionHasNoErrors();
    $this->post(route('knowledge.store'), ['file' => UploadedFile::fake()->createWithContent('faq.txt', 'Plain text FAQ')])->assertSessionHasNoErrors();

    expect(Document::query()->orderBy('id')->get()->map(fn (Document $d): array => [$d->title, $d->mime])->all())->toBe([
        ['handbook', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ['Notes', 'text/markdown'],
        ['faq', 'text/plain'],
    ]);
});

it('rejects disallowed types and content that does not match the extension', function (UploadedFile $file): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('knowledge.store'), ['file' => $file])->assertSessionHasErrors('file');

    expect(Document::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
})->with([
    'executable' => fn () => UploadedFile::fake()->createWithContent('tool.exe', "MZ\x90\x00binary"),
    'php renamed to pdf' => fn () => UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo "pwned";'),
    'zip renamed to docx' => fn () => UploadedFile::fake()->createWithContent('fake.docx', "PK\x03\x04garbage-not-a-zip"),
    'docx zip bomb' => fn () => docxUpload(str_repeat(' ', 30 * 1024 * 1024)),
    'binary renamed to txt' => fn () => UploadedFile::fake()->createWithContent('bin.txt', "abc\x00\x01\x02def"),
    'pdf renamed to png' => fn () => pdfUpload('image.png'),
]);

it('blocks files above the plan size limit and keeps nothing', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('knowledge.store'), ['file' => UploadedFile::fake()->createWithContent('big.txt', str_repeat("Rule line.\n", 1_100_000))])
        ->assertRedirect()
        ->assertSessionHas('limit', 'max_file_mb');

    expect(Document::query()->count())->toBe(0);
});

it('forbids employees from the knowledge pages and uploads', function (): void {
    $this->signInMember(WorkspaceRole::Employee);

    $this->get(route('knowledge.index'))->assertForbidden();
    $this->post(route('knowledge.store'), ['file' => pdfUpload()])->assertForbidden();
});

it('keeps managers within their departments when uploading', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $mine = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Mine', 'code' => 'mine']);
    $other = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other', 'code' => 'other']);
    DepartmentMember::query()->create(['workspace_id' => $workspace->id, 'department_id' => $mine->id, 'user_id' => $manager->id, 'is_primary' => true]);

    $this->post(route('knowledge.store'), ['file' => pdfUpload(), 'department_ids' => [$other->id]])->assertSessionHas('error');
    $this->post(route('knowledge.store'), ['file' => pdfUpload()])->assertSessionHas('error');
    $this->post(route('knowledge.store'), ['file' => pdfUpload(), 'department_ids' => [$mine->id]])->assertSessionHas('success');

    expect(Document::query()->count())->toBe(1);
});

it('shows a document with chunk preview, versions and permissions', function (): void {
    Http::fake(['ai.test/v1/documents/*/chunks*' => Http::response(['chunks' => [
        ['chunk_id' => 41, 'page' => 1, 'section_path' => ['Policy', 'Refunds'], 'content' => 'Refunds within 14 days.'],
    ]])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $v1 = Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'title' => 'Rule', 'version' => '1']);
    $v2 = Document::factory()->indexed()->create(['workspace_id' => $workspace->id, 'title' => 'Rule', 'version' => '2', 'supersedes_document_id' => $v1->id]);

    $this->get(route('knowledge.show', $v2))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('knowledge/Show', false)
            ->where('document.id', $v2->id)
            ->where('document.supersedes', ['id' => $v1->id, 'title' => 'Rule', 'version' => '1'])
            ->where('chunksPreview', [['chunk_id' => 41, 'page' => 1, 'section' => 'Policy › Refunds', 'content' => 'Refunds within 14 days.']])
            ->has('versions', 2)
            ->where('versions.0.id', $v1->id)
            ->where('citedRecently', [])
            ->where('can', ['approve' => true, 'archive' => true, 'edit' => true, 'test' => true]));

    $this->get(route('knowledge.show', $v1))
        ->assertInertia(fn ($page) => $page->where('document.superseded_by.id', $v2->id));
});

it('updates metadata without re-ingesting and publishes metadata_changed', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $document = Document::factory()->indexed()->create(['workspace_id' => $workspace->id, 'title' => 'Old']);

    $this->patch(route('knowledge.update', $document), ['title' => 'New', 'roles' => ['admin']])->assertSessionHas('success');

    $document->refresh();
    expect($document->title)->toBe('New')
        ->and($document->roles)->toBe(['admin'])
        ->and($document->status)->toBe(DocumentStatus::Indexed)
        ->and(OutboxMessage::query()->topic('document.uploaded')->count())->toBe(0)
        ->and(OutboxMessage::query()->topic('document.metadata_changed')->sole()->payload)->toMatchArray(['title' => 'New', 'roles' => ['admin'], 'approval_status' => 'draft', 'archived' => false])
        ->and(AuditLog::query()->where('action', 'document.metadata_updated')->sole()->changes)->toHaveKeys(['title', 'roles']);
});

it('lets only approvers edit approved documents', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $document = Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'title' => 'Approved']);

    $this->patch(route('knowledge.update', $document), ['title' => 'Changed'])->assertSessionHas('error');
    expect($document->refresh()->title)->toBe('Approved');
});

it('approves an indexed draft with default dates in the workspace timezone', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 22:30:00');
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();
    $document = Document::factory()->indexed()->create(['workspace_id' => $workspace->id]);

    $this->post(route('knowledge.approve', $document))->assertSessionHas('success');

    $document->refresh();
    expect($document->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($document->effective_date?->toDateString())->toBe('2026-10-23')
        ->and($document->review_due_at?->toDateString())->toBe('2027-10-23')
        ->and(OutboxMessage::query()->topic('document.metadata_changed')->sole()->payload['approval_status'])->toBe('approved')
        ->and(AuditLog::query()->where('action', 'document.approved')->exists())->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('refuses to approve documents that are not indexed or not drafts', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $queued = Document::factory()->create(['workspace_id' => $workspace->id]);
    $archived = Document::factory()->indexed()->archived()->create(['workspace_id' => $workspace->id]);

    $this->post(route('knowledge.approve', $queued))->assertSessionHas('error', __('errors.documents.not_indexed'));
    $this->post(route('knowledge.approve', $archived))->assertSessionHas('error', __('errors.documents.not_draft'));

    expect($queued->refresh()->approval_status)->toBe(ApprovalStatus::Draft);
});

it('forbids managers from approving and archiving', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $document = Document::factory()->indexed()->create(['workspace_id' => $workspace->id]);

    $this->post(route('knowledge.approve', $document))->assertSessionHas('error');
    $this->post(route('knowledge.archive', $document))->assertSessionHas('error');

    expect($document->refresh()->approval_status)->toBe(ApprovalStatus::Draft);
});

it('archives the predecessor atomically when an effective successor is approved', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $v1 = Document::factory()->approved()->create(['workspace_id' => $workspace->id]);
    $v2 = Document::factory()->indexed()->create(['workspace_id' => $workspace->id, 'supersedes_document_id' => $v1->id]);

    $this->post(route('knowledge.approve', $v2), ['effective_date' => '2026-01-01'])->assertSessionHas('success');

    expect($v1->refresh()->approval_status)->toBe(ApprovalStatus::Archived)
        ->and($v1->archive_reason)->toBe(ArchiveReason::Superseded)
        ->and(OutboxMessage::query()->topic('document.metadata_changed')->count())->toBe(2);
});

it('approves in bulk and reports how many were approved', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $a = Document::factory()->indexed()->create(['workspace_id' => $workspace->id]);
    $b = Document::factory()->indexed()->create(['workspace_id' => $workspace->id]);
    $c = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->post(route('knowledge.approve-bulk'), ['document_ids' => [$a->id, $b->id, $c->id], 'effective_date' => '2026-02-01'])
        ->assertSessionHas('success', trans_choice('flash.documents.approved_bulk', 2, ['count' => 2]));

    expect(Document::query()->where('approval_status', 'approved')->count())->toBe(2);
});

it('retries failed documents with a new upload revision', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $document = Document::factory()->create(['workspace_id' => $workspace->id, 'status' => DocumentStatus::Failed, 'error_code' => 'ocr_failed']);

    $this->post(route('knowledge.retry', $document))->assertSessionHas('success');

    expect($document->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($document->upload_rev)->toBe(2)
        ->and(OutboxMessage::query()->topic('document.uploaded')->sole()->payload['upload_rev'])->toBe(2);
});

it('tests a question against a draft through the ai search API', function (): void {
    Http::fake(['ai.test/v1/search' => Http::response(['confidence' => 0.71, 'results' => [
        ['chunk_id' => 7, 'page' => 2, 'section_path' => ['Damage'], 'content' => 'Stop work and take photos.', 'confidence' => 0.71],
    ]])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $document = Document::factory()->indexed()->create(['workspace_id' => $workspace->id]);

    $this->postJson(route('knowledge.test', $document), ['question' => 'What if I scratch a table?'])
        ->assertOk()
        ->assertExactJson(['confidence' => 0.71, 'hits' => [
            ['chunk_id' => 7, 'page' => 2, 'section' => 'Damage', 'snippet' => 'Stop work and take photos.', 'confidence' => 0.71],
        ]]);

    Http::assertSent(fn ($request): bool => $request['include_drafts'] === true
        && $request['filters'] === ['document_ids' => [$document->id]]);
});

it('deletes drafts and publishes document.deleted but never deletes approved documents', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    Storage::disk('documents')->put('x/doc.pdf', 'pdf');
    $draft = Document::factory()->create(['workspace_id' => $workspace->id, 'path' => 'x/doc.pdf']);
    $approved = Document::factory()->approved()->create(['workspace_id' => $workspace->id]);

    $this->delete(route('knowledge.destroy', $draft))->assertRedirect(route('knowledge.index'));
    $this->delete(route('knowledge.destroy', $approved))->assertSessionHas('error');

    expect(Document::query()->pluck('id')->all())->toBe([$approved->id])
        ->and(OutboxMessage::query()->topic('document.deleted')->sole()->payload)->toEqual(['document_id' => $draft->id, 'workspace_id' => $workspace->id])
        ->and(AuditLog::query()->where('action', 'document.deleted')->exists())->toBeTrue();
    Storage::disk('documents')->assertMissing('x/doc.pdf');
});

it('imports the starter pack as drafts once and creates departments on request', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['locale' => 'ru'])->save();
    config(['billing.plans.free.limits.documents' => 100]);

    $this->post(route('knowledge.starter-pack'), ['pack' => 'cleaning', 'languages' => ['ru', 'en'], 'create_departments' => true])
        ->assertSessionHas('success');

    $residential = Department::query()->where('code', 'residential')->sole();

    expect(Document::query()->count())->toBe(16)
        ->and(Document::query()->where('approval_status', 'draft')->count())->toBe(16)
        ->and(Department::query()->count())->toBe(2)
        ->and($residential->name)->toBe('Уборка квартир')
        ->and(Document::query()->where('source_ref', 'cleaning:key-handover:ru')->sole()->department_ids)->toBe([$residential->id])
        ->and(Document::query()->where('source_ref', 'cleaning:manager-complaint-playbook:en')->sole()->roles)->toBe(['manager'])
        ->and(OutboxMessage::query()->topic('document.uploaded')->count())->toBe(16)
        ->and(AuditLog::query()->where('action', 'document.imported_starter_pack')->exists())->toBeTrue();

    $this->post(route('knowledge.starter-pack'), ['pack' => 'cleaning', 'languages' => ['ru'], 'create_departments' => true]);

    expect(Document::query()->count())->toBe(16);
});

it('blocks the starter pack import over the document limit', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('knowledge.starter-pack'), ['pack' => 'cleaning', 'languages' => ['ru', 'en'], 'create_departments' => false])
        ->assertSessionHas('limit', 'documents');

    expect(Document::query()->count())->toBe(0);
});

it('streams the file to the worker through the signed internal API', function (): void {
    Storage::disk('documents')->put('w/file.pdf', '%PDF-1.4 content');
    $document = Document::factory()->create(['path' => 'w/file.pdf', 'mime' => 'application/pdf']);

    $response = $this->internal('GET', "/internal/v1/documents/{$document->id}/file")->assertOk();

    expect($response->streamedContent())->toBe('%PDF-1.4 content')
        ->and($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('x-upload-rev'))->toBe('1')
        ->and($response->headers->get('x-workspace-id'))->toBe((string) $document->workspace_id);

    $this->internal('GET', '/internal/v1/documents/999999/file')->assertNotFound();
    $this->get("/internal/v1/documents/{$document->id}/file")->assertUnauthorized();
});

it('records processing progress reported by the worker and ignores stale revisions', function (): void {
    $document = Document::factory()->create(['upload_rev' => 2]);

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'processing', 'upload_rev' => 2, 'pages_done' => 5, 'pages_total' => 20, 'ocr_used' => true])->assertOk();
    expect($document->refresh()->status)->toBe(DocumentStatus::Processing)
        ->and($document->pages_done)->toBe(5)
        ->and($document->pages_total)->toBe(20);

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'failed', 'upload_rev' => 1, 'error_code' => 'timeout'])
        ->assertOk()
        ->assertJson(['ignored' => 'stale_revision']);
    expect($document->refresh()->status)->toBe(DocumentStatus::Processing);

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", [
        'status' => 'indexed', 'upload_rev' => 2, 'pages' => 20, 'chunks' => 61,
    ])->assertOk()->assertJson(['status' => 'indexed']);

    $document->refresh();
    expect($document->only(['pages', 'chunks', 'error', 'error_code']))->toBe(['pages' => 20, 'chunks' => 61, 'error' => null, 'error_code' => null])
        ->and($document->indexed_at)->not->toBeNull();

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'failed', 'upload_rev' => 2, 'error_code' => 'ocr_failed'])->assertOk();
    expect($document->refresh()->error_code)->toBe('ocr_failed');

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'done', 'upload_rev' => 2])->assertStatus(422);
    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'indexed', 'upload_rev' => 2], 'ai')->assertForbidden();
});
