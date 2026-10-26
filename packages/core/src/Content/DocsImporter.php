<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Help\HelpImages;
use Basalto\Core\Help\HelpPages;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Die Produkt-Doku als Inhalte einer Site (TB 11 Scheibe 4, G-08/N-09): jede Markdown-Datei unter `docs/` wird ein
 * Eintrag eines Typs des Themes (Vorgabe «doc») — Titel aus der ersten Überschrift, Abschnitt aus dem Ordner, Text als
 * Fliesstext, veröffentlicht. Übersetzungen liegen im selben Aufbau unter `docs/<sprache>/` und werden zur Fassung
 * derselben Seite. Links zwischen Dateien zeigen auf die Doku-Seiten, andere Dateien des Repositorys auf GitHub.
 *
 * Idempotent: Jede Datei hat eine feste UUID; eine Fassung entsteht nur, wenn sich die Datei geändert hat (Prüfsumme).
 * Rechtstexte (`docs/recht/`) gehören nicht dazu — sie kommen aus dem Kern unter `/_recht/…`.
 */
final class DocsImporter
{
    /** Ordner unter docs/, die keine Doku-Seiten sind. */
    public const SKIP = ['recht', 'hersteller'];

    /** Fassung der Umwandlung — geht in die Prüfsumme ein, damit eine geänderte Umwandlung alle Seiten auffrischt. */
    public const VERSION = 3;

    public function __construct(private readonly ThemeLoader $themes, private readonly Pages $pages) {}

    /** @return array{created: list<string>, updated: list<string>, kept: list<string>, removed: list<string>} */
    public function import(Site $site, string $root, string $type = 'doc'): array
    {
        $schema = ThemeSchema::fromTheme($this->themes->forSite($site));
        $routes = $schema->types[$type]['routes'] ?? null;
        if ($routes === null) {
            throw new RuntimeException(sprintf('Das Theme der Site «%s» kennt keinen Typ «%s».', $site->handle, $type));
        }
        if (! is_dir($root)) {
            throw new RuntimeException(sprintf('Ordner «%s» fehlt.', $root));
        }
        $root = rtrim((string) realpath($root), '/');
        $locales = $site->localeCodes();
        $translations = array_values(array_filter($locales, static fn (string $l): bool => $l !== $site->default_locale && is_dir($root.'/'.$l)));
        $files = $this->files($root, [...self::SKIP, ...$translations]);

        // Slug und Abschnitt je Datei, damit Links schon beim ersten Durchlauf auf die richtige Adresse zeigen
        $docs = [];
        foreach ($files as $position => $rel) {
            $docs[$rel] = ['slug' => self::slugOf($rel), 'section' => str_contains($rel, '/') ? Str::before($rel, '/') : 'einstieg', 'position' => $position];
        }
        $result = ['created' => [], 'updated' => [], 'kept' => [], 'removed' => []];
        $uuids = [];
        foreach ($docs as $rel => $doc) {
            $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-docs:%d:%s', $site->id, $rel))->toString();
            $uuids[] = $uuid;
            $entry = Entry::query()->withoutGlobalScopes()->withTrashed()->where('uuid', $uuid)->first();
            if ($entry?->trashed()) {
                $result['kept'][] = $rel.' (im Papierkorb)';

                continue;
            }
            $entry ??= Entry::query()->create(['site_id' => $site->id, 'type' => $type, 'uuid' => $uuid, 'position' => $doc['position']]);
            foreach ([$site->default_locale, ...$translations] as $locale) {
                $file = $locale === $site->default_locale ? $root.'/'.$rel : $root.'/'.$locale.'/'.$rel;
                if (! is_file($file)) {
                    continue;
                }
                $markdown = (string) file_get_contents($file);
                $hash = hash('xxh128', self::VERSION."\n".$markdown);
                $label = sprintf('%s (%s)', $rel, strtoupper($locale));
                $current = $entry->publishedVersion($locale);
                if ($current !== null && ($current->data['source_hash'] ?? null) === $hash) {
                    $result['kept'][] = $label;

                    continue;
                }
                [$title, $body] = self::split($markdown, $doc['slug']);
                $links = fn (string $href): ?string => $this->link($site, $href, dirname($rel), $locale, $root, $docs, $routes, $translations);
                // Ein festes Datum für alle: entries() ordnet nach Datum, bei Gleichstand nach Titel — die Doku alphabetisch
                $this->publish($entry, $locale, ['title' => $title, 'slug' => $doc['slug'], 'section' => $doc['section'], 'position' => $doc['position'], 'date' => '2026-01-01', 'source' => 'docs/'.($locale === $site->default_locale ? '' : $locale.'/').$rel,
                    'body' => (new MarkdownDocument($links(...), static fn (string $src): ?string => ($path = HelpImages::resolve(dirname($rel), $locale === $site->default_locale ? 'de' : $locale, $src)) !== null
                        && HelpImages::file($path) !== null ? HelpImages::url($path) : null))->convert($body), 'source_hash' => $hash]);
                $result[$current === null ? 'created' : 'updated'][] = $label;
            }
        }
        // Seiten, deren Datei es nicht mehr gibt (verschoben, gelöscht, nur noch im Extranet), kommen in den Papierkorb
        $stale = Entry::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('site_id', $site->id)->where('type', $type)->whereNotIn('uuid', $uuids)->get();
        foreach ($stale as $entry) {
            $source = (string) ($entry->publishedVersion($site->default_locale)?->data['source'] ?? '');
            if (str_starts_with($source, 'docs/')) {
                $entry->delete();
                $result['removed'][] = substr($source, 5);
            }
        }
        $this->pages->forget();

        return $result;
    }

