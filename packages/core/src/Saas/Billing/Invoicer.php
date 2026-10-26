<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Invoices\InvoiceSequence;
use Basalto\Core\Invoices\InvoiceSheet;
use Basalto\Core\Invoices\Money;
use Basalto\Core\Invoices\PdfRenderer;
use Basalto\Core\Invoices\SwissQrBill;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Saas\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

/**
 * Rechnungen an Konten (TB 12 Scheibe 2, M-21): QR-Rechnungen je Periode (offen, mit QR-Zahlteil) und die bezahlten
 * Rechnungen eines Stripe-Abos (aus `invoice.paid`). Nummern fortlaufend je Jahr und Instanz (S-2026-0001), Positionen
 * in der Sprache des Kontos, MwSt. aus dem Bruttobetrag, Adresse als Momentaufnahme. Jede Rechnung entsteht genau einmal
 * (`source`), das PDF bei Bedarf über den Rechnungs-Bausatz des Kerns — derselbe wie im Lizenzverkauf.
 */
final class Invoicer
{
    public function __construct(
        private readonly BillingSettings $settings,
        private readonly SwissQrBill $qr,
        private readonly InvoiceSheet $sheet,
        private readonly PdfRenderer $pdf,
    ) {}

    /** Die QR-Rechnung für die Periode ab `$start` (ein Monat) — gibt es sie schon, diese. */
    public function issueQr(Account $account, CarbonImmutable $start): AccountInvoice
    {
        $source = 'qr:'.$account->id.':'.$start->toDateString();
        $existing = AccountInvoice::query()->where('source', $source)->first();
        if ($existing !== null) {
            return $existing;
        }
        $plan = Plan::find($account->plan);
        abort_if($plan === null || $plan->isTrial(), 409);
        $end = $start->addMonthNoOverflow()->subDay();
        $lang = $this->locale($account);
        $extras = AccountBilling::extras($account);
        $lines = [['key' => $plan->key, 'text' => __('Basalto :plan · monatlich', ['plan' => $plan->label()], $lang), 'detail' => null,
            'quantity' => 1, 'amount' => $this->settings->price($plan->key)]];
        foreach ([BillingSettings::EXTRA_SITE => $extras['sites'], BillingSettings::EXTRA_DOMAIN => $extras['domains']] as $key => $quantity) {
            if ($quantity > 0) {
                // Eine Zeile je Position — über dem Zahlteil (105 mm) ist der Platz knapp
                $lines[] = ['key' => $key, 'text' => $this->extraLabel($key, $quantity, $lang).' · '.InvoiceSheet::text(__('je :price pro Monat', ['price' => Money::format($this->settings->price($key), BillingSettings::CURRENCY, $lang)], $lang)),
                    'detail' => null, 'quantity' => $quantity, 'amount' => $quantity * $this->settings->price($key)];
            }
        }
        $today = CarbonImmutable::today();

        return $this->create($account, $source, AccountInvoice::METHOD_INVOICE, $lines, array_sum(array_column($lines, 'amount')), [
            'status' => AccountInvoice::STATUS_OPEN, 'period_start' => $start, 'period_end' => $end, 'issued_at' => $today, 'due_at' => $today->addDays($this->settings->paymentDays()),
        ]);
    }

