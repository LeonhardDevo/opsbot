<?php

declare(strict_types=1);

return [

    'actions' => [
        'auth' => [
            'login' => 'Signed in',
            'logout' => 'Signed out',
            'login_failed' => 'Failed sign-in attempt',
            'lockout' => 'Sign-in locked after too many attempts',
            'registered' => 'Account created',
            'email_verified' => 'Email confirmed',
            'password_reset' => 'Password reset',
            'two_factor_enabled' => 'Two-factor authentication enabled',
            'account_deleted' => 'Account deleted',
            'two_factor_disabled' => 'Two-factor authentication disabled',
        ],
        'workspace' => [
            'created' => 'Workspace created',
            'updated' => 'Workspace settings changed',
            'deleted' => 'Workspace deleted',
            'onboarding_step_skipped' => 'Setup step skipped',
            'onboarding_finished' => 'Setup finished',
            'deletion_scheduled' => 'Workspace deletion scheduled',
            'deletion_cancelled' => 'Workspace deletion cancelled',
        ],
        'member' => [
            'role_changed' => 'Member role changed',
            'removed' => 'Member removed',
            'left' => 'Member left the workspace',
        ],
        'invitation' => [
            'created' => 'Invitation sent',
            'revoked' => 'Invitation revoked',
            'accepted' => 'Invitation accepted',
        ],
        'billing' => [
            'subscription_changed' => 'Subscription changed',
        ],
        'ai' => [
            'settings_updated' => 'AI settings changed',
            'key_updated' => 'AI provider key saved',
            'key_cleared' => 'AI provider key removed',
        ],
        'document' => [
            'uploaded' => 'Document uploaded',
            'updated' => 'Document details changed',
            'deleted' => 'Document deleted',
            'approved' => 'Document approved',
            'archived' => 'Document archived',
            'metadata_updated' => 'Document details changed',
            'imported_starter_pack' => 'Starter pack imported',
            'viewed_via_bot' => 'Document opened from Telegram',
        ],
        'telegram' => [
            'linked' => 'Telegram connected',
            'unlinked' => 'Telegram disconnected',
        ],
        'assistant' => [
            'escalated' => 'Question escalated to admins',
        ],
        'telegram_bot' => [
            'connected' => 'Company bot connected',
            'token_rotated' => 'Bot token replaced',
            'disconnect_requested' => 'Bot disconnect requested',
            'disconnected' => 'Bot disconnected',
            'revoked' => 'Bot token revoked in Telegram',
        ],
        'employee' => [
            'created' => 'Employee added',
            'updated' => 'Employee changed',
            'link_issued' => 'Invite link issued',
            'linked' => 'Employee connected Telegram',
            'offboarded' => 'Employee offboarded',
            'reactivated' => 'Employee reactivated',
            'data_exported' => 'Employee data exported',
            'erased' => 'Employee data erased',
        ],
        'department' => [
            'created' => 'Department created',
            'updated' => 'Department changed',
            'archived' => 'Department archived',
        ],
        'routing_rules' => [
            'updated' => 'Routing rules changed',
        ],
        'incident' => [
            'created' => 'Incident created',
            'status_changed' => 'Incident status changed',
            'assigned' => 'Incident reassigned',
            'updated' => 'Incident changed',
            'commented' => 'Incident comment added',
        ],
        'task' => [
            'created' => 'Task created',
            'status_changed' => 'Task status changed',
            'assigned' => 'Task reassigned',
            'updated' => 'Task changed',
        ],
        'question' => [
            'escalated' => 'Question passed to a manager',
            'answered' => 'Question answered',
            'dismissed' => 'Question dismissed',
            'converted_to_doc' => 'Question converted to FAQ draft',
        ],
        'ai_query' => [
            'viewed' => 'Question log viewed',
        ],
        'ops_settings' => [
            'updated' => 'Assistant settings changed',
        ],
        'legal' => [
            'employee_notice_acknowledged' => 'Employee notice acknowledged',
            'dpa_accepted' => 'Data Processing Agreement accepted',
        ],
        'retention' => [
            'purged' => 'Expired records deleted',
        ],
        'legal' => [
            'accepted' => 'Legal document accepted',
        ],
        'privacy' => [
            'export_requested' => 'Personal data export requested',
            'workspace_export_requested' => 'Workspace export requested',
            'export_ready' => 'Data export ready',
            'export_failed' => 'Data export failed',
            'export_downloaded' => 'Data export downloaded',
            'deletion_scheduled' => 'Account deletion scheduled',
            'deletion_cancelled' => 'Account deletion cancelled',
            'deletion_completed' => 'Account permanently deleted',
            'workspace_purged' => 'Workspace data purged',
            'settings_updated' => 'Privacy settings changed',
            'retention_purged' => 'Retention purge completed',
        ],
        'email' => [
            'opted_out' => 'Recipient unsubscribed from automated e-mail',
        ],
    ],

    'system' => 'System',

];
