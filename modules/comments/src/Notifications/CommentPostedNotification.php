<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ein neuer Kommentar als Mail an die Redaktion oder den Autor (M-27): Inhalt, Name und Text — die E-Mail-Adresse
 * der kommentierenden Person steht nicht darin (sie bleibt im Backend), dazu der Weg zur Moderation.
 */
final class CommentPostedNotification extends Notification
{
    public function __construct(public readonly string $siteName, public readonly string $entryTitle, public readonly string $authorName, public readonly string $excerpt,
        public readonly string $status, public readonly ?string $backendUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__('Neuer Kommentar zu «:title»', ['title' => $this->entryTitle]))
            ->greeting(__('Neuer Kommentar'))
            ->line($this->status === 'pending'
                ? __(':author hat «:title» auf :site kommentiert — der Kommentar wartet auf Freigabe:', ['author' => $this->authorName, 'title' => $this->entryTitle, 'site' => $this->siteName])
                : __(':author hat «:title» auf :site kommentiert:', ['author' => $this->authorName, 'title' => $this->entryTitle, 'site' => $this->siteName]))
            ->line($this->excerpt);
        if ($this->backendUrl !== null) {
            $mail->action($this->status === 'pending' ? __('Freigeben oder löschen') : __('In der Moderation ansehen'), $this->backendUrl);
        }

        return $mail->salutation('Basalto');
    }
}