    /**
     * Alle Markdown-Dateien relativ zu docs/, Übersichten (README, index) je Ordner zuerst.
     *
     * @param  list<string>  $skip
     * @return list<string>
     */
    private function files(string $root, array $skip): array
    {
        $files = [];
        foreach (File::allFiles($root) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            if (strtolower($file->getExtension()) !== 'md' || in_array(Str::before($rel, '/'), $skip, true) && str_contains($rel, '/')) {
                continue;
            }
            $files[] = $rel;
        }
        usort($files, static function (string $a, string $b): int {
            // Übersicht zuerst, dann die Reihenfolge der Webhilfe (HelpPages::SECTION_ORDER), sonst nach Name
            $key = static function (string $p): string {
                $section = str_contains($p, '/') ? Str::before($p, '/') : '';
                $order = array_search(basename($p, '.md'), HelpPages::SECTION_ORDER[$section] ?? [], true);

                return $section.'/'.(in_array(strtolower(basename($p)), ['readme.md', 'index.md'], true) ? '0' : '1').sprintf('%03d', $order === false ? 999 : $order).basename($p);
            };

            return [substr_count($a, '/') > 0, $key($a)] <=> [substr_count($b, '/') > 0, $key($b)];
        });

        return $files;
    }

    /** «entwickler/lava.md» → «entwickler-lava», «entwickler/README.md» → «entwickler», «README.md» → «uebersicht». */
    public static function slugOf(string $rel): string
    {
        $path = Str::beforeLast($rel, '.');
        if (in_array(strtolower(basename($path)), ['readme', 'index'], true)) {
            $path = str_contains($path, '/') ? dirname($path) : 'uebersicht';
        }

        return Str::slug(str_replace('/', '-', $path));
    }

    /** @return array{0: string, 1: string} Titel aus der ersten Überschrift und der Text ohne sie */
    private static function split(string $markdown, string $fallback): array
    {
        if (preg_match('/^\s*#\s+(.+?)\s*#*\s*$/m', $markdown, $m, PREG_OFFSET_CAPTURE) === 1 && trim(substr($markdown, 0, (int) $m[0][1])) === '') {
            return [trim($m[1][0]), ltrim(substr($markdown, (int) $m[0][1] + strlen($m[0][0])))];
        }

        return [Str::headline($fallback), $markdown];
    }

    /**
     * @param  array<string, array{slug: string, section: string, position: int}>  $docs
     * @param  array<string, string>  $routes
     * @param  list<string>  $translations
     */
    private function link(Site $site, string $href, string $dir, string $locale, string $root, array $docs, array $routes, array $translations): ?string
    {
        if (preg_match('#^(https?:|mailto:|tel:|\#)#i', $href) === 1) {
            return $href;
        }
        $anchor = str_contains($href, '#') ? '#'.Str::after($href, '#') : '';
        // Vom Wurzelordner des Projekts aus auflösen: «../../dokumentationen/…» aus docs/entwickler/ bleibt erreichbar
        $project = rtrim(base_path(), '/').'/';
        $base = (str_starts_with($root.'/', $project) ? substr($root, strlen($project)) : basename($root)).'/'.($locale !== $site->default_locale && in_array($locale, $translations, true) ? $locale.'/' : '');
        $repo = self::normalize($base.($dir === '.' ? '' : $dir.'/').Str::before($href, '#'));
        if ($repo === null) {
            return null;
        }
        $target = str_starts_with($repo, $base) ? substr($repo, strlen($base)) : null;
        if ($target !== null && isset($docs[$target])) {
            $pattern = $routes[$locale] ?? $routes['*'] ?? '/doku/{slug}';

            return $this->pages->urlPath($site, $locale, str_replace('{slug}', $docs[$target]['slug'], $pattern)).$anchor;
        }

        // Ausserhalb der Doku gibt es kein Ziel — der Text bleibt, ohne Link
        return null;
    }

    /** «entwickler/../betrieb/lizenz.md» → «betrieb/lizenz.md»; null, wenn der Pfad über die Wurzel hinausführt. */
    private static function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    /** @param  array<string, mixed>  $data */
    private function publish(Entry $entry, string $locale, array $data): void
    {
        $version = $entry->newDraft($locale, $data);
        $this->pages->trackPaths($entry->site, static function () use ($entry, $locale, $version): void {
            $entry->versions()->where('locale', $locale)->where('status', EntryStatus::Published->value)->whereKeyNot($version->id)->update(['status' => EntryStatus::Archived->value]);
            $version->forceFill(['status' => EntryStatus::Published, 'published_at' => now()])->save();
        });
    }
}
