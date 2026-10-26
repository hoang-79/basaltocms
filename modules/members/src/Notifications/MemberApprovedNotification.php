<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Die Redaktion hat die Mitgliedschaft freigegeben (M-23) — mit dem Weg zur Anmeldung, in der Sprache des Kontos. */
final class MemberApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $siteName, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':site · Ihre Mitgliedschaft ist freigegeben', ['site' => $this->siteName]))
            ->greeting(__('Guten Tag'))
            ->line(__('Die Redaktion von :site hat Ihre Mitgliedschaft freigegeben. Die Seiten für Mitglieder stehen Ihnen jetzt offen.', ['site' => $this->siteName]))
            ->action(__('Anmelden'), $this->url);
    }
}
