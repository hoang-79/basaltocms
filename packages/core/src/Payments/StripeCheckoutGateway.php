<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

use Basalto\Core\Support\StripeNotices;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe Checkout mit dem Schlüssel der Site: eine Einmalzahlung mit dem Betrag aus dem Modul (`price_data`), die
 * Zahlungsarten stellt der Betreiber im Stripe-Dashboard ein (Karte, TWINT …). Managed Payments aus — der Betreiber
 * verkauft selbst, Stripe ist nicht Händler. Hinweise von Stripe gehen ins Log ({@see StripeNotices}).
 */
final class StripeCheckoutGateway implements CheckoutGateway
{
    public function account(string $secret): array
    {
        $live = (bool) preg_match('/^(sk|rk)_live_/', $secret);
        try {
            $account = StripeNotices::quietly(fn () => $this->client($secret)->accounts->retrieve());
            $name = $account->settings->dashboard->display_name ?? $account->business_profile->name ?? $account->email ?? $account->id;

            return ['name' => (string) $name, 'live' => $live];
        } catch (AuthenticationException $e) {
            throw new PaymentException((string) __('Stripe kennt diesen Schlüssel nicht.'), previous: $e);
        } catch (PermissionException) {
            // Ein eingeschränkter Schlüssel darf das Konto nicht lesen — Zahlungen gehen trotzdem
            return ['name' => 'Stripe', 'live' => $live];
        } catch (Throwable $e) {
            throw new PaymentException((string) __('Stripe ist gerade nicht erreichbar. Bitte später erneut versuchen.'), previous: $e);
        }
    }

    public function create(string $secret, int $amount, string $currency, string $description, string $successUrl, string $cancelUrl, ?string $email, string $locale, array $metadata, int $minutes): array
    {
        $params = array_filter([
            'mode' => 'payment',
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($currency), 'unit_amount' => $amount, 'product_data' => ['name' => mb_substr($description, 0, 250)]]]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $email,
            'locale' => in_array($locale, ['de', 'fr', 'it', 'en'], true) ? $locale : 'auto',
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            // Stripe verlangt 30 Minuten bis 24 Stunden
            'expires_at' => time() + max(30, min(1440, $minutes)) * 60,
            'managed_payments' => ['enabled' => false],
        ], static fn (mixed $v): bool => $v !== null);
        try {
            $session = StripeNotices::quietly(fn () => $this->client($secret)->checkout->sessions->create($params));
        } catch (ApiErrorException $e) {
            report($e);

            throw new PaymentException((string) __('Stripe hat die Zahlung abgelehnt. Bitte später erneut versuchen.'), previous: $e);
        }

        return ['id' => (string) $session->id, 'url' => (string) $session->url, 'expires_at' => (int) $session->expires_at];
    }

    public function retrieve(string $secret, string $session): array
    {
        try {
            $checkout = StripeNotices::quietly(fn () => $this->client($secret)->checkout->sessions->retrieve($session));
        } catch (ApiErrorException $e) {
            throw new PaymentException((string) __('Stripe ist gerade nicht erreichbar. Bitte später erneut versuchen.'), previous: $e);
        }
        $status = match (true) {
            $checkout->status === 'complete' && in_array($checkout->payment_status, ['paid', 'no_payment_required'], true) => 'paid',
            $checkout->status === 'expired' => 'expired',
            default => 'open',
        };
        $intent = $checkout->payment_intent;

        return ['status' => $status, 'payment' => is_string($intent) ? $intent : ($intent->id ?? null), 'email' => $checkout->customer_details->email ?? null];
    }

    private function client(string $secret): StripeClient
    {
        return new StripeClient(['api_key' => $secret, 'max_network_retries' => 1]);
    }
}
