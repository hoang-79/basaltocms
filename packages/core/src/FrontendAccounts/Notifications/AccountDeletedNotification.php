<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Bestätigung «Konto gelöscht» (CR-011 TB 61, Art. 17 und 19 DSGVO) — was weg ist und was das Gesetz aufzubewahren verlangt. */
final class AccountDeletedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $site) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':site · Ihr Konto ist gelöscht', ['site' => $this->site]))
            ->greeting(__('Guten Tag'))
            ->line(__('Wie gewünscht haben wir Ihr Konto bei :site gelöscht: Name, Adresse, Anmeldungen, Passkeys und verknüpfte Konten, Profil und Bilder der Community, Kommentare und Formulareingänge.', ['site' => $this->site]))
            ->line(__('Beiträge in der Community bleiben ohne Namen stehen. Rechnungen und Buchungen bewahren wir ohne Bezug zu Ihnen zehn Jahre auf, wie es das Gesetz verlangt.'))
            ->line(__('Mit derselben E-Mail-Adresse können Sie jederzeit ein neues Konto eröffnen.'));
    }
}
