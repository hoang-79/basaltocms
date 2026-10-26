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

/** Erinnerung an eine offene QR-Rechnung nach Ablauf der Frist (TB 12 Scheibe 2) — genau einmal, keine Sperre. */
final class InvoiceReminderNotification extends Notification
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

        return ['title' => 'Erinnerung: Rechnung :number ist noch offen', 'params' => ['number' => (string) $invoice?->number], 'url' => route('basalto.saas.account.billing')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = AccountInvoice::query()->findOrFail($this->invoiceId);

        return (new MailMessage)
            ->subject(__('Basalto · Erinnerung: Rechnung :number', ['number' => $invoice->number]))
            ->greeting(__('Guten Tag'))
            ->line(__('Die Rechnung :number über :amount vom :date ist noch offen. Bitte bezahlen Sie sie mit dem QR-Zahlteil im beiliegenden PDF.', [
                'number' => $invoice->number, 'amount' => Money::format($invoice->total, $invoice->currency, app()->getLocale()), 'date' => $invoice->issued_at->format('d.m.Y'),
            ]))
            ->line(__('Haben Sie schon bezahlt? Dann ist diese Mail hinfällig. Gesperrt wird nichts — Ihre Sites bleiben erreichbar.'))
            ->action(__('Abo und Rechnungen'), route('basalto.saas.account.billing'))
            ->attachData(app(Invoicer::class)->pdf($invoice), $invoice->filename(), ['mime' => 'application/pdf']);
    }
}
