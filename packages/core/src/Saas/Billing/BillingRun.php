<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Models\AccountNotice;
use Basalto\Core\Saas\Notifications\InvoiceReminderNotification;
use Carbon\CarbonImmutable;

/**
 * Der Lauf der Abrechnung (TB 12 Scheibe 2, `basalto:saas-billing`, stündlich, idempotent): fällige Herabstufungen
 * übernehmen, fällige QR-Rechnungen ausstellen, offene QR-Rechnungen nach Ablauf der Frist **genau einmal** erinnern
 * ({@see AccountNotice}, Bezug = Rechnungsnummer). Konten in der Testphase und ohne Abo berührt er nicht — nichts läuft
 * automatisch an —, gekündigte, umziehende und umgezogene Konten bekommen keine neuen Rechnungen (Scheibe 4). Gesperrt
 * wird nie.
 */
final class BillingRun
{
    public const REMINDER = 'invoice.reminder';

    public function __construct(private readonly AccountBilling $billing, private readonly Invoicer $invoicer) {}

    /** @return array{plans: int, invoices: int, reminders: int} */
    public function run(): array
    {
        $done = ['plans' => 0, 'invoices' => 0, 'reminders' => 0];
        // Nur Konten, die hier aktiv sind — gekündigte, gesperrte, umziehende und umgezogene nicht (Scheibe 4)
        $accounts = Account::query()->whereNull('cancelled_at')->whereNotIn('plan', [Account::PLAN_TRIAL, Account::PLAN_DOWNLOAD])
            ->where(static fn ($query) => $query->whereNull('status')->orWhere('status', 'active'))->orderBy('id');
        foreach ($accounts->lazyById(100) as $account) {
            if (! AccountBilling::subscribed($account)) {
                continue;
            }
            $done['plans'] += $this->billing->applyPending($account) ? 1 : 0;
            // Bestellte Zusatzdomains (Scheibe 3), die beim Bestellen nicht gebucht werden konnten
            try {
                $this->billing->bookExtraDomainOrders($account);
            } catch (BillingException $e) {
                report($e);
            }
            $done['invoices'] += count($this->billing->invoiceDue($account));
        }

        $overdue = AccountInvoice::query()->where('status', AccountInvoice::STATUS_OPEN)->where('method', AccountInvoice::METHOD_INVOICE)
            ->whereNotNull('account_id')->where('due_at', '<', CarbonImmutable::today()->toDateString())->with('account')->orderBy('id');
        foreach ($overdue->lazyById(100) as $invoice) {
            $account = $invoice->account;
            if ($account === null) {
                continue;
            }
            $done['reminders'] += AccountNotice::once($account, self::REMINDER, $invoice->number,
                fn () => $this->invoicer->notify($account, new InvoiceReminderNotification($invoice->id))) ? 1 : 0;
        }

        return $done;
    }
}
