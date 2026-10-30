<?php

declare(strict_types=1);

return [
    'action_type' => [
        'create_incident' => 'Create incident',
        'create_task' => 'Create task',
        'escalate' => 'Pass to manager',
        'open_source' => 'Open source',
        'ask' => 'Ask',
    ],
    'action_status' => [
        'proposed' => 'Proposed',
        'executed' => 'Done',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'failed' => 'Failed',
    ],
    'refusal_reason' => [
        'low_confidence' => 'Low confidence',
        'not_found' => 'Not found',
        'model_refusal' => 'Model refused',
        'unavailable' => 'Assistant unavailable',
        'limit_reached' => 'Monthly limit reached',
        'paused' => 'Answers paused',
    ],
    'answer_kind' => [
        'document' => 'From documents',
        'tool_data' => 'From company data',
    ],
    'actions' => [
        'create_incident' => 'Create incident',
        'create_task' => 'Create task',
        'escalate' => 'Pass to manager',
        'open_source' => 'Open source',
        'ask' => 'Ask',
    ],
    'actions_result' => [
        'not_found' => 'This action is no longer available.',
        'foreign' => 'This button was sent to another person.',
        'unavailable' => 'This action is not available.',
        'cancelled' => 'Cancelled.',
        'expired' => 'This proposal has expired. Ask again.',
        'rate_limited' => 'Too many requests in the last hour. Try again later.',
        'confirm_prompt' => 'Check the details and confirm.',
        'source_ready' => 'Here is the source document.',
        'done' => 'Done.',
        'incident_created' => 'Incident :number created. Responsible: :assignee.',
        'incident_exists' => 'Incident :number has already been created. Responsible: :assignee.',
        'task_created' => 'Task :number created. Assignee: :assignee.',
        'task_exists' => 'Task :number has already been created. Assignee: :assignee.',
        'escalated' => 'Question :number passed to :assignee.',
    ],
    'card' => [
        'incident_title' => 'New incident',
        'task_title' => 'New task',
        'category' => 'Category',
        'severity' => 'Severity',
        'description' => 'Description',
        'client_ref' => 'Client',
        'title' => 'Title',
        'due' => 'Due',
        'due_in_hours' => 'in :count hour|in :count hours',
    ],
    'buttons' => [
        'confirm' => 'Confirm',
        'cancel' => 'Cancel',
        'open_source' => 'Open source',
        'convert_faq' => 'Save as FAQ draft',
    ],
    'subscription' => [
        'paused' => 'AI answers are paused for your company — contact your administrator. You can still report an incident.',
    ],
    'bot_actions' => [
        'incident_already' => 'Incident :number already has this status.',
        'incident_in_progress' => 'Incident :number is now in progress.',
        'incident_resolved' => 'Incident :number is resolved.',
        'task_already' => 'Task :number already has this status.',
        'task_in_progress' => 'Task :number started.',
        'task_done' => 'Task :number is done.',
        'dismiss_choose' => 'Choose a reason to dismiss the question.',
        'dismissed' => 'Question :number dismissed.',
        'converted' => 'An FAQ draft was created from question :number. It must be approved before the bot uses it.',
        'incident_created_html' => 'Incident <b>:number</b> created. Responsible: <b>:assignee</b>.',
        'nothing_to_escalate' => 'You have not asked anything yet.',
        'escalated_html' => 'Your question <b>:number</b> was passed to <b>:assignee</b>.',
        'answered_html' => 'Your answer to <b>:number</b> was sent to the employee.',
    ],
    'counts' => [
        'hours' => ':count hour|:count hours',
        'days' => ':count day|:count days',
    ],
    'mail' => [
        'open' => 'Open in OpsBot',
        'fields' => [
            'category' => 'Category: :value',
            'severity' => 'Severity: :value',
            'department' => 'Department: :value',
            'due' => 'Due: :value',
        ],
        'incident_created' => [
            'subject' => 'New incident :number',
            'intro' => 'A new incident was assigned to you. Reported by :reporter.',
        ],
        'incident_status' => [
            'subject' => 'Incident :number: :status',
            'intro' => 'The status of your incident changed to “:status”.',
        ],
        'task_assigned' => [
            'subject' => 'New task :number',
            'intro' => 'You have a new task “:title” from :by.',
        ],
        'task_reminder' => [
            'subject' => 'Reminder: task :number',
            'pre_due' => 'Task “:title” is due soon (:due).',
            'due' => 'Task “:title” is due now (:due).',
            'overdue_1' => 'Task “:title” is overdue (due :due).',
            'overdue_2' => 'Task “:title” is still overdue (due :due).',
            'overdue_3' => 'Task “:title” has been overdue for three days (due :due).',
            'escalation' => 'Task “:title” is overdue (due :due).',
        ],
        'task_escalated' => [
            'subject' => 'Overdue task :number',
            'intro' => 'Task “:title” assigned to :assignee is overdue (due :due).',
        ],
        'question_escalated' => [
            'subject' => 'Question :number needs your answer',
            'intro' => ':employee asked a question the assistant could not answer from the approved documents.',
        ],
        'question_answered' => [
            'subject' => 'Manager\'s answer (not from the regulations)',
            'intro' => ':manager answered your question:',
        ],
        'review_due' => [
            'subject' => 'Review due: :title',
            'intro' => 'The document “:title” was due for review on :date.',
        ],
        'limits' => [
            'subject' => 'Plan limit reached',
            'intro' => 'Your workspace has reached the limit for :limit (:used of :max). Upgrade the plan to keep adding.',
        ],
        'bot_revoked' => [
            'subject' => 'The company bot token was revoked',
            'intro' => 'Telegram no longer accepts the token of :username. Employees cannot reach the bot until you connect a new token.',
        ],
    ],
];
