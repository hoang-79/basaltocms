<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Das Stripe-Konto einer Site (CR-018, TB 103): geheimer Schlüssel verschlüsselt und nie in einer Ausgabe, dazu Währung,
 * Name des Kontos und ob es echte Zahlungen sind (Schlüssel `sk_live_`/`rk_live_`).
 *
 * @property int $id
 * @property int $site_id
 * @property string $provider
 * @property string|null $secret_key
 * @property string $currency
 * @property string|null $account_name
 * @property bool $live
 * @property int|null $updated_by
 */
final class PaymentSetting extends Model
{
    use BelongsToSite;

    protected $table = 'site_payment_settings';

    protected $fillable = ['site_id', 'provider', 'secret_key', 'currency', 'account_name', 'live', 'updated_by'];

    protected $hidden = ['secret_key'];

    protected function casts(): array
    {
        return ['secret_key' => 'encrypted', 'live' => 'boolean'];
    }
}
