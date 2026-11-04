<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

/**
 * Der Anbieter hinter den Zahlungen einer Site — Stripe ({@see StripeCheckoutGateway}); in Tests eine Attrappe.
 */
interface CheckoutGateway
{
    /**
     * Prüft den Schlüssel und nennt das Konto.
     *
     * @return array{name: string, live: bool}
     *
     * @throws PaymentException
     */
    public function account(string $secret): array;

    /**
     * Eine Checkout-Sitzung für eine Einmalzahlung.
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string, expires_at: int}
     *
     * @throws PaymentException
     */
    public function create(string $secret, int $amount, string $currency, string $description, string $successUrl, string $cancelUrl, ?string $email, string $locale, array $metadata, int $minutes): array;

    /**
     * Stand einer Sitzung: bezahlt, offen oder abgelaufen.
     *
     * @return array{status: 'paid'|'open'|'expired', payment: string|null, email: string|null}
     *
     * @throws PaymentException
     */
    public function retrieve(string $secret, string $session): array;
}
