<?php

declare(strict_types=1);

return [
    'bot' => [
        'connected' => 'The bot is connected and active.',
        'disconnect_requested' => 'The bot is being disconnected.',
        'test_message_sent' => 'A test message is on its way to your Telegram.',
    ],
    'employees' => [
        'created' => ':name was added. Issue an invite link to connect Telegram.',
        'updated' => ':name was updated.',
        'offboarded' => ':name was offboarded.',
        'export_queued' => 'The export is being prepared. You will get a download link shortly.',
        'erased' => 'The employee\'s personal data was erased.',
    ],
    'departments' => [
        'created' => 'Department “:name” created.',
        'updated' => 'Department “:name” updated.',
        'archived' => 'Department “:name” archived.',
    ],
    'routing' => [
        'updated' => 'Routing rules saved.',
    ],
    'incidents' => [
        'created' => 'Incident :number created.',
        'updated' => 'Incident updated.',
        'commented' => 'Comment added.',
    ],
    'tasks' => [
        'created' => 'Task :number created.',
        'updated' => 'Task updated.',
    ],
    'questions' => [
        'answered' => 'Answer to :number sent to the employee.',
        'dismissed' => 'Question :number dismissed.',
        'converted' => 'FAQ draft created. Approve it to make it answerable.',
        'reassigned' => 'Question reassigned.',
    ],
    'documents' => [
        'uploaded' => '“:title” uploaded — processing has started.',
        'starter_pack_imported' => ':count draft imported.|:count drafts imported.',
        'updated' => '“:title” updated.',
        'approved' => '“:title” approved.',
        'approved_bulk' => ':count document approved.|:count documents approved.',
        'archived' => '“:title” archived.',
        'retry' => 'Processing of “:title” restarted.',
        'deleted' => '“:title” deleted.',
    ],
    'settings' => [
        'assistant_saved' => 'Assistant settings saved.',
    ],
    'onboarding' => [
        'finished' => 'Setup complete. Welcome to OpsBot.',
    ],
];
