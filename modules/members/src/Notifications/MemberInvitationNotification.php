<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Die Einladung als Mitglied (M-23): wohin, und dass die Anmeldung per Code ohne Passwort geht — in der Sprache des Kontos. */
final class MemberInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $siteName, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':site · Einladung als Mitglied', ['site' => $this->siteName]))
            ->greeting(__('Guten Tag'))
            ->line(__('Sie sind eingeladen, Mitglied von :site zu werden — damit sehen Sie die Seiten für Mitglieder.', ['site' => $this->siteName]))
            ->action(__('Anmelden'), $this->url)
            ->line(__('Ein Passwort brauchen Sie nicht: Geben Sie dort diese Adresse ein, und wir senden Ihnen einen Code per Mail.'));
    }
}
