<?php

declare(strict_types=1);

namespace Basalto\Core\Access;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Extranet\SupportAccess;
use Basalto\Core\Models\Role;
use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Die eine Prüfstelle für Rechte (CR-005, R-06): Welche Rolle hat eine Person auf einer Site, welche Stufe in einem
 * Bereich, welche Sprachen darf sie bearbeiten? Menü, Middleware, Policies und Props fragen hier. Je Anfrage
 * zwischengespeichert (im Container «scoped», unter Octane je Anfrage neu).
 */
final class Access
{
    /** @var array<string, RoleDefinition|null> */
    private array $definitions = [];

    public function __construct(private readonly Areas $areas) {}

    /** Die Rolle der Person auf der Site — null, wenn sie nicht Mitglied ist. */
    public function definition(Authenticatable $user, Site $site): ?RoleDefinition
    {
        $cacheKey = $user->getAuthIdentifier().':'.$site->getKey();
        if (array_key_exists($cacheKey, $this->definitions)) {
            return $this->definitions[$cacheKey];
        }
        // Besitzer und Admins des Kontos dürfen auf allen Sites des Kontos alles (ADR-008)
        $accountRole = $site->account?->users()->whereKey($user->getAuthIdentifier())->first()?->getRelationValue('pivot')?->getAttribute('role');
        if (in_array($accountRole, [AccountRole::Owner->value, AccountRole::Admin->value], true)) {
            return $this->definitions[$cacheKey] = self::fromTemplate(RoleTemplates::OWNER);
        }
        $key = $site->users()->whereKey($user->getAuthIdentifier())->first()?->getRelationValue('pivot')?->getAttribute('role');
        if (! is_string($key)) {
            // TB 19 (P-16): befristeter Support-Zugriff des Herstellers — überall nur «ansehen»
            return $this->definitions[$cacheKey] = SupportAccess::active($user, $site) !== null ? SupportAccess::definition() : null;
        }

        return $this->definitions[$cacheKey] = $this->resolve($key, $site->account_id);
    }

    /** Rolle nach Schlüssel: angepasst aus der Tabelle, sonst die Vorlage. Betreiber immer aus der Vorlage. */
    public function resolve(string $key, ?int $accountId): ?RoleDefinition
    {
        if ($key !== RoleTemplates::OWNER) {
            $role = Role::query()->where('account_id', $accountId)->where('key', $key)->first();
            if ($role !== null) {
                return $role->definition();
            }
        }

        return RoleTemplates::get($key) === null ? null : self::fromTemplate($key);
    }

    public static function fromTemplate(string $key): RoleDefinition
    {
        $template = RoleTemplates::get($key) ?? ['name' => $key, 'levels' => [], 'languages' => null, 'locked' => false];

        return new RoleDefinition($key, $template['name'], $template['levels'], $template['languages'], $template['locked']);
    }

    public function level(Authenticatable $user, Site $site, string $area): Level
    {
        return $this->definition($user, $site)?->level($area) ?? Level::None;
    }

    public function allows(Authenticatable $user, Site $site, string $area, Level $level = Level::View): bool
    {
        return $this->level($user, $site, $area)->includes($level);
    }