    /**
     * Die bezahlte Rechnung zu einer Rechnung von Stripe (`invoice.paid`) — null bei CHF 0 (Testphase im Abo) oder wenn
     * es sie schon gibt. Positionen aus den Zeilen von Stripe (Preis-ID → Plan oder Zusatz, anteilige Zeilen als solche).
     *
     * @param  array<string, mixed>  $invoice
     */
    public function recordStripe(Account $account, array $invoice): ?AccountInvoice
    {
        $id = $invoice['id'] ?? null;
        $paid = (int) ($invoice['amount_paid'] ?? 0);
        if (! is_string($id) || $paid <= 0) {
            return null;
        }
        $source = 'stripe:'.$id;
        if (AccountInvoice::query()->where('source', $source)->exists()) {
            return null;
        }
        $lang = $this->locale($account);
        $lines = [];
        $start = $end = null;
        foreach ((array) ($invoice['lines']['data'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $price = $line['price']['id'] ?? $line['pricing']['price_details']['price'] ?? null;
            $key = $this->settings->keyOfStripePrice(is_string($price) ? $price : null);
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $prorated = (bool) ($line['proration'] ?? $line['parent']['subscription_item_details']['proration'] ?? false);
            $from = isset($line['period']['start']) ? CarbonImmutable::createFromTimestamp((int) $line['period']['start'], 'Europe/Zurich') : null;
            $to = isset($line['period']['end']) ? CarbonImmutable::createFromTimestamp((int) $line['period']['end'], 'Europe/Zurich')->subDay() : null;
            $start ??= $from;
            $end = $to ?? $end;
            $text = match (true) {
                $key === BillingSettings::EXTRA_SITE, $key === BillingSettings::EXTRA_DOMAIN => $this->extraLabel($key, $quantity, $lang),
                $key !== null && ($plan = Plan::find($key)) !== null => __('Basalto :plan · monatlich', ['plan' => $plan->label()], $lang),
                default => (string) ($line['description'] ?? __('Position', [], $lang)),
            };
            $lines[] = ['key' => $key ?? 'other', 'text' => $prorated ? __('Anteilig: :text', ['text' => $text], $lang) : $text,
                'detail' => $from !== null && $to !== null ? $this->period($from, $to, $lang) : null, 'quantity' => $quantity, 'amount' => (int) ($line['amount'] ?? 0)];
        }
        $difference = $paid - array_sum(array_column($lines, 'amount'));
        if ($lines === [] || $difference !== 0) {
            // Guthaben, Rabatt oder Zeilen ohne Betrag: die Rechnung nennt, was bezahlt wurde
            $lines[] = ['key' => 'other', 'text' => $lines === [] ? __('Basalto · Abo', [], $lang) : __('Verrechnet (Guthaben, Rabatt)', [], $lang), 'detail' => null, 'quantity' => 1, 'amount' => $difference];
        }
        $paidAt = $invoice['status_transitions']['paid_at'] ?? null;

        return $this->create($account, $source, AccountInvoice::METHOD_STRIPE, $lines, $paid, [
            'status' => AccountInvoice::STATUS_PAID, 'period_start' => $start, 'period_end' => $end, 'issued_at' => CarbonImmutable::today(), 'due_at' => null,
            'paid_at' => is_numeric($paidAt) ? CarbonImmutable::createFromTimestamp((int) $paidAt) : now(), 'stripe_invoice_id' => $id,
        ]);
    }

    /** Das Blatt der Rechnung (HTML für Dompdf) in der Sprache der Rechnung. */
    public function html(AccountInvoice $invoice): string
    {
        $lang = $invoice->locale;
        $billing = $invoice->billing;
        $money = static fn (int $cents): string => Money::format($cents, $invoice->currency, $lang);
        $vatId = trim((string) ($billing['vat_id'] ?? ''));
        $country = strtoupper((string) ($billing['country'] ?? 'CH'));
        $debtor = array_values(array_filter([$billing['company'] ?? null, $billing['name'] ?? null, $billing['street'] ?? null,
            trim(($billing['postal_code'] ?? '').' '.($billing['city'] ?? '')), $country !== '' && $country !== 'CH' ? $country : null,
            $vatId !== '' ? InvoiceSheet::text(__('UID :number', ['number' => $vatId], $lang)) : null],
            static fn (mixed $v): bool => is_string($v) && trim($v) !== ''));
        $open = $invoice->isOpen();
        $byQr = $invoice->method === AccountInvoice::METHOD_INVOICE;

        $meta = [[InvoiceSheet::text(__('Datum', [], $lang)), $invoice->issued_at->format('d.m.Y')]];
        if ($invoice->due_at !== null && $open) {
            $meta[] = [InvoiceSheet::text(__('Zahlbar bis', [], $lang)), $invoice->due_at->format('d.m.Y')];
        }
        if ($invoice->period_start !== null && $invoice->period_end !== null) {
            $meta[] = [InvoiceSheet::text(__('Periode', [], $lang)), $invoice->period_start->format('d.m.Y').' – '.$invoice->period_end->format('d.m.Y')];
        }

        $stamp = match ($invoice->status) {
            AccountInvoice::STATUS_PAID => ['text' => InvoiceSheet::text(__('Bezahlt am :date · :method', ['date' => $invoice->paid_at?->format('d.m.Y') ?? '', 'method' => self::methodLabel($invoice->method, $lang)], $lang)), 'cancelled' => false],
            AccountInvoice::STATUS_CANCELLED => ['text' => InvoiceSheet::text(__('Storniert', [], $lang)), 'cancelled' => true],
            default => null,
        };
        $qr = $open && $byQr ? $this->qr->html($this->qr->bill($this->settings->creditor(), $invoice->currency, $invoice->total, $invoice->payment_reference, $invoice->number, $billing), $lang) : null;

        $totals = InvoiceSheet::totals($invoice->net_amount, $invoice->vat_rate, $invoice->vat_amount, $invoice->total, $invoice->currency, $lang);
        $note = $open && $byQr ? InvoiceSheet::text(__('Bitte bezahlen Sie mit dem QR-Zahlteil unten — eine offene Rechnung sperrt nichts.', [], $lang)) : null;
        if ($note !== null && $totals['vat_note'] !== null) {
            // Ein Absatz statt zwei — über dem Zahlteil (105 mm) ist der Platz knapp
            [$note, $totals['vat_note']] = [$totals['vat_note'].' '.$note, null];
        }

        return $this->sheet->html([
            'lang' => $lang,
            'title' => InvoiceSheet::text(__('Rechnung :number', ['number' => $invoice->number], $lang)),
            'creditor' => $this->settings->creditor()->lines(),
            'vat_number' => $this->settings->vatNumber(),
            'debtor' => $debtor,
            'meta' => $meta,
            'lines' => array_map(static fn (array $line): array => ['text' => $line['quantity'] > 1 && ! str_starts_with($line['key'], 'extra_') ? $line['quantity'].' × '.$line['text'] : $line['text'],
                'detail' => $line['detail'], 'amount' => $money($line['amount'])], $invoice->lines),
            'labels' => ['position' => InvoiceSheet::text(__('Position', [], $lang)), 'amount' => InvoiceSheet::text(__('Betrag', [], $lang))],
            ...$totals,
            'stamp' => $stamp,
            'note' => $note,
            'qr' => $qr,
            'qr_missing' => $qr === null && $open && $byQr ? InvoiceSheet::text(__('QR-Zahlteil folgt: Die Zahlungsangaben fehlen noch. Bitte warten Sie auf die vollständige Rechnung.', [], $lang)) : null,
        ]);
    }

    public function pdf(AccountInvoice $invoice): string
    {
        return $this->pdf->render($this->html($invoice));
    }

    /** Eine Mail an Besitzer und Abrechnung des Kontos — jede Person in ihrer Sprache (HasLocalePreference). */
    public function notify(Account $account, Notification $notification): void
    {
        $recipients = $account->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Billing->value])->get();
        foreach ($recipients as $user) {
            if ($user->getAttribute('deactivated_at') === null && method_exists($user, 'notify')) {
                $user->notify($notification);
            }
        }
    }

