<?php

declare(strict_types=1);

return [
    'http' => [
        '419' => 'Your session has expired. Refresh the page and try again.',
        '429' => 'Too many attempts. Please try again in :seconds seconds.',
        '503' => 'The service is temporarily unavailable. Please try again in a minute.',
    ],
    'forbidden' => 'You do not have access to this action.',
    'not_found' => 'Not found.',
    'too_many_requests' => 'Too many requests. Please try again in :seconds seconds.',
    'bot' => [
        'invalid_token' => 'Telegram rejected this token. Copy it again from BotFather.',
        'token_mismatch' => 'This token belongs to a different bot.',
        'already_connected' => 'This bot is already connected to another OpsBot workspace.',
        'network' => 'Telegram could not be reached. Try again in a minute.',
        'webhook_failed' => 'The webhook could not be set. The worker needs a public HTTPS address.',
        'revoked' => 'The token was revoked in BotFather. Connect a new token.',
        'disconnecting' => 'The bot is still disconnecting. Try again in a minute.',
        'not_connected' => 'Connect the company bot first.',
        'actor_not_linked' => 'Link your own Telegram account to the company bot first.',
    ],
    'employees' => [
        'not_found' => 'One or more employees were not found.',
        'not_active' => 'This employee is offboarded.',
        'role_forbidden' => 'You cannot assign this role.',
        'role_requires_web' => 'Owners, admins and managers need a web account. Invite them by e-mail.',
        'already_offboarded' => 'This employee is already offboarded.',
        'last_owner' => 'A workspace must keep at least one owner.',
        'not_offboarded' => 'Only offboarded employees can be reactivated.',
        'erased' => 'This employee\'s data was erased; the record cannot be reactivated.',
        'erase_requires_offboarded' => 'Offboard the employee before erasing their data.',
        'erase_other_workspaces' => 'This person is still a member of another workspace.',
        'department_scope' => 'You can manage employees only in your departments.',
        'primary_department' => 'The primary department must be one of the selected departments.',
        'phone_invalid' => 'Enter the phone number in international format, for example +79991234567.',
    ],
    'departments' => [
        'invalid' => 'One or more departments are not available.',
        'already_archived' => 'This department is already archived.',
        'name_taken' => 'A department with this name already exists.',
        'code_taken' => 'A department with this code already exists.',
        'manager_invalid' => 'The manager must be an active manager, admin or owner of this workspace.',
    ],
    'routing' => [
        'duplicate' => 'Each department and category can have only one rule.',
    ],
    'incidents' => [
        'description_required' => 'Describe what happened.',
        'transition_forbidden' => 'This status change is not allowed.',
        'comment_required' => 'Add a comment for this status change.',
        'assignee_invalid' => 'The assignee must be an active member of the workspace.',
    ],
    'tasks' => [
        'create_forbidden' => 'Only managers can create tasks on the web.',
        'transition_forbidden' => 'This status change is not allowed.',
        'department_scope' => 'You can create tasks only in your departments.',
        'assignee_invalid' => 'The assignee must be an active member of the workspace.',
    ],
    'questions' => [
        'answer_length' => 'The answer must be between 1 and 2000 characters.',
        'not_open' => 'This question is no longer open.',
        'not_answered' => 'Answer the question before converting it to a document.',
        'assignee_invalid' => 'The assignee must be an active member who can answer questions.',
    ],
    'documents' => [
        'not_draft' => 'Only drafts can be approved.',
        'not_indexed' => 'The document must finish processing before it can be approved.',
        'already_archived' => 'This document is already archived.',
        'supersedes_invalid' => 'The replaced document is not available.',
        'not_failed' => 'Only failed documents can be retried.',
        'delete_approved' => 'Archive an approved document instead of deleting it.',
        'department_scope' => 'You can manage documents only for your departments.',
    ],
    'starter_pack' => [
        'not_found' => 'This starter pack does not exist.',
        'languages' => 'Choose at least one available language.',
    ],
    'notice' => [
        'version_mismatch' => 'The notice was updated. Please read the new version.',
    ],
    'telegram' => [
        'not_linked' => 'This Telegram account is not linked to an active employee.',
    ],
    'internal' => [
        'invalid_identity' => 'The request does not identify a user.',
    ],
    'actions' => [
        'unavailable' => 'This action is not available.',
    ],
    'limits' => [
        'seats' => 'All :count seats of your plan are in use. Upgrade to add more people.',
        'documents' => 'Your plan allows :count documents. Archive old ones or upgrade.',
        'storage_mb' => 'Your plan\'s storage (:count MB) is full. Upgrade to upload more.',
        'ai_queries_monthly' => 'The monthly limit of :count AI questions is reached.',
        'max_pages_per_document' => 'Your plan allows up to :count pages per document.',
        'max_file_mb' => 'Your plan allows files up to :count MB.',
        'bots' => 'Your plan allows :count company bot.',
    ],
];
