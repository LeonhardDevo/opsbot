<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\DocumentStatusRequest;
use App\Models\Document;
use App\Notifications\DocumentProcessed;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentsController extends Controller
{
    public function file(int $document): StreamedResponse|JsonResponse
    {
        $model = Document::query()->withoutGlobalScopes()->find($document);

        if ($model === null || ! Storage::disk($model->disk)->exists($model->path)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return Storage::disk($model->disk)->response($model->path, $model->original_name, [
            'Content-Type' => $model->mime,
            'X-Document-Id' => (string) $model->id,
            'X-Workspace-Id' => (string) $model->workspace_id,
            'X-Upload-Rev' => (string) $model->upload_rev,
            'X-Content-Sha256' => $model->sha256,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function status(DocumentStatusRequest $request, int $document): JsonResponse
    {
        return DB::transaction(function () use ($request, $document): JsonResponse {
            $model = Document::query()->withoutGlobalScopes()->whereKey($document)->lockForUpdate()->first();

            if ($model === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $rev = (int) $request->validated('upload_rev');

            if ($rev < $model->upload_rev) {
                return response()->json(['id' => $model->id, 'status' => $model->status->value, 'ignored' => 'stale_revision']);
            }

            $status = DocumentStatus::from((string) $request->validated('status'));
            $previous = $model->status;

            $model->status = $status;
            $model->pages = $request->validated('pages') ?? $model->pages;
            $model->chunks = $request->validated('chunks') ?? $model->chunks;
            $model->pages_done = $request->validated('pages_done') ?? ($status === DocumentStatus::Indexed ? ($request->validated('pages') ?? $model->pages_done) : $model->pages_done);
            $model->pages_total = $request->validated('pages_total') ?? ($status === DocumentStatus::Indexed ? ($request->validated('pages') ?? $model->pages_total) : $model->pages_total);
            $model->ocr_used = (bool) ($request->validated('ocr_used') ?? $model->ocr_used);
            $model->error_code = $status === DocumentStatus::Failed ? (string) ($request->validated('error_code') ?? 'index_failed') : null;
            $model->error = $status === DocumentStatus::Failed ? (string) ($request->validated('error') ?? $model->error_code) : null;
            $model->indexed_at = $status === DocumentStatus::Indexed ? now() : $model->indexed_at;
            $model->save();

            if ($previous !== $status && in_array($status, [DocumentStatus::Indexed, DocumentStatus::Failed], true)) {
                $model->loadMissing('uploader')->uploader?->notify(new DocumentProcessed($model));
            }

            return response()->json(['id' => $model->id, 'status' => $model->status->value]);
        });
    }
}
