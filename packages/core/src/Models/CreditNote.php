<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Gutschrift zu einer Rechnung des Herstellers (CR-004, P-12) — mit eigener Nummer `G<Jahr>-<laufend>`, unabhängig
 * davon, ob die Rechnung aus der SaaS oder dem Lizenzverkauf stammt.
 *
 * @property int $id
 * @property string $source
 * @property int $invoice_id
 * @property string $invoice_number
 * @property string $number
 * @property int $year
 * @property int $sequence
 * @property string $currency
 * @property int $amount
 * @property string $reason
 * @property string $email
 * @property list<string> $debtor
 * @property string $locale
 * @property int|null $created_by
 * @property CarbonImmutable $issued_at
 */
final class CreditNote extends Model
{
    protected $fillable = ['source', 'invoice_id', 'invoice_number', 'number', 'year', 'sequence', 'currency', 'amount', 'reason', 'email', 'debtor', 'locale', 'created_by', 'issued_at'];

    protected function casts(): array
    {
        return ['debtor' => 'array', 'issued_at' => 'immutable_date', 'amount' => 'integer'];
    }
}