    public static function methodLabel(string $method, ?string $lang = null): string
    {
        return $method === AccountInvoice::METHOD_STRIPE ? __('Stripe (Karte)', [], $lang) : __('QR-Rechnung', [], $lang);
    }

    private function extraLabel(string $key, int $quantity, string $lang): string
    {
        return $key === BillingSettings::EXTRA_SITE
            ? trans_choice(':count Zusatz-Site|:count Zusatz-Sites', $quantity, [], $lang)
            : trans_choice(':count Zusatzdomain|:count Zusatzdomains', $quantity, [], $lang);
    }

    private function period(CarbonImmutable $from, CarbonImmutable $to, string $lang): string
    {
        return __('Periode :from – :to', ['from' => $from->format('d.m.Y'), 'to' => $to->format('d.m.Y')], $lang);
    }

    private function locale(Account $account): string
    {
        $locale = $account->billing['locale'] ?? null;

        return is_string($locale) && in_array($locale, ['de', 'en', 'it', 'fr'], true) ? $locale : 'de';
    }

    /**
     * @param  list<array{key: string, text: string, detail: string|null, quantity: int, amount: int}>  $lines
     * @param  array<string, mixed>  $values
     */
    private function create(Account $account, string $source, string $method, array $lines, int $total, array $values): AccountInvoice
    {
        $year = (int) CarbonImmutable::today()->year;
        $prefix = $this->settings->invoicePrefix();
        $rate = $this->settings->vatRate();
        $vat = Money::includedVat($total, $rate);
        $address = (array) ($account->billing['address'] ?? []);
        $billing = ['account' => $account->name] + array_map(static fn (mixed $v): ?string => is_scalar($v) ? (string) $v : null,
            array_intersect_key($address, array_flip(['company', 'name', 'street', 'postal_code', 'city', 'country', 'email', 'vat_id'])));
        if (($billing['email'] ?? '') === '') {
            $billing['email'] = $account->stripeEmail();
        }

        return InvoiceSequence::issue(
            static fn () => AccountInvoice::query()->where('year', $year),
            fn (int $sequence): AccountInvoice => AccountInvoice::query()->create($values + [
                'account_id' => $account->id, 'number' => sprintf('%s-%d-%04d', $prefix, $year, $sequence), 'year' => $year, 'sequence' => $sequence,
                'source' => $source, 'method' => $method, 'plan' => $account->plan, 'currency' => BillingSettings::CURRENCY, 'lines' => $lines, 'billing' => $billing,
                'locale' => $this->locale($account), 'net_amount' => $total - $vat, 'vat_rate' => $rate, 'vat_amount' => $vat, 'total' => $total,
                'payment_reference' => $method === AccountInvoice::METHOD_INVOICE
                    ? $this->qr->reference($this->settings->creditor(), $year, $sequence, $prefix, $this->settings->referencePrefix()) : null,
            ]),
        );
    }
}
