<?php

declare(strict_types=1);

namespace Basalto\Core\Help;

use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Support\Text;
use Illuminate\Support\Str;

/**
 * Redaktions-Hilfe im Produkt (N-09): die Markdown-Seiten aus `docs/redaktion/` im Backend gelesen und als HTML
 * ausgegeben, dazu je Backend-Route die passende Seite für das «?» im Seitenkopf. Die Dateien liegen im Repository
 * (und in jeder Installation aus dem Skelett); fehlt der Ordner, gibt es kein «?» und `/admin/hilfe` antwortet 404.
 *
 * Sprache: Die Hilfe folgt der Sprache der Oberfläche. Englisch, Französisch und Italienisch liegen mit denselben
 * Dateinamen in `docs/en/`, `docs/fr/` und `docs/it/` (allgemein: `<sprache>/` vor dem letzten Ordner des deutschen
 * Pfads). Fehlt eine Seite, sucht die Hilfe auf Englisch — wie `app.fallback_locale` — und erst dann auf Deutsch.
 *
 * Rohes HTML im Markdown wird entfernt, unsichere Links (javascript:) ebenso. Links auf eine andere Hilfeseite
 * (`medien.md`, `medien.md#alt-text`) zeigen auf die Hilfe im Backend; alle übrigen bleiben, wie sie sind.
 *
 * Webhilfe (TB 20): Neben der Redaktion liest die Hilfe auch Betrieb, Entwickler und Marktplatz — die Ordner neben
 * `redaktion/` unter `docs/`. Deren Seiten heissen wie auf der Doku-Site (`betrieb-rollen`, `betrieb` für das
 * README), die der Redaktion bleiben ohne Präfix. Links zwischen den Bereichen (`../betrieb/rollen.md`) bleiben in
 * der Hilfe, Links auf andere Dateien verlieren den Link (wie bei `basalto:docs-import`). Überschriften
 * `##` und `###` bekommen Anker wie auf GitHub, damit `rollen.md#vorlagen` trifft; `search()` sucht in Titeln,
 * Überschriften und Text aller Seiten für das Hilfe-Panel.
 */
final class HelpPages
{
    /** Reihenfolge der Seiten in der Navigation der Hilfe — TB 13 Scheibe 3: «ki» (Modul KI-Vorschläge) dazu,
     * TB 13 Scheibe 2: «mitglieder». */
    public const ORDER = ['index', 'seiten', 'bloecke', 'medien', 'uebersetzen', 'freigabe', 'formulare', 'kommentare', 'mitglieder', 'frontend-editor', 'ki'];

    /** Sprache der Oberfläche → Sprachen, in denen die Hilfe gesucht wird (die erste vorhandene Datei gewinnt). */
    public const FALLBACKS = [
        'de' => ['de'],
        'en' => ['en', 'de'],
        'it' => ['it', 'en', 'de'],
        'fr' => ['fr', 'en', 'de'],
    ];

    /**
     * Bereiche der Hilfe: Ordner unter `docs/` in der Reihenfolge der Navigation; die Redaktion ist die Quelle.
     * «hersteller» ist die Hilfe des Teams von basaltocms.com — nur im Extranet sichtbar (VENDOR).
     */
    public const SECTIONS = ['einstieg', 'redaktion', 'einrichtung', 'verwaltung', 'community', 'marktplatz', 'entwickler', 'betrieb', 'hersteller'];

    /** Bereich, den nur das Extranet zeigt. */
    public const VENDOR = 'hersteller';

