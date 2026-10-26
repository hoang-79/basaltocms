<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Models\AccountNotice;
use Basalto\Core\Saas\Events\InstanceMoveRequested;
use Basalto\Core\Saas\Notifications\InvoiceIssuedNotification;
use Basalto\Core\Saas\Notifications\SubscriptionConfirmedNotification;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\Trial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Abo eines Kontos (TB 12 Scheibe 2, M-21) — Plan wählen, wechseln, Zusätze, Zahlungsart. Der Stand liegt in
 * `accounts.billing` (JSON):
 *
 * - `method` `stripe` oder `invoice` (null = noch kein Abo), `locale` (Sprache der Rechnungen), `address` (Firma, Name,
 *   Strasse, PLZ, Ort, Land, E-Mail, UID), `extra_sites`, `extra_domains` (Mengen, CHF 15 bzw. 5 je Monat);
 * - QR-Rechnung: `next_invoice_at` (Beginn der nächsten Periode, monatlich ab dem Tag der Wahl — bzw. ab dem Ende der
 *   Testphase); Stripe: `period_end` (aus den Webhooks);
 * - `pending_plan` und `pending_from`: vorgemerkte Herabstufung zum Ende der Periode;
 * - `paid_until`: Ende der laufenden Periode für die Kündigung (Scheibe 4), aus den beiden Daten oben nachgeführt.
 *
 * Regeln (Mockup 23): **Nichts läuft automatisch an** — ohne Wahl bleibt die Testphase, es entsteht nichts. Gewählt in
 * der Testphase, gilt der Plan sofort, bezahlt wird ab ihrem Ende. **Heraufstufen** sofort (Stripe anteilig, QR ab der
 * nächsten Rechnung), **Herabstufen** zum Ende der Periode. Ein Plan auf einer anderen Instanz setzt `instance_target`
 * und meldet {@see InstanceMoveRequested} — umgezogen wird in Scheibe 4.
 */
final class AccountBilling
{
    public function __construct(private readonly SubscriptionGateway $gateway, private readonly Invoicer $invoicer) {}

    /** Hat das Konto ein Abo (Plan gewählt, Zahlungsart gesetzt)? */
    public static function subscribed(Account $account): bool
    {
        $plan = Plan::find($account->plan);

        return $plan !== null && ! $plan->isTrial() && self::method($account) !== null;
    }

    public static function method(Account $account): ?string
    {
        $method = $account->billing['method'] ?? null;

        return in_array($method, [AccountInvoice::METHOD_STRIPE, AccountInvoice::METHOD_INVOICE], true) ? $method : null;
    }

    /** @return array{sites: int, domains: int} */
    public static function extras(Account $account): array
    {
        return ['sites' => max(0, (int) ($account->billing['extra_sites'] ?? 0)), 'domains' => max(0, (int) ($account->billing['extra_domains'] ?? 0))];
    }

    public static function pendingPlan(Account $account): ?Plan
    {
        $key = $account->billing['pending_plan'] ?? null;

        return is_string($key) ? Plan::find($key) : null;
    }

