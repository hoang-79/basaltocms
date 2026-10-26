<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Das Konto ist auf der neuen Instanz angekommen (TB 12 Scheibe 4) — prüfen und den Umzug abschliessen. */
final class AccountMovedNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly string $account, public readonly string $url) {}

    protected function bellKind(): string
    {
        return 'account.lifecycle';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => 'Der Umzug von «:account» ist abgeschlossen', 'params' => ['account' => $this->account], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · Umzug Ihres Kontos abgeschlossen'))
            ->greeting(__('Guten Tag'))
            ->line(__('Das Konto «:account» läuft jetzt im neuen Plan — Sites, Medien und Benutzer sind umgezogen, die Adressen bleiben gleich.', ['account' => $this->account]))
            ->line(__('Passkeys und Zwei-Faktor-Anmeldung richten Sie nach der nächsten Anmeldung neu ein; die Anmeldung mit Code per Mail geht sofort.'))
            ->line(__('Bitte prüfen Sie Ihre Sites. Mit «Umzug abschliessen» löschen wir die alte Kopie.'))
            ->action(__('Umzug abschliessen'), $this->url);
    }
}
