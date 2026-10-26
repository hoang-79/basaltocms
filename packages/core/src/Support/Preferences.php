<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Einstellungen eines Benutzers für die Darstellung (Profil): Datumsformat, Zeitformat, Startseite nach der Anmeldung;
 * Sprache und Zeitzone stehen als eigene Spalten. Jede hat eine Wirkung — keine Schalter ohne Folge.
 *
 * TB 21 (Benutzermenü): dazu Einträge pro Seite (Inhalte, Medien, Eingänge, Protokoll), Sortierung der Listen
 * (Inhalte, Medien), die Tastenkürzel der Hilfe (? und F1) und die Darstellung (hell, dunkel, wie das System).
 */
final class Preferences
{
    /** @var array<string, string> Format → Beispiel-Beschriftung */
    public const DATE_FORMATS = ['d.m.Y' => 'TT.MM.JJJJ', 'Y-m-d' => 'JJJJ-MM-TT', 'd/m/Y' => 'TT/MM/JJJJ', 'm/d/Y' => 'MM/TT/JJJJ'];

    /** @var array<string, string> */
    public const TIME_FORMATS = ['H:i' => '24 Stunden', 'g:i A' => '12 Stunden (AM/PM)'];

    /** @var list<int> Einträge pro Seite in den Listen des Backends */
    public const PER_PAGE = [25, 50, 100];

    /** @var array<string, string> Darstellung des Backends → Beschriftung (TB 21: dunkle Oberfläche) */
    public const THEMES = ['light' => 'Hell', 'dark' => 'Dunkel', 'system' => 'Wie das System'];

    /** @var array<string, string> Sortierung der Listen → Beschriftung */
    public const LIST_SORTS = ['newest' => 'Neueste zuerst', 'oldest' => 'Älteste zuerst', 'title' => 'Nach Titel (A–Z)'];

    /** @var array<string, array{label: string, route: string}> */
    public const START_PAGES = [
        'dashboard' => ['label' => 'Übersicht', 'route' => 'basalto.dashboard'],
        'pages' => ['label' => 'Seiten', 'route' => 'basalto.pages.index'],
        'reviews' => ['label' => 'Freigabe', 'route' => 'basalto.reviews.index'],
        'translations' => ['label' => 'Übersetzung', 'route' => 'basalto.translations.index'],
        'media' => ['label' => 'Medien', 'route' => 'basalto.media.index'],
    ];

    /**
     * Beschriftungen in der Sprache der Oberfläche (N-01) — die Konstanten oben bleiben die deutsche Quelle.
     *
     * @return array{date_formats: array<string, string>, time_formats: array<string, string>, start_pages: array<string, string>, list_sorts: array<string, string>, themes: array<string, string>}
     */
    public static function labels(): array
    {
        return [
            'date_formats' => ['d.m.Y' => __('TT.MM.JJJJ'), 'Y-m-d' => __('JJJJ-MM-TT'), 'd/m/Y' => __('TT/MM/JJJJ'), 'm/d/Y' => __('MM/TT/JJJJ')],
            'time_formats' => ['H:i' => __('24 Stunden'), 'g:i A' => __('12 Stunden (AM/PM)')],
            'start_pages' => ['dashboard' => __('Übersicht'), 'pages' => __('Seiten'), 'reviews' => __('Freigabe'), 'translations' => __('Übersetzung'), 'media' => __('Medien')],
            'list_sorts' => ['newest' => __('Neueste zuerst'), 'oldest' => __('Älteste zuerst'), 'title' => __('Nach Titel (A–Z)')],
            'themes' => ['light' => __('Hell'), 'dark' => __('Dunkel'), 'system' => __('Wie das System')],
        ];
    }

    /** @var array{date_format: string, time_format: string, start_page: string, per_page: int, list_sort: string, help_shortcuts: bool, theme: string, help_width: int} */
    public const DEFAULTS = ['date_format' => 'd.m.Y', 'time_format' => 'H:i', 'start_page' => 'dashboard', 'per_page' => 50, 'list_sort' => 'newest', 'help_shortcuts' => true, 'theme' => 'light', 'help_width' => 480];

    /** TB 29: Breite der Hilfe-Leiste in Pixeln (mit der Maus gezogen) — kleinste und grösste erlaubte. */
    public const HELP_WIDTH = [320, 1600];

    /** @return array{date_format: string, time_format: string, start_page: string, per_page: int, list_sort: string, help_shortcuts: bool, theme: string, help_width: int} */
    public static function of(?Authenticatable $user): array
    {
        $stored = $user instanceof Model ? $user->getAttribute('preferences') : null;
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        $stored = is_array($stored) ? $stored : [];

        return [
            'date_format' => array_key_exists((string) ($stored['date_format'] ?? ''), self::DATE_FORMATS) ? (string) $stored['date_format'] : self::DEFAULTS['date_format'],
            'time_format' => array_key_exists((string) ($stored['time_format'] ?? ''), self::TIME_FORMATS) ? (string) $stored['time_format'] : self::DEFAULTS['time_format'],
            'start_page' => array_key_exists((string) ($stored['start_page'] ?? ''), self::START_PAGES) ? (string) $stored['start_page'] : self::DEFAULTS['start_page'],
            'per_page' => in_array((int) ($stored['per_page'] ?? 0), self::PER_PAGE, true) ? (int) $stored['per_page'] : self::DEFAULTS['per_page'],
            'list_sort' => array_key_exists((string) ($stored['list_sort'] ?? ''), self::LIST_SORTS) ? (string) $stored['list_sort'] : self::DEFAULTS['list_sort'],
            'help_shortcuts' => is_bool($stored['help_shortcuts'] ?? null) ? $stored['help_shortcuts'] : self::DEFAULTS['help_shortcuts'],
            'theme' => array_key_exists((string) ($stored['theme'] ?? ''), self::THEMES) ? (string) $stored['theme'] : self::DEFAULTS['theme'],
            'help_width' => is_int($stored['help_width'] ?? null) ? max(self::HELP_WIDTH[0], min(self::HELP_WIDTH[1], $stored['help_width'])) : self::DEFAULTS['help_width'],
        ];
    }

    /** Einträge pro Seite für die Listen des Backends. */
    public static function perPage(?Authenticatable $user): int
    {
        return self::of($user)['per_page'];
    }

    /**
     * Sortierung einer Liste nach der Wahl im Profil: neueste oder älteste zuerst (nach `id`), sonst nach Titel.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Builder<TModel>): mixed  $byTitle  sortiert nach Titel (je Liste anders: Fassung, Dateiname)
     * @return Builder<TModel>
     */
    public static function sort(Builder $query, ?Authenticatable $user, Closure $byTitle): Builder
    {
        match (self::of($user)['list_sort']) {
            'oldest' => $query->oldest('id'),
            'title' => $byTitle($query),
            default => $query->latest('id'),
        };

        return $query;
    }

    /** Wohin nach der Anmeldung — die gewählte Startseite. */
    public static function startUrl(?Authenticatable $user): string
    {
        return route(self::START_PAGES[self::of($user)['start_page']]['route']);
    }
}
