<?php

declare(strict_types=1);

return [
    'former_employee' => 'Former employee #:id',
    'kind' => [
        'web' => 'Web account',
        'telegram_only' => 'Telegram only',
    ],
    'status' => [
        'invited' => 'Invited',
        'active' => 'Active',
        'offboarded' => 'Offboarded',
    ],
    'telegram' => [
        'not_linked' => 'Not linked',
        'linked' => 'Linked',
        'blocked' => 'Blocked the bot',
    ],
    'count' => ':count employee|:count employees',
    'notice' => [
        'title' => 'Before we start',
        'intro' => 'This assistant answers work questions from your company\'s approved documents.',
        'stored_title' => 'What is stored',
        'stored_questions' => 'Your questions and answers, the time, your department and role, and the documents cited.',
        'stored_records' => 'Incidents you report, tasks assigned to you, and which source documents you open.',
        'visible_title' => 'Who can see it',
        'visible_to' => 'Company owners and administrators, and managers of your departments.',
        'retention_title' => 'How long it is kept',
        'retention_questions' => 'Questions and answers: :count day.|Questions and answers: :count days.',
        'retention_queue' => 'Questions passed to a manager: :count day after they are answered or dismissed.|Questions passed to a manager: :count days after they are answered or dismissed.',
        'retention_records' => 'Incidents and tasks: until the company deletes its workspace or erases your data.',
        'retention_audit' => 'Record of opened documents: :count day.|Record of opened documents: :count days.',
        'ai_warning' => 'Answers are generated automatically and cite sources. They can be wrong: check the source and ask a manager when unsure. A manager\'s answer is marked as not from the regulations.',
        'rights' => 'Your administrator can export or erase your data on request.',
        'commands' => 'Commands: /privacy, /history, /unlink.',
    ],
    'export' => [
        'ready_title' => 'Data export for :name is ready',
        'ready_body' => 'The archive is available for 24 hours.',
        'mail_subject' => 'Data export for :name is ready',
        'download' => 'Download archive',
    ],
];
