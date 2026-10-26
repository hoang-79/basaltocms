<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Der Anmeldecode per Mail (K-04): sechs Ziffern, zehn Minuten gültig. */
final class LoginCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':app · Ihr Anmeldecode', ['app' => (string) config('app.name')]))
            ->greeting(__('Guten Tag'))
            ->line(__('Ihr Code für die Anmeldung lautet: **:code**', ['code' => $this->code]))
            ->line(__('Er ist :minutes Minuten gültig und nur für diese Anmeldung bestimmt. Haben Sie keinen Code angefordert, ignorieren Sie diese Nachricht.', ['minutes' => $this->minutes]));
    }
}
