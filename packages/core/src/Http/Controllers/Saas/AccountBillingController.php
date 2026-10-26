<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Help\LegalTexts;
use Basalto\Core\Invoices\Money;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Saas\AccountContext;
use Basalto\Core\Saas\Billing\AccountBilling;
use Basalto\Core\Saas\Billing\BillingException;
use Basalto\Core\Saas\Billing\BillingSettings;
use Basalto\Core\Saas\Billing\CashierModels;
use Basalto\Core\Saas\Billing\Invoicer;
use Basalto\Core\Saas\Billing\SubscriptionGateway;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Support\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Konto › Abo und Rechnungen (TB 12 Scheibe 2, M-21, Mockup 23 `_konto_abo`): Abo mit Plan, Zahlungsart, nächster
 * Rechnung, Rechnungsadresse und MwSt.; die Rechnungen mit Nummer, Betrag, Stand und PDF (mit QR-Zahlteil, solange offen).
 * Darüber «Plan wählen» (Testphase, nichts läuft automatisch an) bzw. «Plan wechseln» und die Zusätze.
 *
 * Sehen dürfen alle Konto-Rollen den Stand; wählen, wechseln, Adresse, Zahlungsart und Rechnungen nur Besitzer und
 * Abrechnung ({@see AccountContext::bills()}) — alles andere ist 403, fremde Rechnungen 404.
 */
final class AccountBillingController
{
    public function __construct(private readonly AccountBilling $billing, private readonly SubscriptionGateway $gateway, private readonly BillingSettings $settings) {}

    public function __invoke(Request $request): Response
    {
        CashierModels::apply();
        $account = AccountContext::from($request);
        $user = $request->user();
        $canBill = $user !== null && AccountContext::bills($user, $account);
        $lang = app()->getLocale();
        $current = Plan::find($account->plan);
        $subscribed = AccountBilling::subscribed($account);
        $pending = AccountBilling::pendingPlan($account);
        $extras = AccountBilling::extras($account);
        $method = AccountBilling::method($account);
        $wanted = $request->query('plan');

        return AccountPage::render($request, 'Billing', 'billing', [
            'subscription' => [
                'active' => $subscribed,
                'method' => $method,
                'method_label' => $method !== null ? Invoicer::methodLabel($method) : null,
                'card' => $method === AccountInvoice::METHOD_STRIPE && $account->pm_last_four !== null
                    ? trim(ucfirst((string) $account->pm_type).' •••• '.$account->pm_last_four) : null,
                'next' => $subscribed ? $this->next($account, $lang) : null,
                'pending' => $pending !== null ? ['label' => $pending->label(), 'from' => AccountBilling::date($account, 'pending_from')?->format('d.m.Y')] : null,
                'extras' => $extras,
                'moving_to' => $account->instance_target,
            ],
            'address' => $this->addressOf($account),
            'vat' => ['rate' => $this->settings->vatRate() > 0 ? Money::rate($this->settings->vatRate(), $lang) : null],
            'plans' => array_map(fn (Plan $plan): array => $plan->toArray() + [
                'current' => $plan->key === $account->plan,
                'action' => $this->action($account, $current, $plan, $subscribed),
                'pending' => $pending?->key === $plan->key,
            ], Plan::purchasable()),
            'selected' => is_string($wanted) && Plan::find($wanted) !== null && $wanted !== Account::PLAN_TRIAL ? $wanted : null,
            'period_end' => $subscribed ? $this->billing->periodEnd($account)->format('d.m.Y') : null,
            'extra' => ['site' => $this->settings->price(BillingSettings::EXTRA_SITE) / 100, 'domain' => $this->settings->price(BillingSettings::EXTRA_DOMAIN) / 100],
            'stripe' => $this->gateway->configured(),
            'payment_days' => $this->settings->paymentDays(),
            'invoices' => $canBill ? $this->invoices($account, $lang) : [],
            'countries' => array_map(static fn (array $c): array => ['code' => $c['code'], 'name' => $c['name'], 'dial' => $c['dial']], Countries::ordered()),
            // CR-011 (TB 58): Haken «sofort beginnen» für Verbraucher in EU und EWR, mit Link auf die Belehrung
            'eu_countries' => Countries::EU_EEA,
            'withdrawal_url' => LegalTexts::publicUrl('widerruf'),
            'returned' => $request->query('stripe') === 'ok',
            'urls' => [
                'choose' => route('basalto.saas.account.billing.choose'),
                'change' => route('basalto.saas.account.billing.change'),
                'extras' => route('basalto.saas.account.billing.extras'),
                'address' => route('basalto.saas.account.billing.address'),
                'method' => route('basalto.saas.account.billing.method'),
                'portal' => route('basalto.saas.account.billing.portal'),
            ],
        ]);
    }

