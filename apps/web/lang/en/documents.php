<?php

declare(strict_types=1);

return [
    'approval' => [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'archived' => 'Archived',
    ],
    'status' => [
        'queued' => 'Queued',
        'processing' => 'Processing',
        'indexed' => 'Indexed',
        'failed' => 'Failed',
    ],
    'archive_reason' => [
        'manual' => 'Archived manually',
        'superseded' => 'Replaced by a newer version',
    ],
    'source' => [
        'upload' => 'Upload',
        'starter_pack' => 'Starter pack',
        'faq' => 'Manager answer',
    ],
    'errors' => [
        'too_many_pages' => 'The document has more pages than your plan allows.',
        'unsupported_type' => 'This file type cannot be processed.',
        'empty_text' => 'No text could be extracted from the file.',
        'ocr_failed' => 'Text recognition failed for this file.',
        'timeout' => 'Processing took too long and was stopped.',
        'file_missing' => 'The uploaded file is missing.',
        'index_failed' => 'The document could not be indexed.',
    ],
    'count' => ':count document|:count documents',
];
