<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Registrierung mit einer Adresse, die schon ein Konto hat (TB 12 Scheibe 1): eine E-Mail gehört genau einem Konto. Statt
 * eines Codes geht ein Einmal-Link zur Anmeldung hinaus — die Seite antwortet dabei gleich wie sonst (keine Auskunft,
 * welche Adressen ein Konto haben).
 */
final class RegistrationExistsNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · Sie haben schon ein Konto'))
            ->greeting(__('Guten Tag'))
            ->line(__('Zu dieser Adresse gibt es bereits ein Konto. Eine weitere Site legen Sie im Backend unter Konto › Sites an.'))
            ->action(__('Anmelden'), $this->url)
            ->line(__('Haben Sie sich nicht registriert, ignorieren Sie diese Nachricht.'));
    }
}
