<?php

declare(strict_types=1);

namespace Basalto\Core\Help;

use Closure;
use Illuminate\Support\Str;

/**
 * Rechtstexte im Produkt (G-07, G-06; TB 56 alle Rechtsseiten von basaltocms.com): Impressum, AGB, Lizenzvertrag, AVV,
 * Datenschutz, Nutzungsbedingungen, Barrierefreiheit, Widerruf, Urheberrecht, Community-Richtlinien und
 * Autoren-Bedingungen aus
 * `docs/recht/<sprache>/<text>.md`, als HTML ausgegeben — im Backend in der Sprache der Oberfläche und öffentlich unter
 * `/_recht/<sprache>/<text>` für den Installer, die Module Lizenz und Marktplatz und das Theme von basaltocms.com.
 *
 * Die Texte liegen mit der Version der Software im Repository, damit gilt, was der bezogenen Version beiliegt
 * (Lizenzvertrag § 12). Rohes HTML im Markdown wird entfernt, unsichere Links ebenso; Verweise auf einen anderen
 * Rechtstext (`agb.md`) zeigen auf denselben Text in derselben Sprache und Umgebung.
 */
final class LegalTexts
{
    /** Reihenfolge der Texte (wie die Fusszeile «Rechtliches»); der Dateiname ist in allen Sprachen derselbe. */
    public const ORDER = ['impressum', 'agb', 'lizenzvertrag', 'avv', 'datenschutz', 'nutzungsbedingungen', 'barrierefreiheit', 'widerruf', 'urheberrecht',
        'community-richtlinien', 'autoren-bedingungen'];

    public const LOCALES = ['de', 'en', 'it', 'fr'];

    /** Die Kennzeichnung im Kopf jedes Texts, solange die anwaltliche Prüfung aussteht. */
    public const DRAFT_MARKERS = [
        'de' => 'Entwurf — anwaltliche Prüfung ausstehend',
        'en' => 'Draft — legal review pending',
        'it' => 'Bozza — verifica legale in sospeso',
        'fr' => 'Projet — examen juridique en attente',
    ];

    public static function directory(): string
    {
        $configured = config('basalto.legal_path');

        return is_string($configured) && $configured !== '' ? $configured : base_path('docs/recht');
    }

    public static function exists(string $locale, string $slug): bool
    {
        return in_array($locale, self::LOCALES, true) && in_array($slug, self::ORDER, true)
            && is_file(self::path($locale, $slug));
    }

    /** Die Sprache der Oberfläche, wenn es Rechtstexte in ihr gibt — sonst Deutsch. */
    public static function locale(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return in_array($locale, self::LOCALES, true) ? $locale : 'de';
    }

    /** Öffentliche Adresse eines Texts — für Installer, Module und Themes. */
    public static function publicUrl(string $slug, ?string $locale = null): string
    {
        return route('basalto.legal.public', ['locale' => self::locale($locale), 'text' => $slug]);
    }

    /** @return list<array{slug: string, title: string}> */
    public static function index(string $locale): array
    {
        $texts = [];
        foreach (self::ORDER as $slug) {
            if (self::exists($locale, $slug)) {
                $texts[] = ['slug' => $slug, 'title' => self::title(self::source($locale, $slug), $slug)];
            }
        }

        return $texts;
    }

    /** @return list<string> Sprachen, in denen es diesen Text gibt */
    public static function locales(string $slug): array
    {
        return array_values(array_filter(self::LOCALES, static fn (string $locale): bool => self::exists($locale, $slug)));
    }

    /**
     * @param  Closure(string, string): string  $url  Adresse eines Texts aus (Text, Sprache)
     * @return array{slug: string, locale: string, title: string, html: string, draft: bool}
     */
    public static function render(string $locale, string $slug, Closure $url): array
    {
        $markdown = self::source($locale, $slug);
        $title = self::title($markdown, $slug);
        // Der Titel steht im Seitenkopf — die erste Überschrift nicht doppelt zeigen
        $body = (string) preg_replace('/\A\s*# [^\n]*\n/', '', $markdown, 1);
        $html = (string) Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = (string) preg_replace_callback('/href="([a-z0-9-]+)\.md(#[A-Za-z0-9_-]*)?"/', static function (array $m) use ($locale, $url): string {
            return self::exists($locale, $m[1]) ? 'href="'.e($url($m[1], $locale).($m[2] ?? '')).'"' : $m[0];
        }, $html);

        return [
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'html' => $html,
            'draft' => str_contains($markdown, self::DRAFT_MARKERS[$locale]),
        ];
    }

    private static function path(string $locale, string $slug): string
    {
        return self::directory().'/'.$locale.'/'.$slug.'.md';
    }

    private static function source(string $locale, string $slug): string
    {
        return (string) file_get_contents(self::path($locale, $slug));
    }

    private static function title(string $markdown, string $slug): string
    {
        return preg_match('/^# (.+)$/m', $markdown, $m) === 1 ? trim($m[1]) : Str::headline($slug);
    }
}
