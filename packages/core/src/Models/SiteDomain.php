<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Adresse einer Site: Alias `<handle>.basaltocms.com`, eigene Kundendomain oder Umleitung.
 *
 * @property int $id
 * @property int $site_id
 * @property string $host
 * @property string $kind
 * @property bool $is_primary
 * @property string|null $cloud_domain_id
 * @property string|null $cloud_status
 * @property array<string, mixed>|null $cloud_response
 * @property Carbon|null $verified_at
 * @property string|null $last_error
 * @property int|null $pair_id TB 12 Scheibe 3: `www` bzw. Apex zur Zeile, die den Cloud-Eintrag trägt
 * @property Carbon|null $redirect_until TB 12 Scheibe 3: alter Alias leitet bis dahin um, danach ist er frei
 * @property Carbon|null $checked_at TB 12 Scheibe 3: letzte Prüfung über die Cloud-CLI
 * @property string|null $hosting_status CR-017: null = nicht von Basalto beim Hoster eingerichtet, sonst `pending`/`ready`
 * @property string|null $hosting_error CR-017: der letzte Fehler beim Einrichten, für Betrieb und Extranet
 * @property Carbon|null $hosting_checked_at CR-017: letzter Versuch
 * @property Carbon|null $hosting_retry_at CR-017: frühester nächster Versuch nach einem Fehler
 * @property Carbon|null $certificate_expires_at CR-017: Ablauf des eingespielten Zertifikats
 * @property Carbon|null $created_at
 */
final class SiteDomain extends Model
{
    public const KIND_ALIAS = 'alias';

    public const KIND_CUSTOM = 'custom';

    public const KIND_REDIRECT = 'redirect';

    /** CR-017: Unterdomain und Zertifikat beim Hoster stehen noch aus. */
    public const HOSTING_PENDING = 'pending';

    /** CR-017: Unterdomain eingerichtet, Zertifikat eingespielt, die Adresse antwortet über https. */
    public const HOSTING_READY = 'ready';

    protected $fillable = ['site_id', 'host', 'kind', 'is_primary', 'cloud_domain_id', 'cloud_status', 'cloud_response', 'verified_at', 'last_error', 'pair_id', 'redirect_until', 'checked_at',
        'hosting_status', 'hosting_error', 'hosting_checked_at', 'hosting_retry_at', 'certificate_expires_at'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'cloud_response' => 'array', 'verified_at' => 'datetime', 'redirect_until' => 'datetime', 'checked_at' => 'datetime',
            'hosting_checked_at' => 'datetime', 'hosting_retry_at' => 'datetime', 'certificate_expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** TB 12 Scheibe 3: eine Umleitung, deren Frist abgelaufen ist (alter Alias nach 30 Tagen) — gilt nicht mehr. */
    public function isExpired(): bool
    {
        return $this->redirect_until !== null && $this->redirect_until->isPast();
    }

    /** @return BelongsTo<SiteDomain, $this> TB 12 Scheibe 3: die Zeile mit dem Cloud-Eintrag (bei `www` bzw. Apex des Paars) */
    public function pair(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pair_id');
    }

    /** Reservierte Alias-Namen gehören der Produkt-Site (D-01: testing, demo, status) oder sind technisch belegt. */
    public static function isReservedAlias(string $name): bool
    {
        return in_array(strtolower($name), config('basalto.saas.reserved_aliases', []), true);
    }

    public static function isValidAlias(string $name): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])?$/', $name) === 1 && ! self::isReservedAlias($name);
    }
}
