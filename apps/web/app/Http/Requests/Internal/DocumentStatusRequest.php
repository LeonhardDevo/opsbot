<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

final class DocumentStatusRequest extends InternalRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:processing,indexed,failed'],
            'upload_rev' => ['required', 'integer', 'min:1'],
            'pages' => ['nullable', 'integer', 'min:0'],
            'chunks' => ['nullable', 'integer', 'min:0'],
            'pages_done' => ['nullable', 'integer', 'min:0'],
            'pages_total' => ['nullable', 'integer', 'min:0'],
            'ocr_used' => ['nullable', 'boolean'],
            'error' => ['nullable', 'string', 'max:5000'],
            'error_code' => ['nullable', 'string', 'in:too_many_pages,unsupported_type,empty_text,ocr_failed,timeout,file_missing,index_failed'],
        ];
    }
}
