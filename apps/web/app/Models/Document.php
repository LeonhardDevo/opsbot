<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgIntegerArray;
use App\Enums\ApprovalStatus;
use App\Enums\ArchiveReason;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $uploaded_by
 * @property string $title
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property list<int> $department_ids
 * @property list<string> $roles
 * @property list<string> $tags
 * @property string|null $version
 * @property CarbonInterface|null $effective_date
 * @property DocumentStatus $status
 * @property int|null $pages
 * @property int|null $chunks
 * @property bool $ocr_used
 * @property string|null $error
 * @property CarbonInterface|null $indexed_at
 * @property int $upload_rev
 * @property ApprovalStatus $approval_status
 * @property int|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $review_due_at
 * @property CarbonInterface|null $review_notified_on
 * @property int|null $supersedes_document_id
 * @property CarbonInterface|null $archived_at
 * @property int|null $archived_by
 * @property ArchiveReason|null $archive_reason
 * @property DocumentSource $source
 * @property string|null $source_ref
 * @property string|null $language
 * @property int|null $pages_done
 * @property int|null $pages_total
 * @property string|null $error_code
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $uploader
 * @property-read User|null $approver
 * @property-read Document|null $supersedes
 */
class Document extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'uploaded_by', 'title', 'original_name', 'mime', 'size_bytes', 'disk', 'path', 'sha256',
        'department_ids', 'roles', 'tags', 'version', 'effective_date', 'status', 'pages', 'chunks', 'ocr_used',
        'error', 'indexed_at', 'upload_rev', 'approval_status', 'approved_by', 'approved_at', 'review_due_at',
        'review_notified_on', 'supersedes_document_id', 'archived_at', 'archived_by', 'archive_reason', 'source',
        'source_ref', 'language', 'pages_done', 'pages_total', 'error_code',
    ];

    protected $hidden = ['disk', 'path'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'roles' => '[]',
        'tags' => '[]',
        'department_ids' => '{}',
        'status' => 'queued',
        'approval_status' => 'draft',
        'source' => 'upload',
        'upload_rev' => 1,
        'ocr_used' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'department_ids' => PgIntegerArray::class,
            'roles' => 'array',
            'tags' => 'array',
            'effective_date' => 'date',
            'review_due_at' => 'date',
            'review_notified_on' => 'date',
            'status' => DocumentStatus::class,
            'approval_status' => ApprovalStatus::class,
            'archive_reason' => ArchiveReason::class,
            'source' => DocumentSource::class,
            'size_bytes' => 'integer',
            'pages' => 'integer',
            'chunks' => 'integer',
            'pages_done' => 'integer',
            'pages_total' => 'integer',
            'upload_rev' => 'integer',
            'ocr_used' => 'boolean',
            'indexed_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'supersedes_document_id')->withoutGlobalScopes();
    }

    /** @return HasMany<Document, $this> */
    public function successors(): HasMany
    {
        return $this->hasMany(Document::class, 'supersedes_document_id')->withoutGlobalScopes();
    }

    public function isApproved(): bool
    {
        return $this->approval_status === ApprovalStatus::Approved;
    }

    public function isArchived(): bool
    {
        return $this->approval_status === ApprovalStatus::Archived;
    }

    public function isDraft(): bool
    {
        return $this->approval_status === ApprovalStatus::Draft;
    }

    /** @param  Builder<self>  $query */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where('approval_status', '!=', ApprovalStatus::Archived->value);
    }
}
