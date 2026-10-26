<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Passkeys;

use Basalto\Core\FrontendAccounts\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passkeys\Support\Aaguids;

/**
 * TB 53: Ein Passkey eines Kundenkontos — gespeichert wie bei `laravel/passkeys` (Kennung, Datensatz des
 * Authenticators als JSON), aber je Site und am Kundenkonto; die Passkeys der Backend-Benutzer bleiben in `passkeys`.
 *
 * @property int $id
 * @property int $site_id
 * @property int $customer_id
 * @property string $name
 * @property string $credential_id
 * @property array<string, mixed> $credential
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read ?string $authenticator
 */
final class CustomerPasskey extends Model
{
    protected $table = 'customer_passkeys';

    protected $fillable = ['site_id', 'customer_id', 'name', 'credential_id', 'credential'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['credential' => 'json', 'last_used_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    /**
     * Name des Authenticators aus seiner AAGUID («iCloud-Schlüsselbund», «Google Passwortmanager») — wenn bekannt.
     *
     * @return Attribute<?string, never>
     */
    protected function authenticator(): Attribute
    {
        return Attribute::get(function (): ?string {
            $aaguid = $this->credential['aaguid'] ?? null;

            return ! is_string($aaguid) || $aaguid === Aaguids::unknown() ? null : Aaguids::labelFor($aaguid);
        });
    }
}
