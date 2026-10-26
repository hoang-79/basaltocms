<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail aus dem Extranet an Kundinnen und Kunden (CR-004, P-12): Rechnung erneut senden, Zahlungserinnerung (Mahnung)
 * oder Gutschrift — immer mit dem PDF im Anhang, in der Sprache der Rechnung. Wird sofort versendet (das PDF reist
 * nicht durch die Warteschlange).
 */
final class ExtranetInvoiceMail extends Notification
{
    use Queueable;

    public const SEND = 'send';

    public const REMINDER = 'reminder';

    public const CREDIT = 'credit';

    public function __construct(
        public readonly string $kind,
        public readonly string $number,
        public readonly string $amount,
        public readonly string $pdf,
        public readonly string $filename,
        public readonly ?string $reference = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting(__('Guten Tag'));
        $mail = match ($this->kind) {
            self::REMINDER => $mail->subject(__('Basalto · Erinnerung: Rechnung :number', ['number' => $this->number]))
                ->line(__('Die Rechnung :number über :amount ist noch offen. Bitte bezahlen Sie sie mit dem QR-Zahlteil im beiliegenden PDF.', ['number' => $this->number, 'amount' => $this->amount]))
                ->line(__('Haben Sie schon bezahlt? Dann ist diese Mail hinfällig.')),
            self::CREDIT => $mail->subject(__('Basalto · Gutschrift :number', ['number' => $this->number]))
                ->line(__('Zu Ihrer Rechnung :reference erhalten Sie eine Gutschrift über :amount — das PDF liegt bei.', ['reference' => $this->reference ?? '', 'amount' => $this->amount])),
            default => $mail->subject(__('Basalto · Rechnung :number', ['number' => $this->number]))
                ->line(__('Im Anhang finden Sie die Rechnung :number über :amount.', ['number' => $this->number, 'amount' => $this->amount])),
        };

        return $mail->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);
    }
}