    /** Plan wählen — in der Testphase oder ohne Abo; mit laufendem Abo ist es ein Wechsel. */
    public function choose(Request $request): HttpResponse
    {
        $account = $this->billable($request);
        if (AccountBilling::subscribed($account)) {
            return $this->change($request);
        }
        // CR-011 (TB 58): Verbraucherinnen und Verbraucher in EU und EWR (ohne Firma und ohne UID) verlangen ausdrücklich,
        // dass wir vor Ablauf der Widerrufsfrist beginnen — sonst schulden sie bei einem Widerruf nichts für die Zeit davor
        $consumerInEu = Countries::inEuOrEea((string) $request->input('country')) && trim((string) $request->input('company')) === ''
            && trim((string) $request->input('vat_id')) === '';
        $input = $request->validate([
            'plan' => ['required', 'string', Rule::in($this->planKeys())],
            'method' => ['required', Rule::in([AccountInvoice::METHOD_STRIPE, AccountInvoice::METHOD_INVOICE])],
            'extra_sites' => ['nullable', 'integer', 'min:0', 'max:50'],
            'extra_domains' => ['nullable', 'integer', 'min:0', 'max:50'],
            'immediate_start' => $consumerInEu ? ['accepted'] : ['nullable'],
            ...$this->addressRules(),
        ], ['immediate_start.accepted' => __('Bestätigen Sie, dass wir sofort beginnen sollen — dann zahlen Sie bei einem Widerruf nur den Anteil bis dahin.')]);
        $plan = Plan::find($input['plan']);
        abort_if($plan === null, 422);
        if ($input['method'] === AccountInvoice::METHOD_STRIPE && ! $this->gateway->configured()) {
            throw ValidationException::withMessages(['method' => __('Die Zahlung mit Karte ist noch nicht eingerichtet — bitte die QR-Rechnung wählen.')]);
        }
        $this->billing->update($account, ['immediate_start_at' => $consumerInEu ? CarbonImmutable::now()->toIso8601String() : null]);
        try {
            $url = $this->billing->choose($account, $plan, $input['method'], (int) ($input['extra_sites'] ?? 0), (int) ($input['extra_domains'] ?? 0),
                $this->addressFrom($input), app()->getLocale(), route('basalto.saas.account.billing', ['stripe' => 'ok']), route('basalto.saas.account.billing'));
        } catch (BillingException $e) {
            throw ValidationException::withMessages(['method' => $e->getMessage()]);
        }
        if ($url !== null) {
            return Inertia::location($url);
        }

        $first = AccountBilling::date($account, 'next_invoice_at');
        $issued = $account->accountInvoices()->exists();

        return redirect()->route('basalto.saas.account.billing')->with('status', ! $issued && $first !== null
            ? __('Plan :plan gewählt — die erste QR-Rechnung kommt am :date, zum Ende der Testphase.', ['plan' => $plan->label(), 'date' => $first->format('d.m.Y')])
            : __('Plan :plan gewählt — die QR-Rechnung ist unterwegs und steht unten als PDF.', ['plan' => $plan->label()]));
    }

