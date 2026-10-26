<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding\Notifications;

use Basalto\Core\Notifications\AlsoInBell;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Der Export ist fertig (TB 12 Scheibe 4, Mockup 24 «Wir mailen, wenn er fertig ist») — signierter Link, 7 Tage gültig. */
final class ExportReadyNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    /** @param  ?string  $site  Name der Site, null = ganzes Konto */
    public function __construct(public readonly ?string $site, public readonly string $size, public readonly string $expires, public readonly string $url) {}

    protected function bellKind(): string
    {
        return 'account.exports';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        return ['title' => 'Ihr Export ist bereit', 'body' => ':size, abrufbar bis :expires.', 'body_params' => ['size' => $this->size, 'expires' => $this->expires], 'url' => $this->url];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Basalto · Ihr Export ist bereit'))
            ->greeting(__('Guten Tag'))
            ->line(__('Der Export «:label» (:size) ist fertig.', ['label' => $this->site !== null ? __('Site «:name»', ['name' => $this->site]) : __('Ganzes Konto'), 'size' => $this->size]))
            ->line(__('Der Link gilt bis :date; danach löschen wir die Datei. Zum Herunterladen melden Sie sich an.', ['date' => $this->expires]))
            ->action(__('Export herunterladen'), $this->url)
            ->line(__('Der Export enthält Inhalte, Medien, Theme, Navigation, Formulare und Einstellungen — dasselbe Format wie der Import.'));
    }
}
