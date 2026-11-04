<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

use Illuminate\Foundation\Events\Dispatchable;

/** Eine Zahlung ist bezahlt — das Modul bestätigt seinen Bezug (Reservation, Gutschein …). Kommt je Zahlung einmal. */
final class PaymentCompleted
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
