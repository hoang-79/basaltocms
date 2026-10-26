<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Die Laufzeit ist zu Ende, die Sites sind gesperrt (TB 12 Scheibe 4) — mit Löschdatum und Weg zum Export. */
final class AccountLockedNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly string $account, public readonly string $deletes, public readonly string $url) {}

    protected function bellKind(): string
    {
        return 'account.lifecycle';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => 'Die Sites von «:account» sind gesperrt', 'params' => ['account' => $this->account], 'body' => 'Löschung am :date.', 'body_params' => ['date' => $this->deletes], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · Ihre Sites sind gesperrt'))
            ->greeting(__('Guten Tag'))
            ->line(__('Die Laufzeit des Kontos «:account» ist zu Ende. Die Sites sind offline; im Backend können Sie alles lesen und exportieren.', ['account' => $this->account]))
            ->line(__('Am :deletes löschen wir Sites und Medien endgültig.', ['deletes' => $this->deletes]))
            ->action(__('Export erstellen'), $this->url);
    }
}
