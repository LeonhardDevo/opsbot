<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\EmployeeExport;
use App\Outbox\OutboxMailTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

final class EmployeeExportReady extends InAppNotification
{
    public function __construct(public readonly EmployeeExport $export) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return isset($notifiable->email) && is_string($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function inApp(object $notifiable): InAppMessage
    {
        return new InAppMessage(
            kind: 'success',
            titleKey: 'employees.export.ready_title',
            titleParams: ['name' => $this->export->subject->displayName()],
            bodyKey: 'employees.export.ready_body',
            href: $this->relativeUrl(),
            workspaceId: $this->export->workspace_id,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspaceId = $this->export->workspace_id;
        $locale = App::getLocale();

        return (new MailMessage)
            ->markdown('mail.notification')
            ->subject((string) __('employees.export.mail_subject', ['name' => $this->export->subject->displayName()]))
            ->line((string) __('employees.export.ready_body'))
            ->action((string) __('employees.export.download'), rtrim((string) config('app.url'), '/').$this->relativeUrl())
            ->withSymfonyMessage(function (Email $email) use ($workspaceId, $locale): void {
                $headers = $email->getHeaders();
                $headers->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_TYPE, 'employee_export');
                $headers->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_ID, $this->export->id);
                $headers->addTextHeader(OutboxMailTransport::HEADER_WORKSPACE, (string) $workspaceId);
                $headers->addTextHeader(OutboxMailTransport::HEADER_LOCALE, $locale);
            });
    }

    private function relativeUrl(): string
    {
        return URL::temporarySignedRoute(
            'employees.exports.download',
            $this->export->expires_at,
            ['export' => $this->export->id],
            absolute: false,
        );
    }
}
