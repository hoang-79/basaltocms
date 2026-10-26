<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Access\Level;
use Basalto\Core\Analytics\Period;
use Basalto\Core\Extranet\Statistics\MetricSection;
use Basalto\Core\Models\Site;
use Basalto\Core\Support\Text;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Was Kern und Module ins Extranet einhängen (CR-004, P-09–P-15): Menü, Aufgaben der Übersicht «Heute zu tun»,
 * Routen, Kunden- und Rechnungsquellen, Abschnitte der Statistik (TB 55). Module melden sich in `boot()` an; ohne
 * Extranet bleibt alles ungenutzt.
 */
final class ExtranetRegistry
{
    /** @var list<array{label: string, route: string, order: int, area: string|null, icon: string}> */
    private array $menu = [];

    /** @var list<array{key: string, label: string, area: string, count: Closure(): int, href: Closure(): string}> */
    private array $todos = [];

    /** @var list<Closure(): void> */
    private array $routes = [];

    /** @var array<string, CustomerSource> */
    private array $customers = [];

    /** @var array<string, InvoiceSource> */
    private array $invoices = [];

    /** @var array<string, Closure(): int> */
    private array $counts = [];

    /** @var array<string, array{key: string, label: string, area: string|null, order: int, build: Closure(Period, Site): MetricSection}> */
    private array $statistics = [];

    /** Menüpunkt; ohne Bereich für jedes Teammitglied sichtbar. `$label` ist der deutsche Text. */
    public function menu(string $label, string $route, int $order, ?string $area, string $icon = 'module'): self
    {
        $this->menu[] = ['label' => $label, 'route' => $route, 'order' => $order, 'area' => $area, 'icon' => $icon];

        return $this;
    }

    /** Zahl am Menüpunkt (nur über 0 sichtbar). */
    public function count(string $route, Closure $count): self
    {
        $this->counts[$route] = $count;

        return $this;
    }

    /**
     * Aufgabe der Übersicht: eine Zahl mit Link auf die passend gefilterte Liste (P-10).
     *
     * @param  Closure(): int  $count
     * @param  Closure(): string  $href
     */
    public function todo(string $key, string $label, string $area, Closure $count, Closure $href): self
    {
        $this->todos[] = ['key' => $key, 'label' => $label, 'area' => $area, 'count' => $count, 'href' => $href];

        return $this;
    }

    /**
     * Abschnitt der Statistik (TB 55) — ein Reiter mit Kennzahlen, Verläufen und Ranglisten aus Daten des Moduls.
     * Sichtbar, wer den Bereich mindestens ansehen darf (ohne Bereich: alle mit Zugang zur Statistik).
     * `$label` ist der deutsche Text.
     *
     * @param  Closure(Period, Site): MetricSection  $build
     */
    public function statistics(string $key, string $label, ?string $area, int $order, Closure $build): self
    {
        $this->statistics[$key] = ['key' => $key, 'label' => $label, 'area' => $area, 'order' => $order, 'build' => $build];

        return $this;
    }

    /**
     * Abschnitte der Statistik für eine Person, in ihrer Reihenfolge.
     *
     * @return list<array{key: string, label: string, area: string|null, order: int, build: Closure(Period, Site): MetricSection}>
     */
    public function statisticsFor(Authenticatable $user): array
    {
        $access = app(ExtranetAccess::class);
        $sections = array_values(array_filter($this->statistics, static fn (array $s): bool => $s['area'] === null || $access->allows($user, $s['area'], Level::View)));
        usort($sections, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $sections;
    }

    /** Routen im Extranet (Host, Anmeldung und Rechte stellt der Kern). */
    public function routes(Closure $routes): self
    {
        $this->routes[] = $routes;

        return $this;
    }

    public function customers(CustomerSource $source): self
    {
        $this->customers[$source->key()] = $source;

        return $this;
    }

    public function invoices(InvoiceSource $source): self
    {
        $this->invoices[$source->key()] = $source;

        return $this;
    }

    /** @return list<Closure(): void> */
    public function routeGroups(): array
    {
        return $this->routes;
    }

    /** @return array<string, CustomerSource> */
    public function customerSources(): array
    {
        return $this->customers;
    }

    public function customerSource(string $key): ?CustomerSource
    {
        return $this->customers[$key] ?? null;
    }

    /** @return array<string, InvoiceSource> */
    public function invoiceSources(): array
    {
        return $this->invoices;
    }

    public function invoiceSource(string $key): ?InvoiceSource
    {
        return $this->invoices[$key] ?? null;
    }

    /**
     * Menü für eine Person — nur Bereiche, die sie mindestens ansehen darf.
     *
     * @return list<array{label: string, href: string, route: string, icon: string, count: int|null}>
     */
    public function menuFor(Authenticatable $user): array
    {
        $access = app(ExtranetAccess::class);
        $items = array_filter($this->menu, static fn (array $item): bool => $item['area'] === null || $access->allows($user, $item['area']));
        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(function (array $item): array {
            $count = isset($this->counts[$item['route']]) ? ($this->counts[$item['route']])() : null;

            return ['label' => Text::t($item['label']), 'href' => route($item['route']), 'route' => $item['route'], 'icon' => $item['icon'], 'count' => $count !== null && $count > 0 ? $count : null];
        }, $items);
    }

    /**
     * Aufgaben für eine Person — nur aus Bereichen, die sie ansehen darf.
     *
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    public function todosFor(Authenticatable $user): array
    {
        $access = app(ExtranetAccess::class);
        $out = [];
        foreach ($this->todos as $todo) {
            if ($access->allows($user, $todo['area'], Level::View)) {
                $out[] = ['key' => $todo['key'], 'label' => Text::t($todo['label']), 'count' => ($todo['count'])(), 'href' => ($todo['href'])()];
            }
        }

        return $out;
    }
}
