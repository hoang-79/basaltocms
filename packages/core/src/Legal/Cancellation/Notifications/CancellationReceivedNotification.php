<?php

declare(strict_types=1);

namespace Basalto\Core\Legal\Cancellation\Notifications;

use Basalto\Core\Legal\Cancellation\ContractCancellations;
use Basalto\Core\Models\ContractCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bestätigung von «Verträge hier kündigen» (CR-011 TB 59, § 312k Abs. 4 BGB): Inhalt der Kündigung, Datum und Uhrzeit des
 * Eingangs und das Ende des Vertrags — sofort nach dem Eingang und noch einmal, wenn das Team zugeordnet oder der
 * tägliche Lauf eine vorgemerkte Kündigung ausgeführt hat (`$update`). In der Sprache der Seite.
 */
final class CancellationReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly int $cancellationId, public readonly bool $update = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cancellation = ContractCancellation::query()->with('site')->findOrFail($this->cancellationId);
        $service = app(ContractCancellations::class);
        $locale = app()->getLocale();
        $zone = $cancellation->site->timezone ?? 'Europe/Zurich';
        $received = $cancellation->received_at->timezone($zone);

        $mail = (new MailMessage)
            ->subject(match (true) {
                $cancellation->status === ContractCancellation::REVOKED => __('Basalto · Ihre Kündigung :number ist zurückgenommen', ['number' => $cancellation->number]),
                $cancellation->status === ContractCancellation::REJECTED => __('Basalto · Ihre Kündigung :number — kein Vertrag gefunden', ['number' => $cancellation->number]),
                $this->update => __('Basalto · Ihre Kündigung :number ist ausgeführt', ['number' => $cancellation->number]),
                default => __('Basalto · Ihre Kündigung :number ist eingegangen', ['number' => $cancellation->number]),
            })
            ->greeting(__('Guten Tag :name', ['name' => $cancellation->name]))
            ->line(__('Ihre Kündigung ist am :date um :time Uhr bei uns eingegangen (Nummer :number).',
                ['date' => $received->format('d.m.Y'), 'time' => $received->format('H:i'), 'number' => $cancellation->number]));

        $mail->line($service->outcome($cancellation, $locale));
        foreach ($service->notes($cancellation, $locale) as $note) {
            $mail->line($note);
        }
        if (in_array($cancellation->status, [ContractCancellation::DONE, ContractCancellation::REJECTED], true) && $cancellation->note !== null && $cancellation->note !== '') {
            $mail->line(__('Hinweis von uns: :note', ['note' => $cancellation->note]));
        }

        $mail->line('**'.__('Ihre Angaben').'**')
            ->line(__('Art: :kind', ['kind' => $cancellation->kind === ContractCancellation::EXTRAORDINARY ? __('Ausserordentliche Kündigung') : __('Ordentliche Kündigung')]));
        if ($cancellation->kind === ContractCancellation::EXTRAORDINARY && $cancellation->reason !== null) {
            $mail->line(__('Grund: :reason', ['reason' => $cancellation->reason]));
        }
        $mail->line(__('Name: :name', ['name' => $cancellation->name]))
            ->line(__('E-Mail-Adresse: :email', ['email' => $cancellation->email]))
            ->line(__('Vertrag: :contract', ['contract' => $service->describe($cancellation, $locale)]));
        if ($cancellation->reference !== null && $cancellation->reference !== '') {
            $mail->line(__('Kennung: :reference', ['reference' => $cancellation->reference]));
        }
        $mail->line(__('Gewünschtes Ende: :when', ['when' => $cancellation->wish_date !== null ? $cancellation->wish_date->format('d.m.Y') : __('zum nächstmöglichen Zeitpunkt')]));

        $revoke = $service->revokeUrl($cancellation);
        if ($revoke !== null) {
            $mail->line(__('Nicht von Ihnen oder anders überlegt? Bis zum Ende können Sie die Kündigung zurücknehmen: [Kündigung zurücknehmen](:url)', ['url' => $revoke]));
        }

        return $mail;
    }
}
