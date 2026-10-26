<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Rechnung an ein Konto der SaaS (TB 12 Scheibe 2, M-21): Nummer fortlaufend je Jahr und Instanz (S-2026-0001),
 * Periode, Positionen (Plan, Zusatz-Sites, Zusatzdomains, anteilige Beträge), MwSt., Adresse und Sprache so, wie sie
 * beim Ausstellen galten. Bezahlt per Stripe-Abo oder offen als QR-Rechnung; das PDF entsteht bei Bedarf.
 *
 * @property int $id
 * @property int|null $account_id
 * @property string $number
 * @property int $year
 * @property int $sequence
 * @property string $source
 * @property string $method
 * @property string $plan
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string $currency
 * @property list<array{key: string, text: string, detail: string|null, quantity: int, amount: int}> $lines
 * @property array<string, string|null> $billing
 * @property string $locale
 * @property int $net_amount
 * @property int $vat_rate
 * @property int $vat_amount
 * @property int $total
 * @property string $status
 * @property string|null $payment_reference
 * @property string|null $stripe_invoice_id
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $due_at
 * @property Carbon|null $paid_at
 * @property-read Account|null $account
 */
final class AccountInvoice extends Model
{
    public const METHOD_STRIPE = 'stripe';

    public const METHOD_INVOICE = 'invoice';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['account_id', 'number', 'year', 'sequence', 'source', 'method', 'plan', 'period_start', 'period_end', 'currency', 'lines', 'billing', 'locale',
        'net_amount', 'vat_rate', 'vat_amount', 'total', 'status', 'payment_reference', 'stripe_invoice_id', 'issued_at', 'due_at', 'paid_at'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'billing' => 'array', 'period_start' => 'immutable_date', 'period_end' => 'immutable_date', 'issued_at' => 'immutable_date',
            'due_at' => 'immutable_date', 'paid_at' => 'datetime', 'year' => 'integer', 'sequence' => 'integer', 'net_amount' => 'integer', 'vat_rate' => 'integer',
            'vat_amount' => 'integer', 'total' => 'integer'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Offen und die Zahlungsfrist vorbei. */
    public function overdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->lt(CarbonImmutable::today());
    }

    public function filename(): string
    {
        return $this->number.'.pdf';
    }
}