    /** Plan wechseln: höher sofort, tiefer zum Ende der Periode, derselbe nimmt eine vorgemerkte Herabstufung zurück. */
    public function change(Request $request): RedirectResponse
    {
        $account = $this->billable($request);
        $input = $request->validate(['plan' => ['required', 'string', Rule::in($this->planKeys())]]);
        $plan = Plan::find($input['plan']);
        abort_if($plan === null, 422);
        if (! AccountBilling::subscribed($account)) {
            throw ValidationException::withMessages(['plan' => __('Wählen Sie zuerst einen Plan und die Zahlungsart.')]);
        }
        try {
            $result = $this->billing->change($account, $plan);
        } catch (BillingException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }
        $account->refresh();

        return redirect()->route('basalto.saas.account.billing')->with('status', match ($result) {
            'upgraded' => AccountBilling::method($account) === AccountInvoice::METHOD_STRIPE
                ? __('Auf :plan heraufgestuft — gilt sofort, Stripe verrechnet den Rest der Periode anteilig.', ['plan' => $plan->label()])
                : __('Auf :plan heraufgestuft — gilt sofort, die nächste QR-Rechnung nennt den neuen Preis.', ['plan' => $plan->label()]),
            'scheduled' => __('Wechsel auf :plan ab :date vorgemerkt — bis dahin gilt Ihr bisheriger Plan.', ['plan' => $plan->label(), 'date' => AccountBilling::date($account, 'pending_from')?->format('d.m.Y') ?? '']),
            'reverted' => __('Vorgemerkter Wechsel zurückgenommen — Sie bleiben bei :plan.', ['plan' => $plan->label()]),
            default => __('Sie haben den Plan :plan bereits.', ['plan' => $plan->label()]),
        });
    }

    public function extras(Request $request): RedirectResponse
    {
        $account = $this->billable($request);
        $input = $request->validate(['extra_sites' => ['required', 'integer', 'min:0', 'max:50'], 'extra_domains' => ['required', 'integer', 'min:0', 'max:50']]);
        try {
            if (AccountBilling::subscribed($account)) {
                $this->billing->changeExtras($account, (int) $input['extra_sites'], (int) $input['extra_domains']);
            } else {
                throw ValidationException::withMessages(['extra_sites' => __('Wählen Sie zuerst einen Plan und die Zahlungsart.')]);
            }
        } catch (BillingException $e) {
            throw ValidationException::withMessages(['extra_sites' => $e->getMessage()]);
        }

        return back()->with('status', __('Zusätze gespeichert: :sites Zusatz-Site(s), :domains Zusatzdomain(s).', ['sites' => (int) $input['extra_sites'], 'domains' => (int) $input['extra_domains']]));
    }

    public function address(Request $request): RedirectResponse
    {
        $account = $this->billable($request);
        $input = $request->validate($this->addressRules());
        $this->billing->update($account, ['address' => $this->addressFrom($input)]);

        return back()->with('status', __('Rechnungsadresse gespeichert — sie gilt ab der nächsten Rechnung.'));
    }

    /** Zahlungsart wechseln: zu Stripe über den Checkout, zur QR-Rechnung zum Ende der bezahlten Periode. */
    public function method(Request $request): HttpResponse
    {
        $account = $this->billable($request);
        $input = $request->validate(['method' => ['required', Rule::in([AccountInvoice::METHOD_STRIPE, AccountInvoice::METHOD_INVOICE])]]);
        if ($input['method'] === AccountInvoice::METHOD_STRIPE && ! $this->gateway->configured()) {
            throw ValidationException::withMessages(['method' => __('Die Zahlung mit Karte ist noch nicht eingerichtet — bitte die QR-Rechnung wählen.')]);
        }
        try {
            $url = $this->billing->switchMethod($account, $input['method'], route('basalto.saas.account.billing', ['stripe' => 'ok']), route('basalto.saas.account.billing'));
        } catch (BillingException $e) {
            throw ValidationException::withMessages(['method' => $e->getMessage()]);
        }
        if ($url !== null) {
            return Inertia::location($url);
        }

        return back()->with('status', __('Zahlungsart: QR-Rechnung ab :date — bis dahin gilt die bezahlte Periode.', ['date' => AccountBilling::date($account->refresh(), 'next_invoice_at')?->format('d.m.Y') ?? '']));
    }

    /** Karte ändern: das Kundenportal von Stripe. */
    public function portal(Request $request): HttpResponse
    {
        $account = $this->billable($request);
        abort_unless(AccountBilling::method($account) === AccountInvoice::METHOD_STRIPE, 404);
        try {
            return Inertia::location($this->gateway->portal($account, route('basalto.saas.account.billing')));
        } catch (BillingException $e) {
            return back()->withErrors(['method' => $e->getMessage()]);
        }
    }

