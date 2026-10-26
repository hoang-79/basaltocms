<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use Basalto\Core\FrontendAccounts\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TB 53: Ein Kundenkonto ist mit einem Konto bei einem Anbieter verknüpft — über dessen feste Kennung (`sub`, bei
 * Microsoft Tenant und Objekt-ID, bei GitHub die Nummer). Die E-Mail steht nur zur Anzeige da.
 *
 * @property int $id
 * @property int $site_id
 * @property int $customer_id
 * @property string $provider
 * @property string $subject
 * @property ?string $email
 * @property ?CarbonImmutable $last_login_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class SocialIdentity extends Model
{
    protected $table = 'customer_identities';

    protected $fillable = ['site_id', 'customer_id', 'provider', 'subject', 'email', 'last_login_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_login_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    public function providerEnum(): ?SocialProvider
    {
        return SocialProvider::tryFrom($this->provider);
    }
}
