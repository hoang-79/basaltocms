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
        // TB 72: der Code in Ziffernfeldern nach der ersten Zeile (notifications::email)
        return (new MailMessage)
            ->markdown('notifications::email', ['code' => $this->code])
            ->subject(__(':app · Ihr Anmeldecode', ['app' => (string) config('app.name')]))
            ->greeting(__('Guten Tag'))
            ->line(__('Ihr Code für die Anmeldung:'))
            ->line(__('Er ist :minutes Minuten gültig und nur für diese Anmeldung bestimmt. Haben Sie keinen Code angefordert, ignorieren Sie diese Nachricht.', ['minutes' => $this->minutes]));
    }
}
