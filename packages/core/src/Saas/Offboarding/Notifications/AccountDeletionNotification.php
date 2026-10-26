<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Erinnerung vor der Löschung (TB 12 Scheibe 4): sieben Tage und einen Tag vorher, je genau einmal. */
final class AccountDeletionNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly string $account, public readonly string $deletes, public readonly int $days, public readonly string $url) {}

    protected function bellKind(): string
    {
        return 'account.lifecycle';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => 'Die Sites von «:account» werden am :date gelöscht', 'params' => ['account' => $this->account, 'date' => $this->deletes], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(trans_choice('Basalto · Löschung Ihrer Sites in :count Tag|Basalto · Löschung Ihrer Sites in :count Tagen', $this->days, ['count' => $this->days]))
            ->greeting(__('Guten Tag'))
            ->line(__('Am :deletes löschen wir die Sites und Medien des Kontos «:account» endgültig. Rechnungen bewahren wir auf, wie es das Gesetz verlangt.', ['deletes' => $this->deletes, 'account' => $this->account]))
            ->line(__('Wenn Sie Ihre Inhalte behalten möchten: Erstellen Sie jetzt einen Export — er lässt sich in jede Basalto-Installation importieren.'))
            ->action(__('Export erstellen'), $this->url);
    }
}