    /** Reihenfolge in Bereichen mit eigener Ordnung (sonst nach Dateiname); die Einrichtung folgt dem Fahrplan (TB 29). */
    public const SECTION_ORDER = [
        'einstieg' => ['README', 'was-ist-basalto', 'begriffe', 'installieren', 'rundgang', 'erste-seite', 'menue-und-sprachen', 'team', 'naechste-schritte'],
        'einrichtung' => ['README', 'site', 'aussehen', 'aufbau', 'menues', 'inhaltstypen', 'bausteine', 'entwuerfe', 'live-gehen'],
        'verwaltung' => ['README', 'sites', 'kundenanmeldung', 'benutzer', 'rollen', 'einstellungen', 'statistik', 'updates', 'lizenz', 'betrieb', 'protokoll'],
        // CR-006 (TB 41, FO-27): die Community — vom Mitlesen bis zur Verwaltung
        'community' => ['README', 'forum', 'live', 'profile', 'moderation', 'verwaltung'],
    ];

    /** Routenname (Präfix) → Hilfeseite; der längste passende Präfix gewinnt. */
    public const ROUTES = [
        'basalto.dashboard' => 'index',
        'basalto.pages.' => 'seiten',
        'basalto.entries.' => 'bloecke',
        'basalto.entries.translation' => 'uebersetzen',
        'basalto.entries.review' => 'freigabe',
        'basalto.translations.' => 'uebersetzen',
        'basalto.reviews.' => 'freigabe',
        'basalto.media.' => 'medien',
        'basalto.forms.' => 'formulare',
        'basalto.comments.' => 'kommentare',
        // TB 13 Scheibe 2: Inhalte › Mitglieder (Modul «Mitglieder»)
        'basalto.members.' => 'mitglieder',
        'basalto.help' => 'index',
        // TB 27: Center der Benachrichtigungen — Abschnitt in der Übersicht der Redaktions-Hilfe
        'basalto.notifications.' => 'index',
        // TB 29: Profil und Passkeys — Abschnitt «Ihr Konto» der Redaktions-Hilfe
        'basalto.profile' => 'index',
        'basalto.passkeys' => 'index',
        // TB 13 Scheibe 3: Verwaltung › KI-Vorschläge
        'basalto.ai.' => 'ki',
        // TB 12 Scheibe 3: Konto › Domains der SaaS (nicht in ORDER — Download-Installationen haben keinen Konto-Bereich)
        'basalto.saas.account.domains' => 'domains',
        // TB 12 Scheibe 4: Konto › Export und Kündigung der SaaS (ebenfalls nicht in ORDER)
        'basalto.saas.account.exit' => 'konto',
        // TB 29: Einrichtung ohne Code — eigener Bereich; nur der Theme-Editor bleibt bei den Entwicklern
        'basalto.setup' => 'einrichtung',
        'basalto.sites.' => 'einrichtung-site',
        // TB 29: Verwaltung im Browser — eigener Bereich in Sie-Form; die Technik bleibt in «betrieb»
        'basalto.sites.index' => 'verwaltung-sites',
        'basalto.sites.create' => 'verwaltung-sites',
        'basalto.sites.sso' => 'betrieb-sso',
        // TB 53: Anmeldung für Kundenkonten (Google, Microsoft, GitHub, LinkedIn)
        'basalto.sites.social' => 'verwaltung-kundenanmeldung',
        'basalto.setup.themes' => 'einrichtung-aussehen',
        'basalto.setup.design' => 'einrichtung-aussehen',
        'basalto.setup.variants.' => 'einrichtung-aussehen',
        'basalto.setup.layouts.' => 'einrichtung-aufbau',
        'basalto.setup.parts.' => 'einrichtung-aufbau',
        'basalto.setup.templates.' => 'einrichtung-aufbau',
        'basalto.setup.navigation.' => 'einrichtung-menues',
        'basalto.setup.types.' => 'einrichtung-inhaltstypen',
        'basalto.setup.snippets.' => 'einrichtung-bausteine',
        'basalto.setup.publish' => 'einrichtung-live-gehen',
        'basalto.setup.legal' => 'einrichtung-live-gehen',
        'basalto.setup.theme-editor' => 'entwickler-themes',
        'basalto.modules' => 'marktplatz-installieren',
        'basalto.roles.' => 'verwaltung-rollen',
        'basalto.users.' => 'verwaltung-benutzer',
        'basalto.invitations.' => 'verwaltung-benutzer',
        'basalto.settings.' => 'verwaltung-einstellungen',
        'basalto.license' => 'verwaltung-lizenz',
        'basalto.operations' => 'verwaltung-betrieb',
        'basalto.updates' => 'verwaltung-updates',
        'basalto.status' => 'verwaltung-betrieb',
        'basalto.activity.' => 'verwaltung-protokoll',
        // TB 54: Statistik der Website
        'basalto.statistics' => 'verwaltung-statistik',
        // TB 29: das Extranet des Herstellers — Seiten des Teams in der Betriebs-Doku
        'extranet.' => 'hersteller-extranet',
        'extranet.saas.' => 'hersteller-saas',
        // TB 55: Statistik im Extranet
        'extranet.statistics.' => 'hersteller-statistik',
        'extranet.notifications.' => 'index',
        'basalto.marketplace.' => 'hersteller-marktplatz',
        'basalto.licensing.' => 'hersteller-lizenzverkauf',
        // CR-006 (TB 41): Verwaltung der Community — im Extranet und im Backend der Kunden-Sites
        'basalto.forum.' => 'community-verwaltung',
        'basalto.forumadmin.' => 'community-verwaltung',
        'basalto.forum.moderation' => 'community-moderation',
        'basalto.forumadmin.moderation' => 'community-moderation',
        'basalto.forum.members' => 'community-moderation',
        'basalto.forumadmin.members' => 'community-moderation',
        'basalto.forum.recognition' => 'community-profile',
        'basalto.forumadmin.recognition' => 'community-profile',
    ];

