<?php

declare(strict_types=1);

namespace Basalto\Core\Backend;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Http\Middleware\EnsureAccountActive;
use Basalto\Core\Http\Middleware\GuardAccountLifecycle;
use Basalto\Core\Http\Middleware\GuardAreaAccess;
use Basalto\Core\Http\Middleware\GuardSaasInstanceAreas;
use Basalto\Core\Http\Middleware\HandleBackendRequests;
use Basalto\Core\Http\Middleware\KeepCmsOffExtranet;
use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\Site;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Route;

/**
 * Die Schnittstelle, über die Kern und Module das Backend füllen (ADR-011): Seiten-Namensräume (Inertia), Module mit
 * Manifest, Menüeinträge je Modus (Inhalte, Einrichtung, Verwaltung) und Modul, Routen unter dem Backend-Pfad und die
 * Vite-Assets des Kerns (fertig gebaut, veröffentlicht nach public/vendor).
 */
final class Backend
{
    public const MODE_CONTENT = 'content';

    public const MODE_SETUP = 'setup';

    public const MODE_ADMIN = 'admin';

    /** @var array<string, string> Namensraum → Verzeichnis mit Vue-Seiten */
    private array $pages = [];

    /** @var list<array{label: string, route: string, order: int, mode: string, module: ?string, icon: ?string}> */
    private array $menu = [];

    /** @var array<string, array{handle: string, label: string, switchable: bool, manifest: array<string, mixed>, default: bool}> */
    private array $modules = [];

    /** @var array<string, Closure(Site): int> Route eines Menüeintrags → Zahl daran (z. B. wartende Kommentare eines Moduls) */
    private array $counts = [];

    /** @var array<string, Closure(?Authenticatable, ?Site): bool> TB 12 Scheibe 1: Route eines Menüeintrags → sichtbar für wen */
    private array $visibility = [];

    /** @var array<string, Closure(?Site): mixed> TB 13 Scheibe 3: geteilte Props der Module für Erweiterungen in Seiten des Kerns */
    private array $shared = [];

    public function pages(string $namespace, string $directory): self
    {
        $this->pages[$namespace] = $directory;

        return $this;
    }

    /** @return array<string, string> */
    public function pageNamespaces(): array
    {
        return $this->pages;
    }

    /**
     * Ein Modul anmelden: Handle, Label (Übersetzungsschlüssel), ob es je Site schaltbar ist, und sein Manifest aus
     * `extra.basalto` der composer.json (Name, Version der Schnittstelle, Lava-Namensraum). TB 11 Scheibe 1: `$enabledByDefault`
     * falsch heisst, das Modul ist auf einer Site erst an, wenn jemand es einschaltet (Lizenzverkauf nur auf basaltocms.com).
     */
    public function module(string $handle, string $label, bool $switchable = true, ?string $composerJson = null, bool $enabledByDefault = true): self
    {
        $manifest = [];
        if ($composerJson !== null && is_file($composerJson)) {
            $decoded = json_decode((string) file_get_contents($composerJson), true);
            $extra = is_array($decoded) ? ($decoded['extra']['basalto'] ?? null) : null;
            $manifest = is_array($extra) ? $extra : [];
        }
        $this->modules[$handle] = ['handle' => $handle, 'label' => $label, 'switchable' => $switchable, 'manifest' => $manifest, 'default' => $enabledByDefault];

        return $this;
    }

    /** @return list<array{handle: string, label: string, switchable: bool, manifest: array<string, mixed>, default: bool}> */
    public function modules(): array
    {
        return array_values($this->modules);
    }

    /** @param  string  $label  Übersetzungsschlüssel, wird beim Lesen übersetzt */
    public function menu(string $label, string $route, int $order = 100, string $mode = self::MODE_CONTENT, ?string $module = null, ?string $icon = null): self
    {
        $this->menu[] = ['label' => $label, 'route' => $route, 'order' => $order, 'mode' => $mode, 'module' => $module, 'icon' => $icon];

        return $this;
    }

    /** Eine Zahl am Menüeintrag eines Moduls, je Site — der Kern legt sie mit seinen eigenen kurz in den Cache. */
    public function count(string $route, Closure $count): self
    {
        $this->counts[$route] = $count;

        return $this;
    }

    /**
     * TB 12 Scheibe 1: Ein Menüeintrag nur unter einer Bedingung — z. B. «Konto» nur im SaaS-Modus für Konto-Rollen,
     * «Lizenz» nicht auf der SaaS. Wirkt auch auf Einträge, die ein anderer Anbieter angemeldet hat.
     *
     * @param  Closure(?Authenticatable, ?Site): bool  $visible
     */
    public function visibleWhen(string $route, Closure $visible): self
    {
        $this->visibility[$route] = $visible;

        return $this;
    }

    /**
     * TB 13 Scheibe 3: Ein Modul teilt Props mit dem Backend — unter `backend.extensions.<key>`, je Anfrage für die
     * aktuelle Site. Seiten des Kerns binden die Vue-Teile der Module über `ModuleSlot` ein (`resources/js/slots/<Name>.vue`
     * eines Moduls); die lesen hier, ob und wie sie sich zeigen. `null` heisst: auf dieser Site nicht da.
     *
     * @param  Closure(?Site): mixed  $props
     */
    public function share(string $key, Closure $props): self
    {
        $this->shared[$key] = $props;

        return $this;
    }

