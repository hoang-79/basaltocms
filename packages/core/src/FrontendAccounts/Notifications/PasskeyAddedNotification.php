<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** TB 53: Ein Passkey wurde zum Kundenkonto hinzugefügt — zur Sicherheit per Mail, mit dem Weg zum Entfernen. */
final class PasskeyAddedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $siteName, public readonly string $accountUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':site · Neuer Passkey «:name»', ['site' => $this->siteName, 'name' => $this->name]))
            ->greeting(__('Guten Tag'))
            ->line(__('Für Ihr Konto bei :site gibt es einen neuen Passkey: «:name». Sie melden sich damit ohne Code an.', ['site' => $this->siteName, 'name' => $this->name]))
            ->action(__('Konto öffnen'), $this->accountUrl)
            ->line(__('Waren Sie das nicht? Entfernen Sie den Passkey im Konto unter «Anmeldung» und melden Sie sich beim Team der Site.'));
    }
}
