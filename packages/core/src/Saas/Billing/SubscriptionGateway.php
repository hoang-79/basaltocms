<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Models\Account;
use Basalto\Core\Saas\Plan;
use Carbon\CarbonImmutable;

/**
 * Der Weg zu Stripe für das Abo eines Kontos (TB 12 Scheibe 2) — in Betrieb Laravel Cashier ({@see StripeSubscriptionGateway}),
 * in Tests eine Attrappe. Aktiv wird ein Abo erst mit dem Webhook, nie wegen der Rückkehr aus dem Checkout.
 */
interface SubscriptionGateway
{
    /** Schlüssel, Webhook-Geheimnis und alle Preis-IDs gesetzt? Sonst gibt es nur die QR-Rechnung. */
    public function configured(): bool;

    /**
     * Eine Checkout-Sitzung im Modus `subscription` anlegen (Plan und Zusätze als Positionen) und ihre Adresse liefern;
     * mit `$trialUntil` beginnt die Zahlung erst dann (Rest der Testphase).
     *
     * @throws BillingException
     */
    public function checkout(Account $account, Plan $plan, int $extraSites, int $extraDomains, ?CarbonImmutable $trialUntil, string $successUrl, string $cancelUrl): string;

    /**
     * Positionen des laufenden Abos setzen: Plan und Mengen der Zusätze — anteilig mit sofortiger Rechnung (Heraufstufen,
     * Zusätze) oder ohne Anteil ab der nächsten Periode (Herabstufen).
     *
     * @throws BillingException
     */
    public function change(Account $account, Plan $plan, int $extraSites, int $extraDomains, bool $prorate): void;

    /**
     * Das Abo zum Ende der bezahlten Periode beenden (Wechsel auf die QR-Rechnung).
     *
     * @throws BillingException
     */
    public function stop(Account $account): void;

    /**
     * Die Adresse des Kundenportals von Stripe (Karte ändern, Belege von Stripe).
     *
     * @throws BillingException
     */
    public function portal(Account $account, string $returnUrl): string;
}
