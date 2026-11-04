<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

use Illuminate\Foundation\Events\Dispatchable;

/** Eine Zahlung ist nicht zustande gekommen (Checkout abgelaufen) — das Modul gibt den Bezug frei. Kommt je Zahlung einmal. */
final class PaymentExpired
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
