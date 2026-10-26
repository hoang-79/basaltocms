<?php

declare(strict_types=1);

namespace Basalto\Core\Legal\Cancellation\Notifications;

use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Legal\Cancellation\ContractCancellations;
use Basalto\Core\Models\ContractCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An den Hersteller (CR-011 TB 59): eine Kündigung über «Verträge hier kündigen», die Basalto nicht selbst zuordnen
 * konnte — mit allen Angaben und dem Grund. Die Person hat die Eingangsbestätigung schon; versprochen ist das Ende
 * innert zwei Werktagen.
 */
final class CancellationReviewNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly int $cancellationId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cancellation = ContractCancellation::query()->with('site')->findOrFail($this->cancellationId);
        $service = app(ContractCancellations::class);
        $received = $cancellation->received_at->timezone($cancellation->site->timezone ?? 'Europe/Zurich');
        $mail = (new MailMessage)
            ->subject(__('Basalto · Kündigung :number zuordnen', ['number' => $cancellation->number], 'de'))
            ->greeting(__('Guten Tag', [], 'de'))
            ->line(__('Eine Kündigung über «Verträge hier kündigen» braucht Sie: Bestätigen Sie der Person das Ende innert zwei Werktagen.', [], 'de'))
            ->line(__('Eingang: :date um :time Uhr · :number', ['date' => $received->format('d.m.Y'), 'time' => $received->format('H:i'), 'number' => $cancellation->number], 'de'))
            ->line(__('Art: :kind', ['kind' => $cancellation->kind === ContractCancellation::EXTRAORDINARY ? __('Ausserordentliche Kündigung', [], 'de') : __('Ordentliche Kündigung', [], 'de')], 'de'));
        if ($cancellation->reason !== null && $cancellation->reason !== '') {
            $mail->line(__('Grund: :reason', ['reason' => $cancellation->reason], 'de'));
        }
        $mail->line(__('Name: :name', ['name' => $cancellation->name], 'de'))
            ->line(__('E-Mail-Adresse: :email', ['email' => $cancellation->email], 'de'))
            ->line(__('Vertrag: :contract', ['contract' => $service->describe($cancellation, 'de')], 'de'));
        if ($cancellation->reference !== null && $cancellation->reference !== '') {
            $mail->line(__('Kennung: :reference', ['reference' => $cancellation->reference], 'de'));
        }
        $mail->line(__('Gewünschtes Ende: :when', ['when' => $cancellation->wish_date?->format('d.m.Y') ?? __('zum nächstmöglichen Zeitpunkt', [], 'de')], 'de'));
        if ($cancellation->note !== null && $cancellation->note !== '') {
            $mail->line(__('Warum nicht automatisch: :note', ['note' => $cancellation->note], 'de'));
        }
        if (Extranet::enabled()) {
            $mail->action(__('Im Extranet zuordnen', [], 'de'), route('extranet.cancellations.index', ['filter' => 'review']));
        }

        return $mail;
    }
}