    /**
     * Der Ordner der Hilfe in einer Sprache; Deutsch ist die Quelle, die übrigen liegen in `<sprache>/` daneben. Die
     * übrigen Bereiche liegen neben dem Ordner der Redaktion.
     */
    public static function directory(string $locale = 'de', string $section = 'redaktion'): string
    {
        $configured = config('basalto.help_path');
        $source = is_string($configured) && $configured !== '' ? rtrim($configured, '/') : base_path('docs/redaktion');
        if ($section !== 'redaktion') {
            $source = dirname($source).'/'.$section;
        }

        return $locale === 'de' ? $source : dirname($source).'/'.$locale.'/'.basename($source);
    }

    /** Die Seite einer Datei: `redaktion/medien` → `medien`, `betrieb/rollen` → `betrieb-rollen`, `betrieb/README` → `betrieb`. */
    public static function slugFor(string $section, string $name): string
    {
        if ($section === 'redaktion') {
            return $name;
        }

        return strtolower($name) === 'readme' ? $section : $section.'-'.$name;
    }

    /**
     * Bereich und Dateiname (ohne `.md`) einer Seite.
     *
     * @return array{0: string, 1: string}
     */
    public static function locate(string $slug): array
    {
        foreach (self::SECTIONS as $section) {
            if ($section === 'redaktion') {
                continue;
            }
            if ($slug === $section) {
                return [$section, 'README'];
            }
            if (str_starts_with($slug, $section.'-')) {
                return [$section, substr($slug, strlen($section) + 1)];
            }
        }

        return ['redaktion', $slug];
    }

    /** Name des Bereichs in der Sprache der Oberfläche. */
    public static function sectionLabel(string $section): string
    {
        return match ($section) {
            'einstieg' => Text::t('Einstieg in Basalto'),
            'hersteller' => Text::t('Hersteller'),
            'einrichtung' => Text::t('Einrichtung'),
            'verwaltung' => Text::t('Verwaltung'),
            'betrieb' => Text::t('Betrieb'),
            'entwickler' => Text::t('Entwickler'),
            'marktplatz' => Text::t('Marktplatz'),
            'community' => Text::t('Community'),
            default => Text::t('Redaktion'),
        };
    }

