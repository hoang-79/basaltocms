<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Notifications;

use Basalto\Core\Models\PrivacyRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Eingangsbestätigung eines Anliegens zum Datenschutz (CR-011 TB 61) — mit Art, Eingang und Frist; und, wenn das Team
 * es erledigt hat (`$answered`), die Antwort. In der Sprache des Kundenkontos.
 */
final class PrivacyRequestReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly int $requestId, public readonly bool $answered = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $item = PrivacyRequest::query()->withoutGlobalScopes()->with('site')->findOrFail($this->requestId);
        $zone = $item->site->timezone ?? 'Europe/Zurich';
        $kind = PrivacyRequest::kindLabel($item->kind);
        $mail = (new MailMessage)
            ->subject($this->answered ? __(':site · Antwort auf Ihr Anliegen zum Datenschutz', ['site' => (string) $item->site?->name])
                : __(':site · Ihr Anliegen zum Datenschutz ist eingegangen', ['site' => (string) $item->site?->name]))
            ->greeting(__('Guten Tag'));
        if ($this->answered) {
            $mail->line(__('Ihr Anliegen «:kind» vom :date ist erledigt.', ['kind' => $kind, 'date' => $item->received_at->timezone($zone)->format('d.m.Y')]))
                ->line('**'.__('Unsere Antwort').'**')->line((string) $item->answer);
        } else {
            $mail->line(__('Ihr Anliegen «:kind» ist am :date um :time Uhr eingegangen. Wir antworten bis :due.', ['kind' => $kind,
                'date' => $item->received_at->timezone($zone)->format('d.m.Y'), 'time' => $item->received_at->timezone($zone)->format('H:i'), 'due' => $item->due_at->timezone($zone)->format('d.m.Y')]))
                ->line('**'.__('Ihr Anliegen').'**')->line($item->message);
        }

        return $mail;
    }
}
