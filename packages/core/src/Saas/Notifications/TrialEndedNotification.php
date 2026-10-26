<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Die Testphase ist zu Ende (TB 12 Scheibe 1): weiterhin erreichbar, Fussleiste erst nach einer Frist, keine Sperre. */
final class TrialEndedNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly string $account, public readonly string $bannerDate, public readonly string $url) {}

    protected function bellKind(): string
    {
        return 'account.trial';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => 'Die Testphase von «:account» ist abgelaufen', 'params' => ['account' => $this->account], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · Ihre Testphase ist abgelaufen'))
            ->greeting(__('Guten Tag'))
            ->line(__('Die Testphase des Kontos «:account» ist abgelaufen. Ihre Sites bleiben erreichbar, und Sie arbeiten im Backend weiter.', ['account' => $this->account]))
            ->line(__('Ohne Plan zeigen die Sites ab :date unten einen Hinweis auf die abgelaufene Testphase.', ['date' => $this->bannerDate]))
            ->action(__('Plan wählen'), $this->url);
    }
}
