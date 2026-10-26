<?php

declare(strict_types=1);

namespace Basalto\Core\Backend;

use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Der Fahrplan der Einrichtung (TB 23): neun Stationen von «Was ist die Site?» bis «Bereit für live?» — jede mit der
 * Frage, die sie beantwortet, dem Fachbegriff, einer Erklärung, dem Stand aus der roten Linie (A–Z, wie
 * `basalto:check`) und den Seiten, die dazu gehören. Dazu der Abzweig «Code» (Theme-Editor) und der Umsteigepunkt
 * «Themes». Was die Rolle nicht öffnen darf, bleibt ohne Link (CR-005).
 *
 * @phpstan-type LineRow array{letter: string, keyword: string, status: string, summary: string}
 * @phpstan-type Station array{key: string, question: string, term: string, explain: string, action: string, letters: list<string>, status: string, findings: list<array{letter: string, keyword: string, status: string, summary: string}>, href: string|null, links: list<array{label: string, href: string}>}
 */
final class SetupJourney
{
    /**
     * Stationen: Schlüssel, Buchstaben der roten Linie, Hauptseite, weitere Seiten (Route, Bezeichnung) und Routen, die
     * zur Station gehören (für «hier sind Sie»).
     *
     * @var list<array{key: string, letters: list<string>, route: string|null, params?: array<string, string>, links: list<array{0: string, 1: string, 2?: array<string, string>}>, routes: list<string>}>
     */
    private const STATIONS = [
        ['key' => 'site', 'letters' => ['A'], 'route' => 'basalto.sites.edit', 'links' => [], 'routes' => ['basalto.sites.edit']],
        ['key' => 'look', 'letters' => ['B'], 'route' => 'basalto.setup.design', 'links' => [['basalto.setup.themes', 'Themes wählen und hinzufügen']], 'routes' => ['basalto.setup.design', 'basalto.setup.themes']],
        ['key' => 'build', 'letters' => ['C', 'G'], 'route' => 'basalto.setup.layouts.index', 'links' => [['basalto.setup.layouts.index', 'Templates', ['tab' => 'templates']], ['basalto.setup.layouts.index', 'Teile', ['tab' => 'parts']]], 'routes' => ['basalto.setup.layouts.']],
        ['key' => 'nav', 'letters' => ['D'], 'route' => 'basalto.setup.navigation.index', 'links' => [], 'routes' => ['basalto.setup.navigation.']],
        ['key' => 'types', 'letters' => ['E'], 'route' => 'basalto.setup.types.index', 'links' => [], 'routes' => ['basalto.setup.types.']],
        ['key' => 'blocks', 'letters' => ['F'], 'route' => 'basalto.setup.snippets.index', 'links' => [['basalto.setup.theme-editor', 'Blöcke im Code ansehen']], 'routes' => ['basalto.setup.snippets.']],
        ['key' => 'forms', 'letters' => ['H'], 'route' => 'basalto.forms.index', 'links' => [], 'routes' => []],
        ['key' => 'seed', 'letters' => ['I'], 'route' => null, 'links' => [['basalto.pages.index', 'Seiten ansehen']], 'routes' => []],
        ['key' => 'live', 'letters' => ['Z'], 'route' => 'basalto.setup.publish', 'links' => [['basalto.setup', 'Prüfbericht A–Z']], 'routes' => ['basalto.setup.publish', 'basalto.setup']],
    ];

    public function __construct(private readonly Backend $backend) {}