    /** Ob die Person mindestens einen Bereich der Gruppe sieht (Modus «Einrichtung», «Verwaltung»). */
    public function seesGroup(Authenticatable $user, Site $site, string $group): bool
    {
        foreach ($this->areas->inGroup($group) as $area) {
            if ($this->allows($user, $site, $area)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sprachen, in denen die Person auf der Site Inhalte bearbeiten darf.
     *
     * @return list<string>
     */
    public function locales(Authenticatable $user, Site $site): array
    {
        return $this->definition($user, $site)?->locales($site->localeCodes(), $site->default_locale) ?? [];
    }

    public function mayEditLocale(Authenticatable $user, Site $site, string $locale): bool
    {
        return in_array($locale, $this->locales($user, $site), true);
    }

    /** Bricht mit 403 ab, wenn die Rolle die Sprache nicht bearbeiten darf (CR-005, R-05). */
    public function authorizeLocale(?Authenticatable $user, Site $site, string $locale): void
    {
        abort_unless($user !== null && $this->mayEditLocale($user, $site, $locale), 403, __('Ihre Rolle darf Inhalte in dieser Sprache nicht bearbeiten.'));
    }

    /**
     * Stufe je Bereich für die Oberfläche (Props).
     *
     * @return array<string, int>
     */
    public function levels(Authenticatable $user, Site $site): array
    {
        $levels = [];
        foreach (array_keys($this->areas->all()) as $area) {
            $levels[$area] = $this->level($user, $site, $area)->value;
        }

        return $levels;
    }

    /**
     * Rollen zur Auswahl (Benutzer, Einladung, Seitenrechte): die Vorlagen in fester Reihenfolge, dann eigene Rollen.
     *
     * @return list<array{value: string, label: string, template: bool}>
     */
    public function options(?int $accountId, bool $extranet = false): array
    {
        $rows = Role::query()->where('account_id', $accountId)->orderBy('name')->get()->keyBy('key');
        $options = [];
        foreach ($extranet ? [RoleTemplates::ADMINISTRATOR, ...RoleTemplates::EXTRANET_KEYS] : RoleTemplates::KEYS as $key) {
            $row = $key === RoleTemplates::OWNER ? null : $rows->get($key);
            $options[] = ['value' => $key, 'label' => $row !== null ? $row->name : (string) RoleTemplates::label($key), 'template' => true];
        }
        foreach ($rows as $key => $row) {
            if (! RoleTemplates::isTemplate((string) $key)) {
                $options[] = ['value' => (string) $key, 'label' => $row->name, 'template' => false];
            }
        }

        return $options;
    }

    /** @return list<string> */
    public function keys(?int $accountId, bool $extranet = false): array
    {
        return array_column($this->options($accountId, $extranet), 'value');
    }

    /** Anzeigename einer Rolle — der Schlüssel, wenn es sie nicht (mehr) gibt. */
    public function name(string $key, ?int $accountId): string
    {
        foreach ($this->options($accountId) as $option) {
            if ($option['value'] === $key) {
                return $option['label'];
            }
        }

        return $key;
    }

    /**
     * Wie viele Personen eine Rolle auf den Sites des Kontos haben (bzw. der Installation ohne Konto).
     *
     * @return array<string, int>
     */
    public function people(?int $accountId): array
    {
        return DB::table('site_user')->join('sites', 'sites.id', '=', 'site_user.site_id')
            ->when($accountId === null, static fn ($q) => $q->whereNull('sites.account_id'), static fn ($q) => $q->where('sites.account_id', $accountId))
            ->selectRaw('site_user.role as role, count(distinct site_user.user_id) as people')->groupBy('site_user.role')->pluck('people', 'role')
            ->map(static fn (mixed $n): int => (int) $n)->all();
    }

    /**
     * Keine Rechte verschenken, die man selbst nicht hat (CR-005, R-10): Wer eine Rolle vergibt oder ändert, braucht
     * in jedem Bereich mindestens dieselbe Stufe und jede ihrer Sprachen. Die Rolle Betreiber vergibt nur ein Betreiber.
     */
    public function covers(Authenticatable $actor, Site $site, RoleDefinition $role): bool
    {
        $own = $this->definition($actor, $site);
        if ($own === null || ($role->locked && ! $own->locked)) {
            return false;
        }
        if ($own->isUnrestricted()) {
            return true;
        }
        foreach (array_keys($this->areas->all()) as $area) {
            if (! $own->level($area)->includes($role->level($area))) {
                return false;
            }
        }

        return array_diff($role->locales($site->localeCodes(), $site->default_locale), $own->locales($site->localeCodes(), $site->default_locale)) === [];
    }

    public function forget(): void
    {
        $this->definitions = [];
    }
}
