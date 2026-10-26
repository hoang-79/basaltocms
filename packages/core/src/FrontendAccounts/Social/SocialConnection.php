<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use Basalto\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TB 53: Ein Anbieter für die Anmeldung im Frontend-Konto einer Site — die App, die der Betreiber beim Anbieter
 * angelegt hat. Das Client-Secret ist verschlüsselt gespeichert und nie in Props oder Protokoll.
 *
 * @property int $id
 * @property int $site_id
 * @property string $provider
 * @property bool $enabled
 * @property string $client_id
 * @property string $client_secret
 * @property ?string $tenant
 * @property ?int $updated_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class SocialConnection extends Model
{
    /** Microsoft: private und Firmenkonten */
    public const TENANT_ALL = 'common';

    /** Microsoft: nur private Konten (Outlook, Hotmail, Xbox) */
    public const TENANT_PERSONAL = 'consumers';

    protected $table = 'customer_login_providers';

    protected $fillable = ['site_id', 'provider', 'enabled', 'client_id', 'client_secret', 'tenant', 'updated_by'];

    protected $hidden = ['client_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'client_secret' => 'encrypted', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function providerEnum(): SocialProvider
    {
        return SocialProvider::from($this->provider);
    }

    public function microsoftTenant(): string
    {
        return $this->tenant === self::TENANT_PERSONAL ? self::TENANT_PERSONAL : self::TENANT_ALL;
    }

    public function hasSecret(): bool
    {
        $raw = $this->getRawOriginal('client_secret');

        return is_string($raw) && $raw !== '';
    }

    /** Eingerichtet und eingeschaltet — für eine Site und einen Anbieter. */
    public static function active(Site $site, SocialProvider $provider): ?self
    {
        return self::query()->where('site_id', $site->id)->where('provider', $provider->value)->where('enabled', true)->first();
    }

    /**
     * Die eingeschalteten Anbieter einer Site, in der festen Reihenfolge der Knöpfe.
     *
     * @return list<SocialProvider>
     */
    public static function enabledFor(Site $site): array
    {
        $enabled = self::query()->where('site_id', $site->id)->where('enabled', true)->pluck('provider')->all();

        return array_values(array_filter(SocialProvider::cases(), static fn (SocialProvider $p): bool => in_array($p->value, $enabled, true)));
    }
}
