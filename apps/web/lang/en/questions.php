<?php

declare(strict_types=1);

return [
    'number' => 'Q-:number',
    'status' => [
        'open' => 'Open',
        'answered' => 'Answered',
        'converted_to_doc' => 'Converted to FAQ',
        'dismissed' => 'Dismissed',
    ],
    'reason' => [
        'low_confidence' => 'Low confidence',
        'not_found' => 'Not found in documents',
        'conflict' => 'Conflicting documents',
        'user_request' => 'Employee request',
        'limit_reached' => 'Monthly question limit reached',
    ],
    'dismiss_reason' => [
        'duplicate' => 'Duplicate',
        'out_of_scope' => 'Out of scope',
        'spam' => 'Spam',
        'other' => 'Other',
    ],
    'faq' => [
        'title' => 'FAQ: :question',
        'question' => 'Question',
        'answer' => 'Answer',
        'source' => 'Source: manager answer, :name, :date.',
    ],
    'count' => ':count question|:count questions',
];
