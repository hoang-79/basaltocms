<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Bestätigung der Kündigung (TB 12 Scheibe 4) mit Laufzeit, Sperre und Löschdatum — in der Sprache der Empfängerin. */
final class AccountCancelledNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly string $account, public readonly string $ends, public readonly string $deletes, public readonly int $lockDays, public readonly string $url) {}

    protected function bellKind(): string
    {
        return 'account.lifecycle';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => 'Kündigung von «:account» bestätigt', 'params' => ['account' => $this->account], 'body' => 'Laufzeit bis :ends, Löschung am :deletes.',
            'body_params' => ['ends' => $this->ends, 'deletes' => $this->deletes], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · Kündigung bestätigt'))
            ->greeting(__('Guten Tag'))
            ->line(__('Das Konto «:account» ist gekündigt. Bis :ends läuft alles wie bisher.', ['account' => $this->account, 'ends' => $this->ends]))
            ->line(__('Danach sind die Sites :days Tage gesperrt — das Backend bleibt für Sie lesbar, der Export geht weiter. Am :deletes löschen wir Sites und Medien.', ['days' => $this->lockDays, 'deletes' => $this->deletes]))
            ->line(__('Sie können die Kündigung bis zum Ende der Laufzeit zurücknehmen.'))
            ->action(__('Export und Kündigung'), $this->url);
    }
}
