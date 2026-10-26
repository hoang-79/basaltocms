<?php

declare(strict_types=1);

namespace Basalto\Core\Access;

/**
 * Verzeichnis der Bereiche (CR-005, R-01): Kern und Module melden ihre Bereiche mit Gruppe an (Inhalte, Einrichtung,
 * Verwaltung) und ordnen ihre Backend-Routen zu — nach Präfix des Routennamens, mit Ausnahmen für einzelne Routen.
 * Die Middleware `GuardAreaAccess`, das Menü und der Modus-Schalter fragen hier nach.
 *
 * @phpstan-type AreaInfo array{key: string, label: string, group: string, module: string|null}
 * @phpstan-type Requirement array{area: string|null, group: string|null, level: Level|null}
 */
final class Areas
{
    public const CONTENT = 'content';

    public const SETUP = 'setup';

    public const ADMIN = 'admin';

    /** CR-004 (P-09, R-12): Bereiche des Hersteller-Extranets — eigene Schicht, dieselben Stufen und Rollen */
    public const EXTRANET = 'extranet';

    public const GROUPS = [self::CONTENT, self::SETUP, self::ADMIN, self::EXTRANET];

    /** @var array<string, AreaInfo> */
    private array $areas = [];

    /** @var array<string, string> Präfix des Routennamens → Bereich */
    private array $prefixes = [];

    /** @var array<string, array{area: string, level: Level|null}> Routenname → Bereich und Stufe (Ausnahme) */
    private array $routes = [];

    /** @var array<string, string> Routenname → Gruppe (offen, wenn ein Bereich der Gruppe sichtbar ist) */
    private array $groupRoutes = [];

    /** @var list<string> Routennamen oder Präfixe ohne Bereich (Anmeldung, Profil, Übersicht …) */
    private array $open = [];

    /** Bereich anmelden. `$label` ist der deutsche Text (Schlüssel der Übersetzung). */
    public function register(string $key, string $label, string $group, ?string $module = null): self
    {
        $this->areas[$key] = ['key' => $key, 'label' => $label, 'group' => $group, 'module' => $module];

        return $this;
    }

    /** Alle Routen, deren Name so beginnt, gehören zum Bereich. */
    public function map(string $prefix, string $area): self
    {
        $this->prefixes[$prefix] = $area;

        return $this;
    }

    /** Eine Route verlangt einen bestimmten Bereich und, wenn angegeben, eine bestimmte Stufe. */
    public function route(string $name, string $area, ?Level $level = null): self
    {
        $this->routes[$name] = ['area' => $area, 'level' => $level];

        return $this;
    }

    /** Eine Route ist offen, sobald die Person einen Bereich der Gruppe sieht (z. B. die Übersicht der Einrichtung). */
    public function groupRoute(string $name, string $group): self
    {
        $this->groupRoutes[$name] = $group;

        return $this;
    }

    /** Routen ohne Bereich: für alle Angemeldeten (Übersicht, Profil, Abmelden …). */
    public function open(string ...$namesOrPrefixes): self
    {
        array_push($this->open, ...$namesOrPrefixes);

        return $this;
    }

    /** @return array<string, AreaInfo> */
    public function all(): array
    {
        return $this->areas;
    }

    /** @return AreaInfo|null */
    public function get(string $key): ?array
    {
        return $this->areas[$key] ?? null;
    }

    /** @return list<string> */
    public function inGroup(string $group): array
    {
        return array_keys(array_filter($this->areas, static fn (array $a): bool => $a['group'] === $group));
    }

    /**
     * Was eine Route verlangt — oder null, wenn sie offen ist. Unbekannte Routen verlangen den Bereich «settings»
     * mit «alles» (lieber zu streng als zu offen); der Abdeckungstest findet sie.
     *
     * @return Requirement|null
     */
    public function requirement(string $routeName, string $method): ?array
    {
        if (isset($this->routes[$routeName])) {
            return ['area' => $this->routes[$routeName]['area'], 'group' => null, 'level' => $this->routes[$routeName]['level'] ?? Level::forMethod($method)];
        }
        if (isset($this->groupRoutes[$routeName])) {
            return ['area' => null, 'group' => $this->groupRoutes[$routeName], 'level' => Level::View];
        }
        foreach ($this->open as $open) {
            if ($routeName === $open || (str_ends_with($open, '.') && str_starts_with($routeName, $open))) {
                return null;
            }
        }
        $best = null;
        foreach ($this->prefixes as $prefix => $area) {
            if (($routeName === rtrim($prefix, '.') || str_starts_with($routeName, $prefix)) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? ['area' => 'settings', 'group' => null, 'level' => Level::All]
            : ['area' => $this->prefixes[$best], 'group' => null, 'level' => Level::forMethod($method)];
    }

    /** Ob die Route ausdrücklich zugeordnet ist (Abdeckungstest, R-01). */
    public function covers(string $routeName): bool
    {
        if (isset($this->routes[$routeName]) || isset($this->groupRoutes[$routeName])) {
            return true;
        }
        foreach ($this->open as $open) {
            if ($routeName === $open || (str_ends_with($open, '.') && str_starts_with($routeName, $open))) {
                return true;
            }
        }
        foreach (array_keys($this->prefixes) as $prefix) {
            if ($routeName === rtrim($prefix, '.') || str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
