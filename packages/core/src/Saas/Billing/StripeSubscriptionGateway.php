<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Models\Account;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Saas;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * Das Abo eines Kontos über Laravel Cashier (TB 12 Scheibe 2): Stripe Checkout im Modus `subscription` mit den Preis-IDs
 * aus `basalto.saas.billing.stripe_prices` — Plan einmal, Zusatz-Sites und Zusatzdomains mit Menge. Konto, Instanz,
 * Plan und Zusätze stehen in den Metadaten von Sitzung und Abo; der Webhook der SaaS findet so das Konto. Zahlarten
 * stellt das Stripe-Dashboard ein (Karte; TWINT kennt Stripe nur für Einmalzahlungen, nicht für Abos).
 */
final class StripeSubscriptionGateway implements SubscriptionGateway
{
    public const TYPE = 'default';

    public function __construct(private readonly BillingSettings $settings) {}

    public function configured(): bool
    {
        foreach (['cashier.key', 'cashier.secret', 'cashier.webhook.secret'] as $key) {
            $value = config($key);
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return $this->settings->stripePricesComplete();
    }

    public function checkout(Account $account, Plan $plan, int $extraSites, int $extraDomains, ?CarbonImmutable $trialUntil, string $successUrl, string $cancelUrl): string
    {
        $this->ensureConfigured();
        CashierModels::apply();
        $metadata = self::metadata($account, $plan, $extraSites, $extraDomains);
        $builder = $account->newSubscription(self::TYPE, []);
        foreach ($this->items($plan, $extraSites, $extraDomains) as $price => $item) {
            $builder->price($price, $item['quantity']);
        }
        if ($trialUntil !== null && $trialUntil->isFuture()) {
            $builder->trialUntil($trialUntil);
        }
        $locale = $account->billing['locale'] ?? app()->getLocale();
        try {
            $checkout = $builder->withMetadata($metadata)->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => 'account-'.$account->id,
                'metadata' => $metadata,
                'locale' => in_array($locale, ['de', 'fr', 'it', 'en'], true) ? $locale : 'auto',
            ]);
        } catch (Throwable $e) {
            report($e);

            throw new BillingException(__('Stripe hat den Checkout abgelehnt. Bitte später erneut versuchen oder die QR-Rechnung wählen.'), previous: $e);
        }

        return (string) $checkout->asStripeCheckoutSession()->url;
    }

    public function change(Account $account, Plan $plan, int $extraSites, int $extraDomains, bool $prorate): void
    {
        $subscription = $this->subscription($account);
        try {
            $items = $this->items($plan, $extraSites, $extraDomains);
            if ($prorate) {
                $subscription->swapAndInvoice($items);
            } else {
                $subscription->noProrate()->swap($items);
            }
        } catch (Throwable $e) {
            report($e);

            throw new BillingException(__('Stripe hat die Änderung des Abos abgelehnt. Bitte später erneut versuchen.'), previous: $e);
        }
    }

    public function stop(Account $account): void
    {
        $subscription = $this->subscription($account);
        try {
            $subscription->cancel();
        } catch (Throwable $e) {
            report($e);

            throw new BillingException(__('Stripe hat die Änderung des Abos abgelehnt. Bitte später erneut versuchen.'), previous: $e);
        }
    }

    public function portal(Account $account, string $returnUrl): string
    {
        $this->ensureConfigured();
        CashierModels::apply();
        try {
            return $account->billingPortalUrl($returnUrl);
        } catch (Throwable $e) {
            report($e);

            throw new BillingException(__('Das Kundenportal von Stripe ist gerade nicht erreichbar. Bitte später erneut versuchen.'), previous: $e);
        }
    }

    /**
     * Metadaten für Sitzung und Abo — Konto, Instanz, Plan und Zusätze.
     *
     * @return array<string, string>
     */
    public static function metadata(Account $account, Plan $plan, int $extraSites, int $extraDomains): array
    {
        return ['basalto_account' => (string) $account->id, 'basalto_instance' => Saas::instance(), 'plan' => $plan->key,
            'extra_sites' => (string) $extraSites, 'extra_domains' => (string) $extraDomains];
    }

    /** @return array<string, array{quantity: int}> Preis-ID → Menge; Zusätze nur mit Menge über null */
    private function items(Plan $plan, int $extraSites, int $extraDomains): array
    {
        $items = [(string) $this->settings->stripePrice($plan->key) => ['quantity' => 1]];
        foreach ([BillingSettings::EXTRA_SITE => $extraSites, BillingSettings::EXTRA_DOMAIN => $extraDomains] as $key => $quantity) {
            if ($quantity > 0) {
                $items[(string) $this->settings->stripePrice($key)] = ['quantity' => $quantity];
            }
        }

        return $items;
    }

    private function subscription(Account $account): Subscription
    {
        $this->ensureConfigured();
        CashierModels::apply();
        $subscription = $account->subscription(self::TYPE);
        if ($subscription === null || ! $subscription->valid()) {
            throw new BillingException(__('Zu diesem Konto gibt es kein laufendes Abo bei Stripe.'));
        }

        return $subscription;
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new BillingException(__('Die Zahlung mit Karte ist noch nicht eingerichtet — bitte die QR-Rechnung wählen.'));
        }
    }
}
