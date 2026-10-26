<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Sso\SsoProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TB 13 Scheibe 4 (M-25): SSO-Anbieter einer Site — App-Registrierung bei Microsoft Entra ID (Tenant fest) oder Google
 * (optional nur eine Workspace-Domain). Das Client-Secret ist verschlüsselt gespeichert und nie in Props oder Protokoll.
 *
 * @property int $id
 * @property int $site_id
 * @property string $provider
 * @property bool $enabled
 * @property ?string $tenant_id
 * @property string $client_id
 * @property string $client_secret
 * @property ?string $hosted_domain
 * @property ?string $auto_create_role
 * @property ?int $updated_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class SsoConnection extends Model
{
    protected $fillable = ['site_id', 'provider', 'enabled', 'tenant_id', 'client_id', 'client_secret', 'hosted_domain', 'auto_create_role', 'updated_by'];

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

    public function providerEnum(): SsoProvider
    {
        return SsoProvider::from($this->provider);
    }

    /**
     * Die Rolle für automatisch angelegte Benutzer — nur, wenn der Kreis der Konten fest begrenzt ist: Entra mit festem
     * Tenant oder Google mit Workspace-Domain. Google ohne Domain hiesse: jedes Google-Konto der Welt bekäme Zugang.
     */
    public function autoCreateRole(): ?SiteRole
    {
        $role = $this->auto_create_role === null ? null : SiteRole::tryFrom($this->auto_create_role);
        if ($role === null || ! in_array($role, self::autoCreateRoles(), true)) {
            return null;
        }
        if ($this->providerEnum() === SsoProvider::Google && ($this->hosted_domain === null || $this->hosted_domain === '')) {
            return null;
        }

        return $role;
    }

    /** @return list<SiteRole> Rollen für die automatische Anlage — nie Betreiber oder Administrator */
    public static function autoCreateRoles(): array
    {
        return [SiteRole::Editor, SiteRole::Approver, SiteRole::Translator];
    }

    /** Eingerichtet und eingeschaltet — für eine Site und einen Anbieter. */
    public static function active(Site $site, SsoProvider $provider): ?self
    {
        return self::query()->where('site_id', $site->id)->where('provider', $provider->value)->where('enabled', true)->first();
    }
}