    public static function date(Account $account, string $key): ?CarbonImmutable
    {
        $value = $account->billing[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->startOfDay() : null;
    }

    /** Ab wann bezahlt wird: Ende der laufenden Testphase, sonst heute. */
    public static function billingStart(Account $account): CarbonImmutable
    {
        $end = $account->trial_ends_at;

        return Trial::inTrial($account) && $end !== null && $end->isFuture() ? $end->timezone('Europe/Zurich')->startOfDay() : CarbonImmutable::today();
    }

    /**
     * Plan wählen (Testphase oder ohne Abo). QR-Rechnung: der Plan gilt sofort, die erste Rechnung kommt heute bzw. am
     * Ende der Testphase. Stripe: Adresse des Checkouts — der Plan gilt erst, wenn der Webhook die Zahlung meldet.
     *
     * @param  array<string, string|null>  $address
     *
     * @throws BillingException
     */
    public function choose(Account $account, Plan $plan, string $method, int $extraSites, int $extraDomains, array $address, string $locale, string $successUrl, string $cancelUrl): ?string
    {
        $this->update($account, ['address' => $address, 'locale' => $locale]);
        if ($method === AccountInvoice::METHOD_STRIPE) {
            $start = self::billingStart($account);

            return $this->gateway->checkout($account, $plan, $extraSites, $extraDomains, $start->isFuture() ? $start : null, $successUrl, $cancelUrl);
        }
        $start = self::billingStart($account);
        DB::transaction(function () use ($account, $plan, $extraSites, $extraDomains, $start): void {
            $this->update($account, ['method' => AccountInvoice::METHOD_INVOICE, 'extra_sites' => $extraSites, 'extra_domains' => $extraDomains,
                'next_invoice_at' => $start->toDateString(), 'pending_plan' => null, 'pending_from' => null, 'started_at' => CarbonImmutable::today()->toDateString()]);
            $this->setPlan($account, $plan);
        });
        $this->invoiceDue($account);
        $this->confirm($account, $plan);

        return null;
    }

    /**
     * Stripe hat den Checkout bestätigt (Webhook): Plan, Zusätze und Zahlungsart übernehmen. Eine laufende QR-Rechnung
     * endet damit (keine weiteren QR-Rechnungen).
     */
    public function activateStripe(Account $account, Plan $plan, int $extraSites, int $extraDomains): void
    {
        DB::transaction(function () use ($account, $plan, $extraSites, $extraDomains): void {
            $this->update($account, ['method' => AccountInvoice::METHOD_STRIPE, 'extra_sites' => $extraSites, 'extra_domains' => $extraDomains,
                'next_invoice_at' => null, 'pending_plan' => null, 'pending_from' => null, 'started_at' => $account->billing['started_at'] ?? CarbonImmutable::today()->toDateString()]);
            $this->setPlan($account, $plan);
        });
        $this->confirm($account, $plan);
    }

    /**
     * Bestätigung des Abos an Besitzer und Abrechnung (CR-011, TB 58) — je Abschluss einmal, auch wenn Stripe den
     * Webhook wiederholt.
     */
    private function confirm(Account $account, Plan $plan): void
    {
        $reference = $plan->key.'|'.($account->billing['started_at'] ?? CarbonImmutable::today()->toDateString());
        AccountNotice::once($account, 'subscription_confirmed', $reference, static function () use ($account, $plan): void {
            foreach ($account->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Billing->value])->get() as $user) {
                if ($user->getAttribute('deactivated_at') === null && method_exists($user, 'notify')) {
                    $user->notify(new SubscriptionConfirmedNotification($account->id, $plan->key));
                }
            }
        });
    }

    /**
     * Den Plan eines laufenden Abos wechseln: höher sofort (Stripe anteilig mit Rechnung, QR ab der nächsten Rechnung),
     * tiefer zum Ende der Periode; derselbe Plan nimmt eine vorgemerkte Herabstufung zurück.
     *
     * @return 'upgraded'|'scheduled'|'reverted'|'unchanged'
     *
     * @throws BillingException
     */
    public function change(Account $account, Plan $plan): string
    {
        $current = Plan::find($account->plan);
        $pending = self::pendingPlan($account);
        $extras = self::extras($account);
        $stripe = self::method($account) === AccountInvoice::METHOD_STRIPE;
        if ($current === null || $plan->key === $current->key) {
            if ($pending === null || $current === null) {
                return 'unchanged';
            }
            if ($stripe) {
                $this->gateway->change($account, $current, $extras['sites'], $extras['domains'], false);
            }
            $this->update($account, ['pending_plan' => null, 'pending_from' => null]);

            return 'reverted';
        }
        if ($plan->price > $current->price) {
            if ($stripe) {
                $this->gateway->change($account, $plan, $extras['sites'], $extras['domains'], true);
            }
            DB::transaction(function () use ($account, $plan): void {
                $this->update($account, ['pending_plan' => null, 'pending_from' => null]);
                $this->setPlan($account, $plan);
            });

            return 'upgraded';
        }
        if ($stripe) {
            // Ab der nächsten Periode zum tieferen Preis, ohne Gutschrift — die bezahlte Periode gilt bis zum Ende
            $this->gateway->change($account, $plan, $extras['sites'], $extras['domains'], false);
        }
        $this->update($account, ['pending_plan' => $plan->key, 'pending_from' => $this->periodEnd($account)->toDateString()]);

        return 'scheduled';
    }

    /**
     * Mengen der Zusatz-Sites und Zusatzdomains setzen — sofort im Kontingent; Stripe anteilig, QR ab der nächsten
     * Rechnung. Auch für Scheibe 3 («Domain hinzufügen»).
     *
     * @throws BillingException
     */
    public function changeExtras(Account $account, int $extraSites, int $extraDomains): void
    {
        $extraSites = max(0, $extraSites);
        $extraDomains = max(0, $extraDomains);
        if (self::method($account) === AccountInvoice::METHOD_STRIPE && ($plan = self::pendingPlan($account) ?? Plan::find($account->plan)) !== null) {
            $this->gateway->change($account, $plan, $extraSites, $extraDomains, true);
        }
        $this->update($account, ['extra_sites' => $extraSites, 'extra_domains' => $extraDomains]);
    }

    /**
     * Bestellte Zusatzdomains buchen (Scheibe 3 → 2): `billing.extra_domain_orders` in die Menge `extra_domains` übernehmen
     * — mit Stripe anteilig im Abo — und die Liste leeren. Ohne Abo (Testphase) bleiben die Bestellungen stehen, bis ein
     * Plan gewählt ist; scheitert Stripe, versucht es der stündliche Lauf wieder.
     *
     * @throws BillingException
     */
    public function bookExtraDomainOrders(Account $account): int
    {
        $account->refresh();
        $orders = $account->billing['extra_domain_orders'] ?? [];
        $count = is_array($orders) ? count(array_filter($orders, 'is_array')) : 0;
        if ($count === 0 || ! self::subscribed($account)) {
            return 0;
        }
        $extras = self::extras($account);
        $domains = $extras['domains'] + $count;
        if (self::method($account) === AccountInvoice::METHOD_STRIPE && ($plan = self::pendingPlan($account) ?? Plan::find($account->plan)) !== null) {
            $this->gateway->change($account, $plan, $extras['sites'], $domains, true);
        }
        $this->update($account, ['extra_domains' => $domains, 'extra_domain_orders' => null]);

        return $count;
    }

    /**
     * Zahlungsart wechseln. Zur QR-Rechnung: das Stripe-Abo endet mit der bezahlten Periode, danach kommen QR-Rechnungen.
     * Zu Stripe: Checkout, bezahlt wird ab dem Ende der laufenden QR-Periode (keine doppelte Rechnung).
     *
     * @throws BillingException
     */
    public function switchMethod(Account $account, string $method, string $successUrl, string $cancelUrl): ?string
    {
        $current = self::method($account);
        $plan = self::pendingPlan($account) ?? Plan::find($account->plan);
        if ($current === null || $current === $method || $plan === null) {
            return null;
        }
        $extras = self::extras($account);
        if ($method === AccountInvoice::METHOD_STRIPE) {
            $next = self::date($account, 'next_invoice_at');

            return $this->gateway->checkout($account, $plan, $extras['sites'], $extras['domains'], $next !== null && $next->isFuture() ? $next : null, $successUrl, $cancelUrl);
        }
        $this->gateway->stop($account);
        $this->update($account, ['method' => AccountInvoice::METHOD_INVOICE, 'next_invoice_at' => $this->periodEnd($account)->toDateString()]);

        return null;
    }

    /** Fällige Herabstufung übernehmen (Scheduler); true, wenn sich der Plan geändert hat. */
    public function applyPending(Account $account): bool
    {
        $plan = self::pendingPlan($account);
        $from = self::date($account, 'pending_from');
        if ($plan === null || $from === null || $from->isFuture()) {
            return false;
        }
        DB::transaction(function () use ($account, $plan): void {
            $this->update($account, ['pending_plan' => null, 'pending_from' => null]);
            $this->setPlan($account, $plan);
        });

        return true;
    }

    /**
     * Fällige QR-Rechnungen ausstellen — je verstrichene Periode genau eine (auch nach einem Ausfall des Schedulers), mit
     * Mail an Besitzer und Abrechnung.
     *
     * @return list<AccountInvoice>
     */
    public function invoiceDue(Account $account): array
    {
        $issued = [];
        while (self::method($account) === AccountInvoice::METHOD_INVOICE && ($next = self::date($account, 'next_invoice_at')) !== null && ! $next->isFuture()) {
            $this->applyPending($account);
            $invoice = $this->invoicer->issueQr($account, $next);
            $this->update($account, ['next_invoice_at' => $next->addMonthNoOverflow()->toDateString()]);
            if ($invoice->wasRecentlyCreated) {
                $this->invoicer->notify($account, new InvoiceIssuedNotification($invoice->id));
                $issued[] = $invoice;
            }
        }

        return $issued;
    }

    /** Ende der bezahlten Periode: Stripe aus dem Webhook, QR der Beginn der nächsten Periode, sonst in einem Monat. */
    public function periodEnd(Account $account): CarbonImmutable
    {
        $key = self::method($account) === AccountInvoice::METHOD_STRIPE ? 'period_end' : 'next_invoice_at';

        return self::date($account, $key) ?? CarbonImmutable::today()->addMonthNoOverflow();
    }

    /** Plan setzen; liegt er auf einer anderen Instanz, das Ziel für den Umzug (Scheibe 4) setzen und melden. */
    public function setPlan(Account $account, Plan $plan): void
    {
        $here = $account->instance ?? Saas::instance();
        $target = $plan->instance !== $here ? $plan->instance : null;
        $moving = $target !== null && $target !== $account->instance_target;
        $account->forceFill(['plan' => $plan->key, 'instance_target' => $target])->save();
        if ($moving) {
            InstanceMoveRequested::dispatch($account, $here, $target);
        }
    }

    /**
     * Werte in `billing` setzen (null entfernt den Schlüssel). Dazu führt es `paid_until` nach — das Ende der laufenden
     * Periode, mit dem die Kündigung (Scheibe 4) die Laufzeit bestimmt: QR der Beginn der nächsten Periode, Stripe das
     * Periodenende aus dem Webhook.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(Account $account, array $values): void
    {
        $billing = is_array($account->billing) ? $account->billing : [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($billing[$key]);
            } else {
                $billing[$key] = $value;
            }
        }
        $method = $billing['method'] ?? null;
        $end = $method === AccountInvoice::METHOD_STRIPE ? ($billing['period_end'] ?? null) : ($method === AccountInvoice::METHOD_INVOICE ? ($billing['next_invoice_at'] ?? null) : null);
        if (is_string($end) && $end !== '') {
            $billing['paid_until'] = $end;
        } else {
            unset($billing['paid_until']);
        }
        $account->forceFill(['billing' => $billing])->save();
    }
}
