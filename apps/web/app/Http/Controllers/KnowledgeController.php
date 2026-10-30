<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\AiClient;
use App\Ai\AiServiceException;
use App\Billing\PlanLimits;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\DocumentApprovalService;
use App\Domain\Opsbot\DocumentVisibility;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Presenters\DocumentPresenter;
use App\Domain\Opsbot\StarterPacks;
use App\Enums\AiChannel;
use App\Enums\ApprovalStatus;
use App\Enums\DocumentStatus;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Opsbot\OpsController;
use App\Http\Requests\Knowledge\StoreDocumentRequest;
use App\Http\Requests\Knowledge\UpdateDocumentRequest;
use App\Knowledge\DocumentFileInspector;
use App\Knowledge\DocumentService;
use App\Models\AiQuery;
use App\Models\Department;
use App\Models\Document;
use App\Support\Flash;
use App\Support\Paginated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KnowledgeController extends OpsController
{
    public function __construct(private readonly DocumentVisibility $visibility) {}

    public function index(Request $request, PlanLimits $limits, StarterPacks $packs): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'knowledge.view');
        $workspace = $actor->workspace;
        $today = $workspace->today();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(DocumentStatus::values())],
            'approval' => ['nullable', 'string', Rule::in(ApprovalStatus::values())],
            'department_id' => ['nullable', 'integer'],
            'review' => ['nullable', 'string', Rule::in(['overdue'])],
        ]);

        $base = fn (): Builder => $this->visibility->scope(Document::query()->withoutGlobalScopes(), $actor);

        $page = $base()
            ->with(['uploader', 'approver'])
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where(function (Builder $inner) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $inner->where('documents.title', 'ilike', $like)->orWhere('documents.original_name', 'ilike', $like);
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('documents.status', $status))
            ->when($filters['approval'] ?? null, fn (Builder $query, string $approval) => $query->where('documents.approval_status', $approval))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $department) => $query->whereRaw('documents.department_ids @> ARRAY[?]::integer[]', [(int) $department]))
            ->when(($filters['review'] ?? null) === 'overdue', fn (Builder $query) => $this->overdue($query, $today))
            ->orderByDesc('documents.id')
            ->paginate(20)
            ->withQueryString();

        $context = DocumentPresenter::context(collect($page->items()), $workspace->id);

        return Inertia::render('knowledge/Index', [
            'documents' => Paginated::from($page, fn (Document $document): array => DocumentPresenter::present($document, $context, $today)),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'departments' => $this->departmentRefs($workspace),
            'roles' => WorkspaceRole::options(),
            'counts' => [
                'draft' => $base()->where('approval_status', ApprovalStatus::Draft->value)->count(),
                'approved' => $base()->where('approval_status', ApprovalStatus::Approved->value)->count(),
                'archived' => $base()->where('approval_status', ApprovalStatus::Archived->value)->count(),
                'review_overdue' => $this->overdue($base(), $today)->count(),
            ],
            'starterPacks' => $packs->all($workspace, App::getLocale()),
            'limits' => $limits->usageFor($workspace, ['documents', 'storage_mb', 'max_file_mb', 'max_pages_per_document']),
            'accept' => DocumentFileInspector::acceptList(),
        ]);
    }

    public function store(StoreDocumentRequest $request, DocumentService $documents): RedirectResponse
    {
        $actor = $this->actor();
        $metadata = $request->metadata();
        $this->assertDepartments($actor, (array) ($metadata['department_ids'] ?? []));

        if (isset($metadata['supersedes_document_id'])) {
            $predecessor = Document::query()->forWorkspace($actor->workspace)->whereKey((int) $metadata['supersedes_document_id'])->first();

            if ($predecessor === null || $predecessor->isArchived()) {
                throw new OpsbotException('documents.supersedes_invalid');
            }
        }

        $document = $documents->upload($actor->workspace, $actor->user, $request->uploadedFile(), $request->sniffedMime(), $metadata);
        Flash::success('flash.documents.uploaded', ['title' => $document->title]);

        return back();
    }

    public function starterPack(Request $request, StarterPacks $packs): RedirectResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', Rule::in($packs->keys())],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['string', Rule::in(['ru', 'en'])],
            'create_departments' => ['required', 'boolean'],
        ]);

        $result = $packs->import($this->actor(), (string) $data['pack'], array_values(array_map('strval', (array) $data['languages'])), (bool) $data['create_departments']);
        Flash::successChoice('flash.documents.starter_pack_imported', $result['created']);

        return back();
    }

    public function show(Document $document, AiClient $ai): Response
    {
        $actor = $this->actor();
        $this->assertVisible($actor, $document);
        $document->load(['uploader', 'approver']);
        $today = $actor->workspace->today();

        $context = DocumentPresenter::context(collect([$document]), $actor->workspaceId());

        return Inertia::render('knowledge/Show', [
            'document' => DocumentPresenter::present($document, $context, $today),
            'chunksPreview' => $document->status === DocumentStatus::Indexed ? $this->chunksPreview($ai, $document) : [],
            'versions' => $this->versions($document),
            'citedRecently' => $this->citedRecently($actor, $document),
            'can' => [
                'approve' => $actor->can('knowledge.approve') && $document->isDraft() && $document->status === DocumentStatus::Indexed,
                'archive' => $actor->can('knowledge.approve') && ! $document->isArchived(),
                'edit' => $this->canEdit($actor, $document),
                'test' => $actor->can('knowledge.approve') && $document->status === DocumentStatus::Indexed,
            ],
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document, DocumentService $documents): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertVisible($actor, $document);

        if (! $this->canEdit($actor, $document)) {
            throw OpsbotException::forbidden();
        }

        $metadata = $request->metadata();

        if (array_key_exists('department_ids', $metadata)) {
            $this->assertDepartments($actor, (array) $metadata['department_ids']);
        }

        if ($document->isApproved()) {
            unset($metadata['effective_date']);
        } else {
            unset($metadata['review_due_at']);
        }

        unset($metadata['supersedes_document_id']);

        $documents->updateMetadata($document, $actor->user, $metadata);
        Flash::success('flash.documents.updated', ['title' => $document->title]);

        return back();
    }

    public function approve(Request $request, Document $document, DocumentApprovalService $approvals): RedirectResponse
    {
        $data = $request->validate([
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'review_due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_date'],
        ]);

        $approvals->approve($this->actor(), $document, $data['effective_date'] ?? null, $data['review_due_at'] ?? null);
        Flash::success('flash.documents.approved', ['title' => $document->title]);

        return back();
    }

    public function approveBulk(Request $request, DocumentApprovalService $approvals): RedirectResponse
    {
        $data = $request->validate([
            'document_ids' => ['required', 'array', 'min:1', 'max:200'],
            'document_ids.*' => ['integer', 'distinct'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $approvals->approveBulk($this->actor(), array_values(array_map('intval', (array) $data['document_ids'])), $data['effective_date'] ?? null);
        Flash::successChoice('flash.documents.approved_bulk', $result['approved']);

        return back();
    }

    public function archive(Document $document, DocumentApprovalService $approvals): RedirectResponse
    {
        $approvals->archive($this->actor(), $document);
        Flash::success('flash.documents.archived', ['title' => $document->title]);

        return back();
    }

    public function retry(Document $document, DocumentService $documents): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertVisible($actor, $document);
        $this->require($actor, 'knowledge.manage');

        if ($document->status !== DocumentStatus::Failed) {
            throw new OpsbotException('documents.not_failed');
        }

        $documents->retry($document, $actor->user);
        Flash::success('flash.documents.retry', ['title' => $document->title]);

        return back();
    }

    public function test(Request $request, Document $document, AiClient $ai): JsonResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'knowledge.approve');
        $this->assertVisible($actor, $document);

        $data = $request->validate(['question' => ['required', 'string', 'min:2', 'max:500']]);

        try {
            $result = $ai->search([
                'workspace_id' => $actor->workspaceId(),
                'query' => (string) $data['question'],
                'k' => 5,
                'user' => ['role' => $actor->role->value, 'department_ids' => $actor->departmentIds, 'privileged' => true],
                'include_drafts' => true,
                'filters' => ['document_ids' => [$document->id]],
                'channel' => AiChannel::Web->value,
                'ai_external_allowed' => $actor->workspace->ai_external_allowed,
            ]);
        } catch (AiServiceException $exception) {
            return response()->json([
                'message' => __($exception->isUnavailable() ? 'notifications.assistant.unavailable' : 'notifications.assistant.failed'),
            ], $exception->isUnavailable() ? 503 : 502);
        }

        $hits = [];

        foreach ((array) ($result['results'] ?? $result['hits'] ?? []) as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $section = $hit['section_path'] ?? $hit['section'] ?? null;
            $hits[] = [
                'chunk_id' => (int) ($hit['chunk_id'] ?? 0),
                'page' => is_numeric($hit['page'] ?? null) ? (int) $hit['page'] : null,
                'section' => is_array($section) ? implode(' › ', array_map('strval', $section)) : (is_string($section) ? $section : null),
                'snippet' => Str::limit((string) ($hit['snippet'] ?? $hit['content'] ?? ''), 400),
                'confidence' => round((float) ($hit['confidence'] ?? 0), 4),
            ];
        }

        return response()->json([
            'confidence' => round((float) ($result['confidence'] ?? ($hits !== [] ? max(array_column($hits, 'confidence')) : 0)), 4),
            'hits' => $hits,
        ]);
    }

    public function destroy(Document $document, DocumentService $documents): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'knowledge.approve');
        $this->assertVisible($actor, $document);

        if ($document->isApproved()) {
            throw new OpsbotException('documents.delete_approved');
        }

        $title = $document->title;
        $documents->delete($document, $actor->user);
        Flash::success('flash.documents.deleted', ['title' => $title]);

        return redirect()->route('knowledge.index');
    }

    public function download(Document $document): StreamedResponse
    {
        $actor = $this->actor();
        $this->assertVisible($actor, $document);

        return Storage::disk($document->disk)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private function overdue(Builder $query, string $today): Builder
    {
        return $query->where('documents.approval_status', ApprovalStatus::Approved->value)
            ->whereNotNull('documents.review_due_at')
            ->whereDate('documents.review_due_at', '<=', $today);
    }

    private function canEdit(Actor $actor, Document $document): bool
    {
        return match ($document->approval_status) {
            ApprovalStatus::Draft => $actor->can('knowledge.manage') && ($actor->privileged() || $document->department_ids === [] || $actor->intersects($document->department_ids)),
            ApprovalStatus::Approved => $actor->can('knowledge.approve'),
            ApprovalStatus::Archived => false,
        };
    }

    /** @throws OpsbotException */
    private function assertVisible(Actor $actor, Document $document): void
    {
        if (! $actor->can('knowledge.view') || ! $this->visibility->visibleTo($document, $actor)) {
            throw OpsbotException::notFound();
        }
    }

    /**
     * @param  array<mixed>  $departmentIds
     *
     * @throws OpsbotException
     */
    private function assertDepartments(Actor $actor, array $departmentIds): void
    {
        $ids = array_values(array_map('intval', $departmentIds));

        if ($ids !== [] && Department::query()->forWorkspace($actor->workspace)->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            throw new OpsbotException('departments.invalid');
        }

        if (! $actor->privileged() && ($ids === [] || array_diff($ids, $actor->departmentIds) !== [])) {
            throw OpsbotException::forbidden('documents.department_scope');
        }
    }

    /** @return list<array{id: int, title: string, version: string|null, approval_status: string, effective_date: string|null}> */
    private function versions(Document $document): array
    {
        $chain = [$document->id => $document];
        $cursor = $document;

        while ($cursor->supersedes_document_id !== null && ! isset($chain[$cursor->supersedes_document_id])) {
            $previous = Document::query()->forWorkspace($document->workspace_id)->find($cursor->supersedes_document_id);

            if ($previous === null) {
                break;
            }

            $chain[$previous->id] = $previous;
            $cursor = $previous;
        }

        $cursor = $document;

        while (($next = Document::query()->forWorkspace($document->workspace_id)->where('supersedes_document_id', $cursor->id)->orderByDesc('id')->first()) !== null && ! isset($chain[$next->id])) {
            $chain[$next->id] = $next;
            $cursor = $next;
        }

        ksort($chain);

        return array_values(array_map(fn (Document $item): array => [
            'id' => $item->id,
            'title' => $item->title,
            'version' => $item->version,
            'approval_status' => $item->approval_status->value,
            'effective_date' => $item->effective_date?->toDateString(),
        ], $chain));
    }

    /** @return list<array{query_id: int, question: string, created_at: string}> */
    private function citedRecently(Actor $actor, Document $document): array
    {
        $query = AiQuery::query()
            ->forWorkspace($actor->workspace)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereRaw('cited_document_versions @> ?::jsonb', [json_encode([['document_id' => $document->id]], JSON_THROW_ON_ERROR)])
            ->whereNotNull('question')
            ->orderByDesc('id')
            ->limit(10);

        return array_values(app(DepartmentScope::class)->aiQueries($query, $actor)->get()->map(fn (AiQuery $query): array => [
            'query_id' => $query->id,
            'question' => (string) $query->question,
            'created_at' => $query->created_at->toIso8601String(),
        ])->all());
    }

    /** @return list<array{chunk_id: int, page: int|null, section: string|null, content: string}> */
    private function chunksPreview(AiClient $ai, Document $document): array
    {
        try {
            return $ai->documentChunks($document->id, $document->workspace_id, 20);
        } catch (AiServiceException) {
            return [];
        }
    }
}
