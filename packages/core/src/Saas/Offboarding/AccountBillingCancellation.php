<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Saas\Billing\AccountBilling;
use Basalto\Core\Saas\Billing\CashierModels;
use Basalto\Core\Saas\Billing\StripeSubscriptionGateway;
use Basalto\Core\Saas\Billing\SubscriptionGateway;
use Carbon\CarbonImmutable;

/**
 * Vorgabe der Naht {@see CancellationBilling} über das Abo aus Scheibe 2 ({@see AccountBilling}):
 *
 * - **Ende der Laufzeit** = `billing.paid_until` (Scheibe 2 führt es nach: Stripe das Periodenende aus dem Webhook, QR
 *   der Beginn der nächsten Periode) — als Tag in der Zeitzone der Site, 00:00 Uhr; bis dahin läuft alles.
 * - **Stripe:** `SubscriptionGateway::stop()` (Cashier `cancel()` — endet mit der bezahlten Periode); Zurücknehmen mit
 *   Cashier `resume()`, solange das Abo in der Kulanzzeit ist.
 * - **QR-Rechnung:** nichts zu tun — `basalto:saas-billing` stellt gekündigten Konten keine Rechnung aus; nach dem
 *   Zurücknehmen geht es mit der nächsten Periode weiter.
 * - **Ohne Abo** (Testphase, kein Plan): keine bezahlte Periode — die Laufzeit endet sofort.
 */
final class AccountBillingCancellation implements CancellationBilling
{
    public function __construct(private readonly SubscriptionGateway $gateway) {}

    public function periodEnd(Account $account): ?CarbonImmutable
    {
        if (! AccountBilling::subscribed($account)) {
            return null;
        }
        $paid = $account->billing['paid_until'] ?? ($account->billing['period_end'] ?? ($account->billing['next_invoice_at'] ?? null));
        if (! is_string($paid) || $paid === '') {
            return null;
        }
        $end = CarbonImmutable::parse($paid, Lifecycle::timezone($account))->startOfDay();

        return $end->isFuture() ? $end : null;
    }

    public function cancelAtPeriodEnd(Account $account): ?CarbonImmutable
    {
        $end = $this->periodEnd($account);
        if ($end !== null && AccountBilling::method($account) === AccountInvoice::METHOD_STRIPE) {
            $this->gateway->stop($account);
        }

        return $end;
    }

    public function resume(Account $account): void
    {
        if (AccountBilling::method($account) !== AccountInvoice::METHOD_STRIPE || ! $this->gateway->configured()) {
            return;
        }
        CashierModels::apply();
        $subscription = $account->subscription(StripeSubscriptionGateway::TYPE);
        if ($subscription !== null && $subscription->onGracePeriod()) {
            $subscription->resume();
        }
    }
}
