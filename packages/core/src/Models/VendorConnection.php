<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * TB 15 Scheibe 4 (CR-004 P-06): Diese Installation ist mit einem Kundenkonto beim Hersteller verbunden. Das Token ist
 * verschlüsselt gespeichert und nie in Props oder Protokoll. Eine Zeile je Installation; gilt nur für den Hersteller,
 * bei dem sie entstand — ändert sich `BASALTO_VENDOR_URL`, ist die Installation nicht mehr verbunden.
 *
 * @property int $id
 * @property string $vendor_url
 * @property string $email
 * @property ?string $customer_name
 * @property string $host
 * @property string $token
 * @property ?int $connected_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class VendorConnection extends Model
{
    protected $fillable = ['vendor_url', 'email', 'customer_name', 'host', 'token', 'connected_by'];

    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['token' => 'encrypted', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** Die Verbindung zum konfigurierten Hersteller — oder null. */
    public static function current(): ?self
    {
        return self::query()->where('vendor_url', Vendor::url())->latest('id')->first();
    }
}
