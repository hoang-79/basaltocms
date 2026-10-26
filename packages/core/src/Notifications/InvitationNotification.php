<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\Sites\SiteRole;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Die Einladung per Mail (K-03): wer, wohin, welche Rolle, wie lange der Link gilt. Die Rolle steht zusätzlich als Wert
 * (`$role`), damit die Mail sie in der Sprache der Empfängerin nennt (N-01); `$roleLabel` ist die Beschriftung beim Einladen.
 */
final class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly string $siteName, public readonly string $roleLabel, public readonly ?string $inviter, public readonly int $hours, public readonly ?string $role = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Einladung zu :site', ['site' => $this->siteName]))
            ->greeting(__('Guten Tag'))
            ->line(__(':inviter lädt Sie zu «:site» ein — Rolle :role.', ['inviter' => $this->inviter ?? 'Basalto', 'site' => $this->siteName, 'role' => SiteRole::tryFrom((string) $this->role)?->label() ?? $this->roleLabel]))
            ->action(__('Einladung annehmen'), $this->url)
            ->line(__('Der Link gilt :hours Stunden und nur einmal. Erwarten Sie keine Einladung, ignorieren Sie diese Nachricht.', ['hours' => $this->hours]));
    }
}
