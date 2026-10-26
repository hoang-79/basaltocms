<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * TB 53: Ein Konto bei einem Anbieter wurde mit dem Kundenkonto verknüpft — zur Sicherheit per Mail, mit dem Weg
 * zum Trennen. In der Sprache des Kontos.
 */
final class SocialLinkedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $provider, public readonly string $siteName, public readonly string $accountUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':site · Neue Anmeldung über :provider', ['site' => $this->siteName, 'provider' => $this->provider]))
            ->greeting(__('Guten Tag'))
            ->line(__('Ihr Konto bei :site ist jetzt mit Ihrem Konto bei :provider verknüpft. Sie können sich damit ohne Code anmelden.', ['site' => $this->siteName, 'provider' => $this->provider]))
            ->action(__('Konto öffnen'), $this->accountUrl)
            ->line(__('Waren Sie das nicht? Trennen Sie die Verknüpfung im Konto unter «Anmeldung» und melden Sie sich beim Team der Site.'));
    }
}