    /** Das PDF einer Rechnung des Kontos — offen mit QR-Zahlteil; fremde Rechnungen 404. */
    public function invoice(Request $request, int $invoice, Invoicer $invoicer): HttpResponse
    {
        $account = $this->billable($request);
        $record = $account->accountInvoices()->whereKey($invoice)->first();
        abort_if($record === null, 404);

        return response($invoicer->pdf($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$record->filename().'"',
        ]);
    }

    /** Das Konto der Anfrage — nur für Besitzer und Abrechnung. */
    private function billable(Request $request): Account
    {
        CashierModels::apply();
        $account = AccountContext::from($request);
        $user = $request->user();
        abort_unless($user !== null && AccountContext::bills($user, $account), 403);

        return $account;
    }

    /** @return list<string> */
    private function planKeys(): array
    {
        return array_map(static fn (Plan $plan): string => $plan->key, Plan::purchasable());
    }

    /** @return array<string, list<mixed>> */
    private function addressRules(): array
    {
        return [
            'company' => ['nullable', 'string', 'max:160'],
            'name' => ['required_without:company', 'nullable', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:16'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['required', 'string', Rule::in(Countries::codes())],
            'email' => ['nullable', 'email', 'max:254'],
            'vat_id' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function addressFrom(array $input): array
    {
        $out = [];
        foreach (['company', 'name', 'street', 'postal_code', 'city', 'country', 'email', 'vat_id'] as $key) {
            $value = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
            $out[$key] = $value !== '' ? $value : null;
        }
        $out['country'] = strtoupper($out['country'] ?? 'CH');

        return $out;
    }

    /** @return array<string, string> */
    private function addressOf(Account $account): array
    {
        $address = (array) ($account->billing['address'] ?? []);
        $out = [];
        foreach (['company', 'name', 'street', 'postal_code', 'city', 'country', 'email', 'vat_id'] as $key) {
            $out[$key] = is_scalar($address[$key] ?? null) ? (string) $address[$key] : '';
        }
        $out['country'] = $out['country'] !== '' ? $out['country'] : 'CH';

        return $out;
    }

    /** @return 'current'|'upgrade'|'downgrade'|'choose' */
    private function action(Account $account, ?Plan $current, Plan $plan, bool $subscribed): string
    {
        if (! $subscribed || $current === null || $current->isTrial()) {
            return 'choose';
        }
        if ($plan->key === $current->key) {
            return 'current';
        }

        return $plan->price > $current->price ? 'upgrade' : 'downgrade';
    }

    /** @return array{date: string, amount: string}|null Nächste Rechnung: Datum und Betrag aus Plan (bzw. vorgemerktem Plan) und Zusätzen. */
    private function next(Account $account, string $lang): ?array
    {
        $date = $this->billing->periodEnd($account);
        $plan = AccountBilling::pendingPlan($account) ?? Plan::find($account->plan);
        if ($plan === null) {
            return null;
        }
        $extras = AccountBilling::extras($account);
        $total = $this->settings->price($plan->key) + $extras['sites'] * $this->settings->price(BillingSettings::EXTRA_SITE) + $extras['domains'] * $this->settings->price(BillingSettings::EXTRA_DOMAIN);

        return ['date' => $date->format('d.m.Y'), 'amount' => Money::format($total, BillingSettings::CURRENCY, $lang)];
    }

    /** @return list<array<string, mixed>> */
    private function invoices(Account $account, string $lang): array
    {
        return array_values($account->accountInvoices()->orderByDesc('issued_at')->orderByDesc('id')->limit(36)->get()->map(static fn (AccountInvoice $invoice): array => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'date' => $invoice->issued_at->format('d.m.Y'),
            'text' => Plan::find($invoice->plan)?->label() ?? $invoice->plan,
            'period' => $invoice->period_start !== null && $invoice->period_end !== null ? $invoice->period_start->format('d.m.').' – '.$invoice->period_end->format('d.m.Y') : null,
            'amount' => Money::format($invoice->total, $invoice->currency, $lang),
            'status' => $invoice->status,
            'overdue' => $invoice->overdue(),
            'due' => $invoice->due_at?->format('d.m.Y'),
            'method' => $invoice->method,
            'qr' => $invoice->isOpen() && $invoice->method === AccountInvoice::METHOD_INVOICE,
            'url' => route('basalto.saas.account.billing.invoice', $invoice->id),
        ])->all());
    }
}
