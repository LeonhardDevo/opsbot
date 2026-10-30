<?php

declare(strict_types=1);

return [
    'telegram' => [
        'driver' => env('TELEGRAM_DRIVER', 'auto'),
        'mode' => env('TELEGRAM_MODE', 'webhook'),
        'verify_timeout' => (int) env('TELEGRAM_VERIFY_TIMEOUT', 15),
        'disconnect_finalize_after_minutes' => 60,
        'token_pattern' => '/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/',
    ],

    'employee_links' => [
        'ttl_days' => 7,
        'bulk_max' => 50,
    ],

    'share_links' => [
        'ttl_minutes' => 30,
        'file_ttl_minutes' => 5,
        'view_audit_throttle_minutes' => 10,
    ],

    'actions' => [
        'ttl_hours' => 24,
        'max_per_answer' => 4,
        'executions_per_hour' => 5,
    ],

    'reminders' => [
        'pre_due_hours' => 2,
        'overdue_step_hours' => 24,
        'escalation_delay_minutes' => 1,
        'dispatch_batch' => 200,
    ],

    'confidence' => [
        'high' => 0.70,
        'default_min' => 0.40,
    ],

    'limits' => [
        'notice_hour' => 9,
        'warning_ratio' => 0.8,
    ],

    'starter_packs_path' => resource_path('starter-packs'),

    'board_column_limit' => 50,

    'desk_feed_limit' => 30,

    'dashboard_today_limit' => 20,

    'analytics_cache_seconds' => 300,

    'export_ttl_hours' => 24,

    'question_sla_hours' => 24,

    'email_fallback_topics' => [
        'opsbot.incident.created',
        'opsbot.incident.status_changed',
        'opsbot.task.assigned',
        'opsbot.task.reminder_due',
        'opsbot.task.escalated',
        'opsbot.question.escalated',
        'opsbot.question.answered',
        'opsbot.document.review_due',
        'opsbot.limits.notice',
        'opsbot.bot.revoked',
    ],
];
