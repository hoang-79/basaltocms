<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Der Code für das Frontend-Konto per Mail: sechs Ziffern, ein paar Minuten gültig — in der Sprache der Seite bzw. des Kontos. */
final class CustomerLoginCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly int $minutes, public readonly string $siteName) {}

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
            ->subject(__(':site · Ihr Anmeldecode', ['site' => $this->siteName]))
            ->greeting(__('Guten Tag'))
            ->line(__('Ihr Code für die Anmeldung:'))
            ->line(__('Er ist :minutes Minuten gültig. Haben Sie keinen Code angefordert, ignorieren Sie diese Nachricht — ohne Code entsteht kein Konto.', ['minutes' => $this->minutes]));
    }
}
