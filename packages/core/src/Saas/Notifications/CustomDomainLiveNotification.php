<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** TB 12 Scheibe 3 (G-11): Die eigene Domain ist verbunden, hat ihr Zertifikat und ist jetzt die Hauptadresse der Site. */
final class CustomDomainLiveNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly string $site, public readonly string $host, public readonly string $url, public readonly string $accountUrl) {}

    protected function bellKind(): string
    {
        return 'account.domains';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => ':host ist live', 'params' => ['host' => $this->host], 'body' => 'Site «:site».', 'body_params' => ['site' => $this->site], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · :host ist live', ['host' => $this->host]))
            ->greeting(__('Guten Tag'))
            ->line(__('Die Site «:site» ist jetzt unter :host erreichbar — mit Zertifikat.', ['site' => $this->site, 'host' => $this->host]))
            ->line(__('Der Alias leitet ab sofort dorthin um; Suchmaschinen sehen nur noch Ihre eigene Domain.'))
            ->action(__('Site ansehen'), $this->url)
            ->line(__('Domains verwalten Sie unter Verwaltung › Konto › Domains: :url', ['url' => $this->accountUrl]));
    }
}
