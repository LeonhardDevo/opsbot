<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Ai\AiClient;
use App\Ai\AiServiceException;
use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Access\ActorFactory;
use App\Domain\Opsbot\DocumentVisibility;
use App\Domain\Opsbot\Support\SignedLinks;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Locale\Locales;
use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ShareDocumentController extends Controller
{
    private const array HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(
        private readonly ActorFactory $actors,
        private readonly DocumentVisibility $visibility,
    ) {}

    public function show(Request $request, int $document, AiClient $ai, Auditor $auditor): Response
    {
        $check = $this->check($request, $document);

        if (is_string($check)) {
            return $this->error($request, $check);
        }

        [$model, $actor] = $check;
        App::setLocale($actor->locale());

        $state = 'ok';
        $successor = null;

        if ($model->approval_status === ApprovalStatus::Archived) {
            $state = 'archived';
            $next = Document::query()
                ->forWorkspace($model->workspace_id)
                ->where('supersedes_document_id', $model->id)
                ->where('approval_status', ApprovalStatus::Approved->value)
                ->orderByDesc('id')
                ->first();

            if ($next !== null && $this->visibility->answerable($next, $actor, $actor->workspace->today())) {
                $successor = ['title' => $next->title, 'url' => SignedLinks::document($next->id, $actor->id())];
            }
        }

        $chunkId = is_numeric($request->query('c')) ? (int) $request->query('c') : null;
        $excerpt = $state === 'ok' && $chunkId !== null ? $this->excerpt($ai, $model, $chunkId) : null;

        if ($state === 'ok' && Cache::add('share-view:'.$actor->id().':'.$model->id, true, now()->addMinutes((int) config('opsbot.share_links.view_audit_throttle_minutes', 10)))) {
            $auditor->record(
                'document.viewed_via_bot',
                $model,
                metadata: array_filter([
                    'chunk_id' => $chunkId,
                    'page' => is_numeric($request->query('p')) ? (int) $request->query('p') : null,
                    'version' => $model->version,
                ], fn (mixed $value): bool => $value !== null),
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: $model->title,
                source: Auditor::SOURCE_BOT,
            );
        }

        $response = Inertia::render('share/DocumentViewer', [
            'document' => [
                'id' => $model->id,
                'title' => $model->title,
                'version' => $model->version,
                'effective_date' => (string) $model->effective_date?->toDateString(),
                'departments' => Department::query()->forWorkspace($model->workspace_id)->whereIn('id', $model->department_ids === [] ? [0] : $model->department_ids)->orderBy('name')->pluck('name')->all(),
            ],
            'excerpt' => $excerpt,
            'successor' => $successor,
            'state' => $state,
            'downloadUrl' => SignedLinks::file($model->id, $actor->id()),
            'locale' => $actor->locale() === 'ru' ? 'ru' : 'en',
            'workspaceName' => $actor->workspace->name,
        ])->toResponse($request);

        $response->headers->add(self::HEADERS);

        return $response;
    }

    public function file(Request $request, int $document): Response
    {
        $check = $this->check($request, $document);

        if (is_string($check)) {
            return $this->error($request, $check);
        }

        [$model] = $check;

        if ($model->approval_status !== ApprovalStatus::Approved || ! Storage::disk($model->disk)->exists($model->path)) {
            return $this->error($request, 'not_found');
        }

        return Storage::disk($model->disk)->download($model->path, $model->original_name, [
            'Content-Type' => $model->mime,
            'X-Content-Type-Options' => 'nosniff',
            ...self::HEADERS,
        ]);
    }

    /** @return array{0: Document, 1: Actor}|string */
    private function check(Request $request, int $documentId): array|string
    {
        if (! URL::hasCorrectSignature($request)) {
            return 'forbidden';
        }

        if (! URL::signatureHasNotExpired($request)) {
            return 'expired';
        }

        $document = Document::query()->withoutGlobalScopes()->find($documentId);
        $user = is_numeric($request->query('u')) ? User::query()->find((int) $request->query('u')) : null;

        if ($document === null) {
            return 'not_found';
        }

        $workspace = Workspace::query()->find($document->workspace_id);
        $actor = $user !== null && $workspace !== null ? $this->actors->forUser($user, $workspace) : null;

        if ($actor === null || ! $this->visibility->visibleTo($document, $actor)) {
            return 'forbidden';
        }

        $today = $workspace->today();

        if ($document->approval_status === ApprovalStatus::Draft
            || ($document->approval_status === ApprovalStatus::Approved && ($document->effective_date === null || $document->effective_date->toDateString() > $today))) {
            return 'not_found';
        }

        return [$document, $actor];
    }

    private function error(Request $request, string $reason): Response
    {
        $locale = Locales::negotiate($request->header('Accept-Language'));

        if ($locale !== null) {
            App::setLocale($locale);
        }

        $response = Inertia::render('share/LinkError', ['reason' => $reason])->toResponse($request);
        $response->setStatusCode($reason === 'not_found' ? 404 : 403);
        $response->headers->add(self::HEADERS);

        return $response;
    }

    /** @return array{chunk_id: int, page: int|null, section: string|null, before: string|null, content: string, after: string|null}|null */
    private function excerpt(AiClient $ai, Document $document, int $chunkId): ?array
    {
        try {
            $context = $ai->chunkContext($document->id, $document->workspace_id, $chunkId);
        } catch (AiServiceException) {
            return null;
        }

        $chunk = $context['chunk'];

        if ($chunk === null) {
            return null;
        }

        $section = $chunk['section_path'] ?? $chunk['section'] ?? null;

        return [
            'chunk_id' => $chunkId,
            'page' => is_numeric($chunk['page'] ?? null) ? (int) $chunk['page'] : null,
            'section' => is_array($section) ? implode(' › ', array_map('strval', $section)) : (is_string($section) ? $section : null),
            'before' => isset($context['before']['content']) ? (string) $context['before']['content'] : null,
            'content' => (string) ($chunk['content'] ?? ''),
            'after' => isset($context['after']['content']) ? (string) $context['after']['content'] : null,
        ];
    }
}
