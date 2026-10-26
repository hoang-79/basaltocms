<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Registrierung auf der SaaS bestätigen (TB 12 Scheibe 1, K-04): sechsstelliger Code oder Einmal-Link — kein Passwort. */
final class RegistrationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly string $url, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · Ihr Code für die Registrierung'))
            ->greeting(__('Guten Tag'))
            ->line(__('Ihr Code für die Registrierung lautet: **:code**', ['code' => $this->code]))
            ->line(__('Er ist :minutes Minuten gültig. Oder öffnen Sie diesen Link im selben Browser:', ['minutes' => $this->minutes]))
            ->action(__('Registrierung bestätigen'), $this->url)
            ->line(__('Haben Sie sich nicht registriert, ignorieren Sie diese Nachricht — ohne Bestätigung entsteht kein Konto.'));
    }
}