    /**
     * Die Sprachen, in denen die Hilfe für die Oberflächensprache gesucht wird — in dieser Reihenfolge.
     *
     * @return list<string>
     */
    public static function locales(?string $locale = null): array
    {
        return self::FALLBACKS[$locale ?? app()->getLocale()] ?? self::FALLBACKS['de'];
    }

    public static function exists(string $slug): bool
    {
        if (self::locate($slug)[0] === self::VENDOR && ! self::vendorVisible()) {
            return false;
        }

        return self::file($slug) !== null;
    }

    /** Die Hilfe des Herstellers zeigt nur das Extranet. */
    public static function vendorVisible(): bool
    {
        return Extranet::isRequest(request());
    }

    /** Die Hilfeseite zu einer Backend-Route, sofern es sie gibt. */
    public static function forRoute(?string $route): ?string
    {
        if ($route === null || $route === '') {
            return null;
        }
        $best = null;
        $length = 0;
        foreach (self::ROUTES as $prefix => $slug) {
            if (($route === $prefix || (str_ends_with($prefix, '.') && str_starts_with($route, $prefix)) || str_starts_with($route, $prefix.'.')) && strlen($prefix) > $length) {
                $best = $slug;
                $length = strlen($prefix);
            }
        }

        return $best !== null && self::exists($best) ? $best : null;
    }

    /**
     * Alle Bereiche mit ihren Seiten — die Redaktion in der Reihenfolge von ORDER, die übrigen mit dem README zuerst,
     * dann nach Dateiname (aus dem deutschen Ordner, der die Quelle ist).
     *
     * @return list<array{key: string, label: string, pages: list<array{slug: string, title: string}>}>
     */
    public static function sections(): array
    {
        $sections = [];
        foreach (self::SECTIONS as $section) {
            if ($section === self::VENDOR && ! self::vendorVisible()) {
                continue;
            }
            $pages = $section === 'redaktion' ? self::index() : [];
            if ($section !== 'redaktion') {
                $names = array_map(static fn (string $file): string => basename($file, '.md'), glob(self::directory('de', $section).'/*.md') ?: []);
                $order = array_flip(self::SECTION_ORDER[$section] ?? []);
                usort($names, static fn (string $a, string $b): int => [strtolower($a) !== 'readme', $order[$a] ?? PHP_INT_MAX, $a] <=> [strtolower($b) !== 'readme', $order[$b] ?? PHP_INT_MAX, $b]);
                foreach ($names as $name) {
                    $slug = self::slugFor($section, $name);
                    if (self::exists($slug)) {
                        $pages[] = ['slug' => $slug, 'title' => self::title(self::source($slug), $slug)];
                    }
                }
            }
            if ($pages !== []) {
                $sections[] = ['key' => $section, 'label' => self::sectionLabel($section), 'pages' => $pages];
            }
        }

        return $sections;
    }

