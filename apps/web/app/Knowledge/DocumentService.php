<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Audit\Auditor;
use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimits;
use App\Enums\ApprovalStatus;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class DocumentService
{
    public const array METADATA_FIELDS = ['title', 'department_ids', 'roles', 'tags', 'version', 'language', 'review_due_at', 'effective_date'];

    public function __construct(
        private Outbox $outbox,
        private Auditor $auditor,
        private PlanLimits $limits,
    ) {}

    public static function disk(): string
    {
        return (string) config('filesystems.documents_disk', 'documents');
    }

    /**
     * @param  array{title?: string|null, department_ids?: list<int>, roles?: list<string>, tags?: list<string>, version?: string|null, language?: string|null, supersedes_document_id?: int|null}  $metadata
     *
     * @throws PlanLimitExceeded
     */
    public function upload(Workspace $workspace, User $uploader, UploadedFile $file, string $mime, array $metadata): Document
    {
        $size = (int) $file->getSize();
        $this->ensureCanAdd($workspace, $size, 1);

        $extension = strtolower($file->getClientOriginalExtension());
        $title = self::title($metadata['title'] ?? null, $file->getClientOriginalName());

        return $this->store(
            $workspace,
            $uploader,
            fn (string $disk, string $path): mixed => Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path)),
            $extension,
            [
                'title' => $title,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime' => $mime,
                'size_bytes' => $size,
                'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
                'department_ids' => $metadata['department_ids'] ?? [],
                'roles' => $metadata['roles'] ?? [],
                'tags' => $metadata['tags'] ?? [],
                'version' => $metadata['version'] ?? null,
                'language' => $metadata['language'] ?? null,
                'supersedes_document_id' => $metadata['supersedes_document_id'] ?? null,
                'source' => DocumentSource::Upload,
            ],
            'document.uploaded',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws PlanLimitExceeded
     */
    public function createFromContent(Workspace $workspace, ?User $creator, string $content, string $filename, array $attributes, string $auditAction): Document
    {
        $size = strlen($content);

        return $this->store(
            $workspace,
            $creator,
            fn (string $disk, string $path): mixed => Storage::disk($disk)->put($path, $content),
            pathinfo($filename, PATHINFO_EXTENSION) ?: 'md',
            [
                'original_name' => Str::limit($filename, 250, ''),
                'mime' => 'text/markdown',
                'size_bytes' => $size,
                'sha256' => hash('sha256', $content),
                ...$attributes,
            ],
            $auditAction,
        );
    }

    /**
     * @param  list<int>  $departmentIds
     *
     * @throws PlanLimitExceeded
     */
    public function createFaqDraft(Workspace $workspace, User $creator, string $title, string $body, array $departmentIds, ?string $language): Document
    {
        $this->ensureCanAdd($workspace, strlen($body), 1);

        return $this->createFromContent($workspace, $creator, $body, Str::slug(Str::limit($title, 60, '')).'.md', [
            'title' => Str::limit($title, 250, ''),
            'department_ids' => $departmentIds,
            'roles' => [],
            'tags' => ['faq'],
            'language' => $language,
            'source' => DocumentSource::Faq,
        ], 'document.uploaded');
    }

    /** @throws PlanLimitExceeded */
    public function ensureCanAdd(Workspace $workspace, int $bytes, int $count): void
    {
        $this->limits->ensure($workspace, 'documents', $count);
        $this->limits->ensure($workspace, 'storage_mb', (int) ceil($bytes / 1048576));
        $this->limits->ensureWithin($workspace, 'max_file_mb', (int) ceil($bytes / 1048576));
    }

    /** @param  array<string, mixed>  $metadata */
    public function updateMetadata(Document $document, User $actor, array $metadata): Document
    {
        return DB::transaction(function () use ($document, $actor, $metadata): Document {
            $before = self::snapshot($document);

            $document->fill(array_intersect_key($metadata, array_flip(self::METADATA_FIELDS)));

            if (! $document->isDirty()) {
                return $document;
            }

            $document->save();
            $this->publishMetadataChanged($document);

            $this->auditor->record(
                'document.metadata_updated',
                $document,
                Auditor::diff($before, self::snapshot($document)),
                actor: $actor,
                subjectLabel: $document->title,
            );

            return $document;
        });
    }

    public function retry(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor): Document {
            $document->forceFill([
                'status' => DocumentStatus::Queued,
                'upload_rev' => $document->upload_rev + 1,
                'error' => null,
                'error_code' => null,
                'pages_done' => null,
                'pages_total' => null,
            ])->save();

            $this->publishUploaded($document);

            $this->auditor->record(
                'document.uploaded',
                $document,
                metadata: ['retry' => true, 'upload_rev' => $document->upload_rev],
                actor: $actor,
                subjectLabel: $document->title,
            );

            return $document;
        });
    }

    public function delete(Document $document, User $actor): void
    {
        $disk = $document->disk;
        $path = $document->path;

        DB::transaction(function () use ($document, $actor): void {
            $this->outbox->publish(Topics::DOCUMENT_DELETED, [
                'document_id' => $document->id,
                'workspace_id' => $document->workspace_id,
            ], $document->workspace_id);

            $this->auditor->record('document.deleted', $document, metadata: [
                'original_name' => $document->original_name,
                'approval_status' => $document->approval_status->value,
            ], actor: $actor, subjectLabel: $document->title);

            Document::query()->withoutGlobalScopes()->where('supersedes_document_id', $document->id)->update(['supersedes_document_id' => null]);
            $document->delete();
        });

        Storage::disk($disk)->delete($path);
    }

    public function publishUploaded(Document $document): void
    {
        $workspace = Workspace::query()->findOrFail($document->workspace_id);

        $this->outbox->publish(Topics::DOCUMENT_UPLOADED, [
            'document_id' => $document->id,
            'workspace_id' => $document->workspace_id,
            'upload_rev' => $document->upload_rev,
            'title' => $document->title,
            'mime' => $document->mime,
            'size_bytes' => $document->size_bytes,
            'ai_external_allowed' => $workspace->ai_external_allowed,
            'limits' => [
                'max_pages_per_document' => $this->limits->limit($workspace, 'max_pages_per_document'),
            ],
            'metadata' => self::metadataPayload($document),
        ], $document->workspace_id);
    }

    public function publishMetadataChanged(Document $document): void
    {
        $this->outbox->publish(Topics::DOCUMENT_METADATA_CHANGED, [
            'document_id' => $document->id,
            'workspace_id' => $document->workspace_id,
            'title' => $document->title,
            'department_ids' => $document->department_ids,
            'roles' => $document->roles,
            'tags' => $document->tags,
            'version' => $document->version,
            'effective_date' => $document->effective_date?->toDateString(),
            'approval_status' => $document->approval_status->value,
            'archived' => $document->approval_status === ApprovalStatus::Archived,
        ], $document->workspace_id);
    }

    /** @return array<string, mixed> */
    public static function metadataPayload(Document $document): array
    {
        return [
            'department_ids' => $document->department_ids,
            'roles' => $document->roles,
            'tags' => $document->tags,
            'version' => $document->version,
            'effective_date' => $document->effective_date?->toDateString(),
            'approval_status' => $document->approval_status->value,
            'language' => $document->language,
        ];
    }

    public static function title(?string $title, string $originalName): string
    {
        $title = trim((string) $title);

        if ($title !== '') {
            return Str::limit($title, 250, '');
        }

        return Str::limit(pathinfo($originalName, PATHINFO_FILENAME) ?: 'Document', 250, '');
    }

    /** @return array<string, mixed> */
    public static function snapshot(Document $document): array
    {
        return [
            'title' => $document->title,
            'department_ids' => $document->department_ids,
            'roles' => $document->roles,
            'tags' => $document->tags,
            'version' => $document->version,
            'language' => $document->language,
            'effective_date' => $document->effective_date?->toDateString(),
            'review_due_at' => $document->review_due_at?->toDateString(),
        ];
    }

    /**
     * @param  \Closure(string, string): mixed  $write
     * @param  array<string, mixed>  $attributes
     */
    private function store(Workspace $workspace, ?User $uploader, \Closure $write, string $extension, array $attributes, string $auditAction): Document
    {
        $disk = self::disk();
        $path = $workspace->id.'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;

        $write($disk, $path);

        try {
            return DB::transaction(function () use ($workspace, $uploader, $attributes, $disk, $path, $auditAction): Document {
                $document = new Document;
                $document->fill([
                    'workspace_id' => $workspace->id,
                    'uploaded_by' => $uploader?->id,
                    'disk' => $disk,
                    'path' => $path,
                    'status' => DocumentStatus::Queued,
                    'approval_status' => ApprovalStatus::Draft,
                    'upload_rev' => 1,
                    ...$attributes,
                ]);
                $document->save();

                $this->publishUploaded($document);

                $this->auditor->record($auditAction, $document, metadata: [
                    'mime' => $document->mime,
                    'size_bytes' => $document->size_bytes,
                    'document_source' => $document->source->value,
                ], actor: $uploader, workspace: $workspace, subjectLabel: $document->title);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }
}
