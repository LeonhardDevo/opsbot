<?php

declare(strict_types=1);

namespace App\Outbox;

final class Topics
{
    public const string EMAIL_SEND = 'email.send';

    public const string TELEGRAM_NOTIFY = 'telegram.notify';

    public const string DOCUMENT_UPLOADED = 'document.uploaded';

    public const string DOCUMENT_DELETED = 'document.deleted';

    public const string DOCUMENT_METADATA_CHANGED = 'document.metadata_changed';

    public const string WORKSPACE_REEMBED = 'workspace.reembed';

    public const string TELEGRAM_BOT_DISCONNECT = 'telegram.bot.disconnect';

    public const string TELEGRAM_CONTEXT_INVALIDATE = 'telegram.context.invalidate';

    public const string INCIDENT_CREATED = 'opsbot.incident.created';

    public const string INCIDENT_STATUS_CHANGED = 'opsbot.incident.status_changed';

    public const string TASK_ASSIGNED = 'opsbot.task.assigned';

    public const string TASK_REMINDER_DUE = 'opsbot.task.reminder_due';

    public const string TASK_ESCALATED = 'opsbot.task.escalated';

    public const string QUESTION_ESCALATED = 'opsbot.question.escalated';

    public const string QUESTION_ANSWERED = 'opsbot.question.answered';

    public const string DOCUMENT_REVIEW_DUE = 'opsbot.document.review_due';

    public const string LIMITS_NOTICE = 'opsbot.limits.notice';

    public const string BOT_REVOKED = 'opsbot.bot.revoked';

    public const string PRIVACY_EXPORT_REQUESTED = 'privacy.export.requested';

    public const string PRIVACY_PURGE_DUE = 'privacy.purge.due';

    public const string WORKSPACE_PURGE = 'workspace.purge';

    public const string USER_PURGE = 'user.purge';
}