    /**
     * Volltextsuche für das Hilfe-Panel: jede Seite in Abschnitte (`##`) zerlegt; alle Wörter der Anfrage müssen im
     * Abschnitt (mit Seitentitel) vorkommen. Treffer im Titel wiegen mehr als in Überschriften, die mehr als im Text.
     *
     * @return list<array{slug: string, title: string, section: string, heading: string|null, anchor: string|null, snippet: string}>
     */
    public static function search(string $query, int $limit = 12): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn (string $term): bool => mb_strlen($term) >= 2));
        if ($terms === []) {
            return [];
        }
        $hits = [];
        foreach (self::sections() as $section) {
            foreach ($section['pages'] as $page) {
                $markdown = (string) preg_replace('/\A\s*# [^\n]*\n/', '', self::source($page['slug']), 1);
                foreach (self::chunks($markdown) as [$heading, $text]) {
                    $plain = self::plain($text);
                    $title = mb_strtolower($page['title']);
                    $head = mb_strtolower((string) $heading);
                    $body = mb_strtolower($plain);
                    $score = 0;
                    foreach ($terms as $term) {
                        $inTitle = str_contains($title, $term);
                        $inHeading = str_contains($head, $term);
                        $count = substr_count($body, $term);
                        if (! $inTitle && ! $inHeading && $count === 0) {
                            continue 2;
                        }
                        $score += ($inTitle ? 10 : 0) + ($inHeading ? 6 : 0) + min($count, 5);
                    }
                    $hits[] = ['score' => $score, 'slug' => $page['slug'], 'title' => $page['title'], 'section' => $section['label'],
                        'heading' => $heading, 'anchor' => $heading === null ? null : self::anchor($heading), 'snippet' => self::snippet($plain, $terms)];
                }
            }
        }
        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        // Je Seite höchstens zwei Abschnitte, damit eine lange Seite die Liste nicht füllt
        $perPage = [];
        $results = [];
        foreach ($hits as $hit) {
            $perPage[$hit['slug']] = ($perPage[$hit['slug']] ?? 0) + 1;
            if ($perPage[$hit['slug']] > 2) {
                continue;
            }
            unset($hit['score']);
            $results[] = $hit;
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /** Anker einer Überschrift wie auf GitHub: klein, Satzzeichen weg, Leerzeichen zu Bindestrichen. */
    public static function anchor(string $heading): string
    {
        $text = mb_strtolower(trim(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5)));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $text);

        return (string) preg_replace('/\s/u', '-', $text);
    }

    /** @return list<array{slug: string, title: string}> */
    public static function index(): array
    {
        $pages = [];
        foreach (self::ORDER as $slug) {
            if (self::exists($slug)) {
                $pages[] = ['slug' => $slug, 'title' => self::title(self::source($slug), $slug)];
            }
        }

        return $pages;
    }

    /**
     * Die Seite als HTML; `locale` ist die Sprache der gezeigten Datei (kann vom Rückfall stammen).
     *
     * @return array{slug: string, title: string, html: string, locale: string}
     */
    public static function render(string $slug): array
    {
        $file = self::file($slug);
        $markdown = $file === null ? '' : (string) file_get_contents($file['path']);
        $title = self::title($markdown, $slug);
        // Der Titel steht im Seitenkopf — die erste Überschrift nicht doppelt zeigen
        $body = (string) preg_replace('/\A\s*# [^\n]*\n/', '', $markdown, 1);
        $html = (string) Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        [$section] = self::locate($slug);
        $locale = $file['locale'] ?? 'de';
        // Ein Link ohne Ziel in der Hilfe verliert den Link, der Text bleibt
        $html = (string) preg_replace_callback('/<a href="((?:\.\.\/)*[A-Za-z0-9_\/-]+)\.md(#[^"]*)?">(.*?)<\/a>/s', static function (array $m) use ($section, $locale): string {
            $href = self::link($section, $locale, $m[1], $m[2]);

            return $href === null ? $m[3] : '<a href="'.e($href).'">'.$m[3].'</a>';
        }, $html);
        // TB 29: Bilder neben dem Text (`bilder/…`) über `/_doku/…` — mit Rückfall auf das deutsche Bild
        $html = (string) preg_replace_callback('/<img src="([^"]+)"/', static function (array $m) use ($section, $locale): string {
            $path = HelpImages::resolve($section, $locale, html_entity_decode($m[1]));

            return $path === null ? $m[0] : '<img loading="lazy" src="'.e(HelpImages::url($path)).'"';
        }, $html);
        // Ein Bild allein im Absatz wird eine Abbildung: Klick öffnet es gross, der Titel steht als Unterschrift darunter
        $html = (string) preg_replace_callback('#<p>(<img loading="lazy" src="([^"]+)"[^>]*/?>)</p>#', static function (array $m): string {
            $title = preg_match('/ title="([^"]*)"/', $m[1], $t) === 1 ? $t[1] : '';

            return '<figure class="hilfe-bild"><a href="'.$m[2].'" target="_blank" rel="noopener">'.$m[1].'</a>'.($title !== '' ? '<figcaption>'.$title.'</figcaption>' : '').'</figure>';
        }, $html);
        $html = (string) preg_replace_callback('/<h([23])>(.*?)<\/h\1>/s', static fn (array $m): string => '<h'.$m[1].' id="'.e(self::anchor($m[2])).'">'.$m[2].'</h'.$m[1].'>', $html);

        return ['slug' => $slug, 'title' => $title, 'html' => $html, 'locale' => $file['locale'] ?? 'de'];
    }

    /**
     * Ziel eines relativen Links aus einer Seite (Bereich und Sprache der gezeigten Datei): eine Hilfeseite im Backend,
     * sonst null — ein Link aus der Hilfe hinaus wird nicht angeboten (der Text bleibt, ohne Link).
     */
    private static function link(string $section, string $locale, string $path, string $anchor): ?string
    {
        $base = $locale === 'de' ? ['docs', $section] : ['docs', $locale, $section];
        $parts = $base;
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $inDocs = array_slice($parts, 0, count($base) - 1) === array_slice($base, 0, -1) && count($parts) === count($base) + 1;
        if ($inDocs && in_array($parts[count($base) - 1], self::SECTIONS, true)) {
            $slug = self::slugFor($parts[count($base) - 1], $parts[count($base)]);
            if (self::exists($slug)) {
                return route('basalto.help', ['page' => $slug]).$anchor;
            }
        }

        return null;
    }

    /**
     * Abschnitte einer Seite für die Suche: der Text vor der ersten `##`-Überschrift (ohne Überschrift), dann je
     * Überschrift `##`/`###` ihr Text.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private static function chunks(string $markdown): array
    {
        $chunks = [];
        $heading = null;
        $lines = [];
        $fence = false;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (str_starts_with($line, '```')) {
                $fence = ! $fence;
            }
            if (! $fence && preg_match('/^#{2,3} (.+)$/', $line, $m) === 1) {
                $chunks[] = [$heading, implode("\n", $lines)];
                $heading = trim($m[1]);
                $lines = [];

                continue;
            }
            $lines[] = $line;
        }
        $chunks[] = [$heading, implode("\n", $lines)];

        return array_values(array_filter($chunks, static fn (array $chunk): bool => $chunk[0] !== null || trim($chunk[1]) !== ''));
    }

    /** Markdown als schlichter Text: Links und Hervorhebungen weg, Leerraum zusammengefasst. */
    private static function plain(string $markdown): string
    {
        // Trennzeilen von Tabellen (|---|:--|) und Linien (---) sagen nichts
        $text = (string) preg_replace('/^[\s|:-]*-{3,}[\s|:-]*$/m', '', $markdown);
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/[*_`>|#]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Ausschnitt um den ersten Treffer, höchstens rund 160 Zeichen.
     *
     * @param  list<string>  $terms
     */
    private static function snippet(string $plain, array $terms): string
    {
        $position = null;
        foreach ($terms as $term) {
            $found = mb_stripos($plain, $term);
            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }
        $start = max(0, ($position ?? 0) - 50);
        $snippet = mb_substr($plain, $start, 160);

        return ($start > 0 ? '…' : '').$snippet.(mb_strlen($plain) > $start + 160 ? '…' : '');
    }

    /**
     * Die Datei einer Seite in der ersten Sprache der Rückfallkette, in der es sie gibt.
     *
     * @return array{path: string, locale: string}|null
     */
    private static function file(string $slug): ?array
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return null;
        }
        [$section, $name] = self::locate($slug);
        foreach (self::locales() as $locale) {
            $path = self::directory($locale, $section).'/'.$name.'.md';
            if (is_file($path)) {
                return ['path' => $path, 'locale' => $locale];
            }
        }

        return null;
    }

    private static function source(string $slug): string
    {
        $file = self::file($slug);

        return $file === null ? '' : (string) file_get_contents($file['path']);
    }

    private static function title(string $markdown, string $slug): string
    {
        return preg_match('/^# (.+)$/m', $markdown, $m) === 1 ? trim($m[1]) : Str::headline($slug);
    }
}
