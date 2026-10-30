<?php

declare(strict_types=1);

return [
    'number' => '#:number',
    'category' => [
        'damage' => 'Damage',
        'complaint' => 'Complaint',
        'no_access' => 'No access',
        'injury' => 'Injury',
        'lost_item' => 'Lost item',
        'other' => 'Other',
    ],
    'severity' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ],
    'status' => [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],
    'source' => [
        'bot' => 'Telegram',
        'web' => 'Web',
        'ai_proposal' => 'Assistant proposal',
    ],
    'event' => [
        'created' => 'Created',
        'status_changed' => 'Status changed',
        'assigned' => 'Assignee changed',
        'severity_changed' => 'Severity changed',
        'category_changed' => 'Category changed',
        'comment' => 'Comment',
        'task_linked' => 'Task created',
    ],
    'count' => ':count incident|:count incidents',
];
