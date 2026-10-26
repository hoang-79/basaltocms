<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Zugang zum Hersteller-Extranet (CR-004, P-09): Adresse, Anmeldung ohne Passwort mit Code per Mail oder Passkey. */
final class ExtranetWelcome extends Notification
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
            ->subject(__('Basalto · Zugang zum Extranet'))
            ->greeting(__('Guten Tag'))
            ->line(__('Sie gehören jetzt zum Team im Extranet von Basalto. Sie melden sich ohne Passwort an — mit einem Code per Mail oder einem Passkey.'))
            ->action(__('Extranet öffnen'), $this->url);
    }
}
