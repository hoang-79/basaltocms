<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ein Eingang als Mail an die Empfänger des Formulars (M-11): Betreff aus `subject` mit Platzhaltern `{feld}`,
 * jedes Feld als Zeile, Antwort an den Absender, wenn das Formular ein E-Mail-Feld hat. Dateien hängen nicht an —
 * sie liegen privat im Backend (Datensparsamkeit, N-05).
 */
final class FormSubmittedNotification extends Notification
{
    /** @param  list<array{label: string, value: string}>  $lines */
    public function __construct(public readonly string $subject, public readonly string $formLabel, public readonly string $siteName, public readonly array $lines, public readonly ?string $replyTo, public readonly ?string $backendUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting(__('Neuer Eingang: :form', ['form' => $this->formLabel]))
            ->line(__('Über das Formular «:form» auf :site:', ['form' => $this->formLabel, 'site' => $this->siteName]));
        foreach ($this->lines as $line) {
            $mail->line(sprintf('**%s:** %s', $line['label'], $line['value'] === '' ? '—' : $line['value']));
        }
        if ($this->replyTo !== null) {
            $mail->replyTo($this->replyTo);
        }
        if ($this->backendUrl !== null) {
            $mail->action(__('Im Backend ansehen'), $this->backendUrl);
        }

        return $mail->salutation('Basalto');
    }
}
