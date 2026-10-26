<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Invoices\Creditor;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Saas;

/**
 * Die Angaben der Abrechnung einer SaaS-Instanz (TB 12 Scheibe 2) aus `basalto.saas.billing`: Rechnungssteller mit
 * Konto für den QR-Zahlteil, MwSt., Zahlungsfrist, Präfix der Rechnungsnummern und die Preis-IDs der Stripe-Abos.
 * Preise selbst stehen bei den Plänen (`basalto.saas.plans.*.price`, CHF je Monat) und bei den Zusätzen.
 */
final class BillingSettings
{
    public const CURRENCY = 'CHF';

    public const EXTRA_SITE = 'extra_site';

    public const EXTRA_DOMAIN = 'extra_domain';

    public function creditor(): Creditor
    {
        return Creditor::fromArray((array) config('basalto.saas.billing.creditor', []));
    }

    /** MwSt.-Satz in Basispunkten (810 = 8,1 %); 0 = nicht pflichtig. */
    public function vatRate(): int
    {
        return max(0, (int) round(((float) config('basalto.saas.billing.vat.rate', 0)) * 100));
    }

    public function vatNumber(): string
    {
        return trim((string) config('basalto.saas.billing.vat.number', ''));
    }

    public function paymentDays(): int
    {
        return max(1, (int) config('basalto.saas.billing.payment_days', 14));
    }

    /** Präfix der Rechnungsnummern: konfiguriert, sonst «S» auf «start» und «P» auf «pro» — eigene Kreise je Instanz. */
    public function invoicePrefix(): string
    {
        $prefix = strtoupper(trim((string) config('basalto.saas.billing.invoice_prefix', '')));

        return preg_match('/^[A-Z]{1,4}$/', $prefix) === 1 ? $prefix : strtoupper(substr(Saas::instance(), 0, 1) ?: 'S');
    }

    /** Ziffer vor der QR-Referenz (nur Ziffern erlaubt): «start» 1, «pro» 2 — der Lizenzverkauf hat keine. */
    public function referencePrefix(): string
    {
        return Saas::instance() === 'pro' ? '2' : '1';
    }

    /** Monatspreis in Rappen: Plan (`start`, `standard`, `pro`) oder Zusatz ({@see EXTRA_SITE}, {@see EXTRA_DOMAIN}). */
    public function price(string $key): int
    {
        return match ($key) {
            self::EXTRA_SITE => (int) config('basalto.saas.extra_site_price', 15) * 100,
            self::EXTRA_DOMAIN => (int) config('basalto.saas.extra_domain_price', 5) * 100,
            default => (int) config('basalto.saas.plans.'.$key.'.price', 0) * 100,
        };
    }

    /** Preis-ID bei Stripe für einen Plan oder Zusatz — null, wenn nicht eingerichtet. */
    public function stripePrice(string $key): ?string
    {
        $price = config('basalto.saas.billing.stripe_prices.'.$key);

        return is_string($price) && trim($price) !== '' ? trim($price) : null;
    }

    /** Welcher Plan oder Zusatz hinter einer Preis-ID steckt — null für fremde Preise. */
    public function keyOfStripePrice(?string $price): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }
        foreach ((array) config('basalto.saas.billing.stripe_prices', []) as $key => $value) {
            if ($value === $price) {
                return (string) $key;
            }
        }

        return null;
    }

    /** Alle Preis-IDs gesetzt: die buchbaren Pläne und beide Zusätze. */
    public function stripePricesComplete(): bool
    {
        foreach (array_merge(array_map(static fn (Plan $plan): string => $plan->key, Plan::purchasable()), [self::EXTRA_SITE, self::EXTRA_DOMAIN]) as $key) {
            if ($this->stripePrice($key) === null) {
                return false;
            }
        }

        return true;
    }
}
