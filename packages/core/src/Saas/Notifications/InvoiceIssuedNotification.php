<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Notifications;

use Basalto\Core\Invoices\Money;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Notifications\AlsoInBell;
use Basalto\Core\Saas\Billing\Invoicer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Eine Rechnung des Abos (TB 12 Scheibe 2) an Besitzer und Abrechnung — der Text in der Sprache der Person, das PDF in
 * der Sprache der Rechnung: offen mit QR-Zahlteil oder bezahlt (Stripe) als Beleg.
 */
final class InvoiceIssuedNotification extends Notification
{
    use AlsoInBell;
    use Queueable;

    public function __construct(public readonly int $invoiceId) {}

    protected function bellKind(): string
    {
        return 'account.billing';
    }

    /** @return array{title: string, params?: array<string, string|int>, body?: string|null, body_params?: array<string, string|int>, url?: string|null} */
    protected function bell(): array
    {
        $invoice = AccountInvoice::query()->find($this->invoiceId);

        return ['title' => 'Rechnung :number ausgestellt', 'params' => ['number' => (string) $invoice?->number],
            'body' => $invoice === null ? null : 'Über :amount für das Konto «:account».', 'body_params' => $invoice === null ? [] : ['amount' => Money::format($invoice->total, $invoice->currency, app()->getLocale()), 'account' => (string) ($invoice->billing['account'] ?? '')],
            'url' => route('basalto.saas.account.billing')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = AccountInvoice::query()->findOrFail($this->invoiceId);
        $amount = Money::format($invoice->total, $invoice->currency, app()->getLocale());
        $mail = (new MailMessage)
            ->subject(__('Basalto · Rechnung :number', ['number' => $invoice->number]))
            ->greeting(__('Guten Tag'));
        if ($invoice->isOpen()) {
            $mail->line(__('Die Rechnung :number über :amount für das Konto «:account» liegt als PDF mit QR-Zahlteil bei — zahlbar bis :date.', [
                'number' => $invoice->number, 'amount' => $amount, 'account' => (string) ($invoice->billing['account'] ?? ''), 'date' => $invoice->due_at?->format('d.m.Y') ?? '',
            ]))->line(__('Eine offene Rechnung sperrt nichts. Ihre Sites bleiben erreichbar.'));
        } else {
            $mail->line(__('Danke — die Rechnung :number über :amount für das Konto «:account» ist bezahlt. Der Beleg liegt als PDF bei.', [
                'number' => $invoice->number, 'amount' => $amount, 'account' => (string) ($invoice->billing['account'] ?? ''),
            ]));
        }

        return $mail->action(__('Abo und Rechnungen'), route('basalto.saas.account.billing'))
            ->attachData(app(Invoicer::class)->pdf($invoice), $invoice->filename(), ['mime' => 'application/pdf']);
    }
}
