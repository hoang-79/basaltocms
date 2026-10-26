<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Erinnerung vor dem Ende der Testphase (TB 12 Scheibe 1): nichts läuft automatisch an. */
final class TrialEndingNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly string $account, public readonly string $date, public readonly int $days, public readonly string $url) {}

    protected function bellKind(): string
    {
        return 'account.trial';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => 'Die Testphase von «:account» endet am :date', 'params' => ['account' => $this->account, 'date' => $this->date], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(trans_choice('Basalto · Ihre Testphase endet in :count Tag|Basalto · Ihre Testphase endet in :count Tagen', $this->days, ['count' => $this->days]))
            ->greeting(__('Guten Tag'))
            ->line(__('Die Testphase des Kontos «:account» endet am :date.', ['account' => $this->account, 'date' => $this->date]))
            ->line(__('Nichts läuft automatisch an: Wählen Sie einen Plan, wenn Sie weitermachen möchten. Ihre Sites bleiben auch danach erreichbar.'))
            ->action(__('Plan wählen'), $this->url);
    }
}
