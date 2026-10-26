<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Database\Factories\SiteFactory;
use Basalto\Core\Events\SiteCreated;
use Basalto\Core\Events\SiteUpdated;
use Basalto\Core\Sites\SiteRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eine Site einer Installation (K-01): Handle, Name, Sprachen, Domains, Benutzer mit Rolle, Einstellungen.
 *
 * @property int $id
 * @property ?int $account_id
 * @property string $handle
 * @property string $name
 * @property string $default_locale
 * @property string $timezone
 * @property string|null $theme
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $live_at gesetzt von `basalto:publish` (L-06); ohne sehen Gäste «Bald online»
 */
final class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'handle', 'name', 'default_locale', 'timezone', 'theme', 'settings', 'live_at'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'live_at' => 'datetime'];
    }

    public function isLive(): bool
    {
        return $this->live_at !== null && $this->live_at->isPast();
    }

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }

    /** Domänenereignisse (ADR-011) aus dem Modell: angelegt und geändert (Stammdaten, nicht Einstellungen). */
    protected static function booted(): void
    {
        self::created(static fn (Site $site) => event(new SiteCreated($site)));
        self::updated(static function (Site $site): void {
            $fields = ['handle', 'name', 'default_locale', 'timezone', 'theme'];
            $changed = array_values(array_intersect($fields, array_keys($site->getChanges())));
            if ($changed !== []) {
                $before = [];
                $after = [];
                foreach ($changed as $field) {
                    $before[$field] = $site->getOriginal($field);
                    $after[$field] = $site->getAttribute($field);
                }
                event(new SiteUpdated($site, $before, $after));
            }
        });
    }

    /** Ein Modul je Site ein- oder ausschalten (`settings.modules.<handle>`, K-12). */
    public function setModuleEnabled(string $handle, bool $enabled): void
    {
        $settings = $this->settings ?? [];
        $settings['modules'][$handle] = $enabled;
        $this->settings = $settings;
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<SiteDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    /** @return HasMany<SiteLocale, $this> */
    public function locales(): HasMany
    {
        return $this->hasMany(SiteLocale::class)->orderBy('sort_order');
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** @return BelongsToMany<Model, $this> */
    public function users(): BelongsToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsToMany($userModel, 'site_user')->withPivot('role')->withTimestamps();
    }

    /** Der Alias der Site auf der SaaS: `<handle>.basaltocms.com` (D-01). */
    public function aliasHost(): string
    {
        return $this->handle.'.'.config('basalto.saas.alias_domain', 'basaltocms.com');
    }

    /**
     * Absolute Adresse auf dem Host der Site (primäre Domain, Schema der Anwendung; ohne Domain die Anwendung) — für
     * Verweise ins Frontend von Orten mit anderem Host, etwa dem Extranet.
     */
    public function publicUrl(string $path = '/'): string
    {
        $appUrl = (string) config('app.url');
        $host = $this->domains()->orderByDesc('is_primary')->value('host');
        $base = is_string($host) && $host !== '' ? (parse_url($appUrl, PHP_URL_SCHEME) ?: 'https').'://'.$host : rtrim($appUrl, '/');

        return $base.'/'.ltrim($path, '/');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** @return list<string> Sprachen der Site — ohne Sprachzeilen nur die Standardsprache */
    public function localeCodes(): array
    {
        $codes = [];
        foreach ($this->locales as $locale) {
            $codes[] = $locale->locale;
        }

        return $codes === [] ? [$this->default_locale] : $codes;
    }

    /** @return list<string> Pflichtsprachen für die Freigabe — ohne Sprachzeilen die Standardsprache */
    public function requiredLocales(): array
    {
        $codes = [];
        foreach ($this->locales as $locale) {
            if ($locale->is_required) {
                $codes[] = $locale->locale;
            }
        }

        return $this->locales->isEmpty() ? [$this->default_locale] : $codes;
    }

    /** Schlüssel der Rolle eines Benutzers auf dieser Site — Vorlage oder eigene Rolle (CR-005); null ohne Mitgliedschaft. */
    public function roleKeyOf(Authenticatable $user): ?string
    {
        $role = $this->users()->whereKey($user->getAuthIdentifier())->first()?->getRelationValue('pivot')?->getAttribute('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    /** Die Vorlage der Rolle eines Benutzers auf dieser Site — null bei eigenen Rollen; für Mitgliedschaft `roleKeyOf()`. */
    public function roleOf(Authenticatable $user): ?SiteRole
    {
        $pivot = $this->users()->whereKey($user->getAuthIdentifier())->first()?->getRelationValue('pivot');
        $role = $pivot?->getAttribute('role');

        return is_string($role) ? SiteRole::tryFrom($role) : null;
    }

    /**
     * Sites, deren Mitglied ein Benutzer ist.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, Authenticatable $user): Builder
    {
        return $query->whereHas('users', static fn (Builder $users): Builder => $users->whereKey($user->getAuthIdentifier()))->orderBy('name');
    }

    /**
     * Die Fallback-Kette einer Sprache: die Sprache selbst, dann ihre Fallback-Sprache, … bis zur Standardsprache.
     *
     * @return list<string>
     */
    public function fallbackChain(string $locale): array
    {
        $byCode = $this->locales->keyBy('locale');
        $chain = [];
        $current = $locale;
        while ($current !== null && ! in_array($current, $chain, true) && count($chain) < 8) {
            $chain[] = $current;
            $next = $byCode->get($current)?->fallback_locale;
            $current = $next ?? ($current === $this->default_locale ? null : $this->default_locale);
        }

        return $chain;
    }
}