    /**
     * @param  list<LineRow>  $line
     * @return array{stations: list<Station>, active: string|null, branch: array{label: string, question: string, href: string|null, active: bool}, interchange: array{label: string, href: string|null}}
     */
    public function build(Site $site, ?Authenticatable $user, array $line, string $routeName = ''): array
    {
        $active = null;
        $byLetter = [];
        foreach ($line as $row) {
            $byLetter[$row['letter']] = $row;
        }
        $texts = self::texts();
        $stations = [];
        foreach (self::STATIONS as $station) {
            $rows = array_values(array_filter(array_map(static fn (string $l): ?array => $byLetter[$l] ?? null, $station['letters'])));
            $links = [];
            foreach ($station['links'] as $link) {
                if ($this->can($link[0], $user, $site)) {
                    $links[] = ['label' => (string) __($link[1]), 'href' => route($link[0], $link[2] ?? [])];
                }
            }
            $route = $station['route'];
            foreach ($station['routes'] as $prefix) {
                if ($routeName === $prefix || (str_ends_with($prefix, '.') && str_starts_with($routeName, $prefix))) {
                    $active = $station['key'];
                }
            }
            $stations[] = [
                'key' => $station['key'],
                'question' => $texts[$station['key']][0],
                'term' => $texts[$station['key']][1],
                'explain' => $texts[$station['key']][2],
                'action' => $texts[$station['key']][3],
                'letters' => $station['letters'],
                'status' => self::worst($rows),
                'findings' => $rows,
                'href' => $route !== null && $this->can($route, $user, $site) ? ($route === 'basalto.sites.edit' ? route($route, $site) : route($route)) : null,
                'links' => $links,
            ];
        }

        return [
            'stations' => $stations,
            'active' => $active,
            'branch' => ['label' => (string) __('Code'), 'question' => (string) __('Alles selbst schreiben?'), 'href' => $this->can('basalto.setup.theme-editor', $user, $site) ? route('basalto.setup.theme-editor') : null,
                'active' => str_starts_with($routeName, 'basalto.setup.theme-editor')],
            'interchange' => ['label' => (string) __('Themes'), 'href' => $this->can('basalto.setup.themes', $user, $site) ? route('basalto.setup.themes') : null],
        ];
    }

    /**
     * Schlechtester Stand der Buchstaben einer Station: danger vor warning vor waiting vor success.
     *
     * @param  list<LineRow>  $rows
     */
    public static function worst(array $rows): string
    {
        $rank = ['success' => 0, 'waiting' => 1, 'warning' => 2, 'danger' => 3];
        $worst = $rows === [] ? 'waiting' : 'success';
        foreach ($rows as $row) {
            $state = match ($row['status']) {
                '✓' => 'success',
                '✗' => 'danger',
                '!' => 'warning',
                default => 'waiting',
            };
            if ($rank[$state] > $rank[$worst]) {
                $worst = $state;
            }
        }

        return $worst;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> Frage, Begriff, Erklärung, Knopf je Station */
    private static function texts(): array
    {
        return [
            'site' => [(string) __('Was ist die Site?'), (string) __('Site'), (string) __('Name, Sprachen, Domains und Zeitzone — die Grundlage für alles Weitere.'), (string) __('Site bearbeiten')],
            'look' => [(string) __('Wie sieht sie aus?'), (string) __('Theme · Design'), (string) __('Das Theme gibt die Gestalt vor; Farben, Schriften und Logo passen Sie hier an, ohne Code.'), (string) __('Design anpassen')],
            'build' => [(string) __('Wie ist sie gebaut?'), (string) __('Layouts · Templates'), (string) __('Layouts teilen die Seite in Bereiche, Templates bestimmen, was in welchem Bereich steht.'), (string) __('Layouts öffnen')],
            'nav' => [(string) __('Wie findet man sich zurecht?'), (string) __('Menüs'), (string) __('Hauptmenü, Fusszeile und weitere Menüs — aus dem Seitenbaum oder von Hand.'), (string) __('Menüs bearbeiten')],
            'types' => [(string) __('Was lässt sich pflegen?'), (string) __('Inhaltstypen'), (string) __('Seiten, Beiträge und eigene Typen mit ihren Feldern — das, was die Redaktion ausfüllt.'), (string) __('Inhaltstypen öffnen')],
            'blocks' => [(string) __('Woraus bestehen Seiten?'), (string) __('Blöcke · Bausteine'), (string) __('Blöcke kommen aus dem Theme; Bausteine sind fertige Inhalte, die Sie auf vielen Seiten wiederverwenden.'), (string) __('Bausteine verwalten')],
            'forms' => [(string) __('Was können Besucher senden?'), (string) __('Formulare'), (string) __('Kontakt, Anmeldung und mehr — mit Eingängen, Spamschutz und Benachrichtigung.'), (string) __('Formulare öffnen')],
            'seed' => [(string) __('Womit startet sie?'), (string) __('Startinhalte'), (string) __('Das Theme kann Beispielseiten mitbringen, damit eine neue Site nicht leer beginnt.'), (string) __('Seiten ansehen')],
            'live' => [(string) __('Bereit für live?'), (string) __('Veröffentlichen'), (string) __('Erst wenn alle Stationen stimmen, geht die Site für Gäste online.'), (string) __('Veröffentlichen')],
        ];
    }

    private function can(string $route, ?Authenticatable $user, Site $site): bool
    {
        return Route::has($route) && ($user === null || $this->backend->accessible($route, $user, $site));
    }
}
