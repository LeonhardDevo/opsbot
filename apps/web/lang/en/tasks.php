<?php

declare(strict_types=1);

return [
    'number' => 'T-:number',
    'status' => [
        'todo' => 'To do',
        'in_progress' => 'In progress',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],
    'source' => [
        'web' => 'Web',
        'bot' => 'Telegram',
        'incident' => 'Incident',
        'ai_proposal' => 'Assistant proposal',
    ],
    'reminder' => [
        'pre_due' => 'Before the deadline',
        'due' => 'At the deadline',
        'overdue_1' => 'Overdue, day 1',
        'overdue_2' => 'Overdue, day 2',
        'overdue_3' => 'Overdue, day 3',
        'escalation' => 'Escalated to the manager',
    ],
    'reminder_status' => [
        'planned' => 'Planned',
        'sent' => 'Sent',
        'skipped' => 'Skipped',
        'cancelled' => 'Cancelled',
    ],
    'count' => ':count task|:count tasks',
];