    /** @return array<string, mixed> TB 13 Scheibe 3: die geteilten Props aller Module für eine Site */
    public function shared(?Site $site): array
    {
        return array_map(static fn (Closure $props): mixed => $props($site), $this->shared);
    }

    /** @return array<string, int> Route → Zahl für die Menüeinträge der Module */
    public function counts(Site $site): array
    {
        return array_map(static fn (Closure $count): int => (int) $count($site), $this->counts);
    }

    /**
     * Alle Menüeinträge, die für eine Site gelten (Module je Site schaltbar), sortiert — flach.
     *
     * @return list<array{label: string, href: string, route: string, mode: string, module: ?string, icon: ?string}>
     */
    public function menuItems(?Site $site = null): array
    {
        $user = auth()->user();
        $items = array_values(array_filter($this->menu, fn (array $item): bool => ($item['module'] === null || $site === null || $this->moduleEnabled($item['module'], $site))
            && (! isset($this->visibility[$item['route']]) || ($this->visibility[$item['route']])($user, $site))
            // CR-005 (R-06): nur, was die Rolle mindestens ansehen darf
            && ($user === null || $site === null || $this->accessible($item['route'], $user, $site))));
        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static function (array $item): array {
            $label = __($item['label']);

            return ['label' => is_string($label) ? $label : $item['label'], 'href' => route($item['route']), 'route' => $item['route'], 'mode' => $item['mode'], 'module' => $item['module'], 'icon' => $item['icon']];
        }, $items);
    }

    /** Ob die Person die Route einer Menüzeile öffnen darf (CR-005). */
    public function accessible(string $route, Authenticatable $user, Site $site): bool
    {
        $requirement = app(Areas::class)->requirement($route, 'GET');
        if ($requirement === null) {
            return true;
        }
        $access = app(Access::class);

        return $requirement['group'] !== null ? $access->seesGroup($user, $site, $requirement['group']) : $access->allows($user, $site, (string) $requirement['area'], $requirement['level'] ?? Level::View);
    }

    /**
     * Menüeinträge je Modus: Inhalte (Redaktion), Einrichtung (rote Linie), Verwaltung.
     *
     * @return array<string, list<array{label: string, href: string, route: string, mode: string, module: ?string, icon: ?string}>>
     */
    public function menuByMode(?Site $site = null): array
    {
        $byMode = [self::MODE_CONTENT => [], self::MODE_SETUP => [], self::MODE_ADMIN => []];
        foreach ($this->menuItems($site) as $item) {
            $byMode[$item['mode']][] = $item;
        }

        return $byMode;
    }

    /** Module sind je Site schaltbar über `sites.settings.modules.<handle>`; ohne Eintrag gilt die Vorgabe des Moduls (meist an). */
    public function moduleEnabled(string $handle, Site $site): bool
    {
        $module = $this->modules[$handle] ?? null;
        if ($module === null || ! $module['switchable']) {
            return true;
        }

        return (bool) $site->setting('modules.'.$handle, $module['default']);
    }

    /** TB 11 Scheibe 1: Ist das Modul auf einer neuen Site ohne Eintrag an? */
    public function enabledByDefault(string $handle): bool
    {
        return $this->modules[$handle]['default'] ?? true;
    }

    public function path(): string
    {
        return trim((string) config('basalto.backend.path', 'admin'), '/');
    }

    /** @return list<string> */
    public function middleware(): array
    {
        // TB 12 Scheibe 1: GuardSaasInstanceAreas — Betrieb und Module auf der SaaS nur für den Betreiber der Installation
        // TB 12 Scheibe 4: GuardAccountLifecycle — gesperrt nur lesbar für den Besitzer, Umzug ohne Speichern, umgezogen → Zielinstanz
        // CR-005 (R-06): GuardAreaAccess — Rechte je Bereich an jeder Route
        // TB 19: KeepCmsOffExtranet — auf dem Host des Extranets kein CMS-Backend
        return ['web', 'auth', KeepCmsOffExtranet::class, EnsureAccountActive::class, SelectBackendSite::class, GuardAreaAccess::class, GuardSaasInstanceAreas::class, GuardAccountLifecycle::class, HandleBackendRequests::class];
    }

    /** Routen eines Moduls unter dem Backend-Pfad, mit Backend-Middleware und Namenspräfix «basalto.». */
    public function routes(Closure $routes): void
    {
        Route::middleware($this->middleware())->prefix($this->path())->name('basalto.')->group($routes);
    }

    /**
     * Die Backend-Assets: im Monorepo aus packages/core/resources/dist (Hot-Datei für «npm run dev:backend»),
     * in einer Installation aus public/vendor/basalto/core — ohne Node auf dem Zielsystem.
     */
    public function vite(): Vite
    {
        $vite = clone app(Vite::class);

        return $vite
            ->useHotFile(dirname(__DIR__, 2).'/resources/dist/hot')
            ->useBuildDirectory('vendor/basalto/core/build')
            ->withEntryPoints(['resources/css/backend.css', 'resources/js/backend.ts']);
    }
}
