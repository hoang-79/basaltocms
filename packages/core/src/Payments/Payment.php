<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

use Basalto\Core\Sites\BelongsToSite;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine Zahlung einer Site (CR-018, TB 103): angelegt von einem Modul für einen Bezug (z. B. «reservation:12»), bezahlt
 * im Stripe-Checkout des Betreibers. Stand: offen, bezahlt, abgelaufen oder abgebrochen. Das Modul erfährt es über
 * {@see PaymentCompleted} bzw. {@see PaymentExpired}.
 *
 * @property int $id
 * @property int $site_id
 * @property string $module
 * @property string $reference
 * @property string $token
 * @property int $amount Rappen bzw. Cent
 * @property string $currency
 * @property string $description
 * @property string $status
 * @property bool $live
 * @property string|null $provider_session
 * @property string|null $provider_payment
 * @property string|null $checkout_url
 * @property string $return_url
 * @property string|null $email
 * @property array<string, string>|null $metadata
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface $created_at
 */
final class Payment extends Model
{
    use BelongsToSite;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    protected $table = 'payments';

    protected $fillable = ['site_id', 'module', 'reference', 'token', 'amount', 'currency', 'description', 'status', 'live', 'provider_session', 'provider_payment',
        'checkout_url', 'return_url', 'email', 'metadata', 'paid_at', 'expires_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'live' => 'boolean', 'metadata' => 'array', 'paid_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /** «CHF 40.00» */
    public function amountLabel(): string
    {
        return $this->currency.' '.number_format($this->amount / 100, 2, '.', "'");
    }
}
