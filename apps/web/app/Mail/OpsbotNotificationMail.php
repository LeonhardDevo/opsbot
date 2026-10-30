<?php

declare(strict_types=1);

namespace App\Mail;

use App\Outbox\OutboxMailTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

final class OpsbotNotificationMail extends Mailable
{
    use Queueable;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $topic,
        public readonly array $payload,
        public readonly string $outboxId,
        public readonly ?int $workspaceId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lines()['subject']);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            OutboxMailTransport::HEADER_REFERENCE_TYPE => 'email_fallback',
            OutboxMailTransport::HEADER_REFERENCE_ID => $this->outboxId,
            OutboxMailTransport::HEADER_WORKSPACE => (string) $this->workspaceId,
            OutboxMailTransport::HEADER_LOCALE => App::getLocale(),
        ]);
    }

    public function content(): Content
    {
        $lines = $this->lines();

        return new Content(
            markdown: 'mail.opsbot.notification',
            with: [
                'heading' => $lines['subject'],
                'lines' => $lines['lines'],
                'quote' => $lines['quote'],
                'actionUrl' => $lines['url'],
                'actionLabel' => (string) __('opsbot.mail.open'),
            ],
        );
    }

    /** @return array{subject: string, lines: list<string>, quote: string|null, url: string|null} */
    public function lines(): array
    {
        $p = $this->payload;
        $get = fn (string $key): string => (string) data_get($p, $key, '');
        $url = is_string($p['web_url'] ?? null) ? $p['web_url'] : null;

        return match ($this->topic) {
            'opsbot.incident.created' => [
                'subject' => (string) __('opsbot.mail.incident_created.subject', ['number' => $get('incident.display_number')]),
                'lines' => array_values(array_filter([
                    (string) __('opsbot.mail.incident_created.intro', ['reporter' => $get('incident.reporter_name') ?: '—']),
                    (string) __('opsbot.mail.fields.category', ['value' => (string) __('incidents.category.'.$get('incident.category'))]),
                    (string) __('opsbot.mail.fields.severity', ['value' => (string) __('incidents.severity.'.$get('incident.severity'))]),
                    $get('incident.department_name') !== '' ? (string) __('opsbot.mail.fields.department', ['value' => $get('incident.department_name')]) : null,
                ])),
                'quote' => $get('incident.description_excerpt'),
                'url' => $url,
            ],
            'opsbot.incident.status_changed' => [
                'subject' => (string) __('opsbot.mail.incident_status.subject', ['number' => $get('incident.display_number'), 'status' => (string) __('incidents.status.'.$get('incident.status'))]),
                'lines' => [(string) __('opsbot.mail.incident_status.intro', ['status' => (string) __('incidents.status.'.$get('incident.status'))])],
                'quote' => $get('note_excerpt') !== '' ? $get('note_excerpt') : null,
                'url' => OpsbotNotificationMail::appUrl('/incidents/'.$get('incident.id')),
            ],
            'opsbot.task.assigned' => [
                'subject' => (string) __('opsbot.mail.task_assigned.subject', ['number' => $get('task.display_number')]),
                'lines' => array_values(array_filter([
                    (string) __('opsbot.mail.task_assigned.intro', ['title' => $get('task.title'), 'by' => $get('assigned_by_name') ?: '—']),
                    $get('task.due_local') !== '' ? (string) __('opsbot.mail.fields.due', ['value' => $get('task.due_local')]) : null,
                ])),
                'quote' => null,
                'url' => $url,
            ],
            'opsbot.task.reminder_due' => [
                'subject' => (string) __('opsbot.mail.task_reminder.subject', ['number' => $get('task.display_number')]),
                'lines' => [(string) __('opsbot.mail.task_reminder.'.$get('kind'), ['title' => $get('task.title'), 'due' => $get('task.due_local')])],
                'quote' => null,
                'url' => OpsbotNotificationMail::appUrl('/tasks?focus='.$get('task.id')),
            ],
            'opsbot.task.escalated' => [
                'subject' => (string) __('opsbot.mail.task_escalated.subject', ['number' => $get('task.display_number')]),
                'lines' => [(string) __('opsbot.mail.task_escalated.intro', ['title' => $get('task.title'), 'assignee' => $get('assignee_name') ?: '—', 'due' => $get('task.due_local')])],
                'quote' => null,
                'url' => $url,
            ],
            'opsbot.question.escalated' => [
                'subject' => (string) __('opsbot.mail.question_escalated.subject', ['number' => $get('question.display_number')]),
                'lines' => [(string) __('opsbot.mail.question_escalated.intro', ['employee' => $get('question.employee_name') ?: '—'])],
                'quote' => $get('question.question_excerpt'),
                'url' => $url,
            ],
            'opsbot.question.answered' => [
                'subject' => (string) __('opsbot.mail.question_answered.subject'),
                'lines' => [(string) __('opsbot.mail.question_answered.intro', ['manager' => $get('manager_name')]), strip_tags(str_replace('<br>', "\n", $get('text_html')))],
                'quote' => $get('question_excerpt'),
                'url' => null,
            ],
            'opsbot.document.review_due' => [
                'subject' => (string) __('opsbot.mail.review_due.subject', ['title' => Str::limit($get('document.title'), 80)]),
                'lines' => [(string) __('opsbot.mail.review_due.intro', ['title' => $get('document.title'), 'date' => $get('document.review_due_local')])],
                'quote' => null,
                'url' => $url,
            ],
            'opsbot.limits.notice' => [
                'subject' => (string) __('opsbot.mail.limits.subject'),
                'lines' => [(string) __('opsbot.mail.limits.intro', ['limit' => (string) __('billing.limits.'.$get('limit_key')), 'used' => $get('used'), 'max' => $get('limit')])],
                'quote' => null,
                'url' => $url,
            ],
            'opsbot.bot.revoked' => [
                'subject' => (string) __('opsbot.mail.bot_revoked.subject'),
                'lines' => [(string) __('opsbot.mail.bot_revoked.intro', ['username' => $get('bot_username') !== '' ? '@'.$get('bot_username') : '—'])],
                'quote' => null,
                'url' => $url,
            ],
            default => [
                'subject' => (string) config('app.name'),
                'lines' => [],
                'quote' => null,
                'url' => $url,
            ],
        };
    }

    public static function appUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
