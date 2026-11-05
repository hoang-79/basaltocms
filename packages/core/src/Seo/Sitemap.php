<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Die Sitemap einer Site (M-10): `/sitemap.xml` ist ein Index mit einer Sitemap je Sprache (`/sitemap-de.xml` …),
 * jede Adresse mit letzter Änderung und ihren Sprachfassungen (hreflang). Drin steht, was veröffentlicht, im Zeitfenster
 * sichtbar, nicht «noindex», nicht auf eine fremde kanonische Adresse gerichtet und im Reiter Experten nicht aus der
 * Sitemap genommen ist — Seiten und Inhalte mit eigener Route und eigener Fassung in der Sprache (nicht über den
 * Fallback); Weiterleitungen, Feeds, Ordner und Fehlerseiten nie.
 */
final class Sitemap
{
    private const EXCLUDED = [PageType::Redirect, PageType::Feed, PageType::Folder, PageType::Error401, PageType::Error403, PageType::Error404, PageType::Error503];

    /** @var array<int, array{schema: ThemeSchema, noindex: list<string>}|null> Theme je Site: Schema und Templates mit «noindex» */
    private array $themes = [];

    /** Getaggte Closures `fn (Site, string $locale, string $base): list<array{loc, lastmod, alternates}>` der Module. */
    public const TAG = 'basalto.sitemap';

    public function __construct(private readonly Pages $pages) {}

    public function index(Site $site, string $base): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($site->localeCodes() as $locale) {
            $urls = $this->urls($site, $locale, $base);
            if ($urls === []) {
                continue;
            }
            $latest = max(array_map(static fn (array $u): int => $u['lastmod']?->getTimestamp() ?? 0, $urls));
            $xml .= sprintf('<sitemap><loc>%s</loc>%s</sitemap>', self::e(rtrim($base, '/').'/sitemap-'.$locale.'.xml'),
                $latest > 0 ? '<lastmod>'.gmdate('Y-m-d\TH:i:s\Z', $latest).'</lastmod>' : '');
        }

        return $xml.'</sitemapindex>';
    }

    /**
     * Wie viele Adressen die Sitemap in allen Sprachen hat (CR-019, Statistik und Verwaltung › Suchmaschinen) — zehn
     * Minuten zwischengespeichert, weil grosse Sites dafür jede Seite durchgehen.
     */
    public function count(Site $site): int
    {
        return (int) Cache::remember('basalto.sitemap.count.'.$site->id, 600, function () use ($site): int {
            $base = rtrim($site->publicUrl(), '/');
            $count = 0;
            foreach ($site->localeCodes() as $locale) {
                $count += count($this->urls($site, $locale, $base));
            }

            return $count;
        });
    }

    public function forLocale(Site $site, string $locale, string $base): ?string
    {
        if (! in_array($locale, $site->localeCodes(), true)) {
            return null;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
        foreach ($this->urls($site, $locale, $base) as $url) {
            $xml .= '<url><loc>'.self::e($url['loc']).'</loc>';
            if ($url['lastmod'] !== null) {
                $xml .= '<lastmod>'.$url['lastmod']->utc()->format('Y-m-d\TH:i:s\Z').'</lastmod>';
            }
            if (count($url['alternates']) > 1) {
                foreach ($url['alternates'] as $code => $href) {
                    $xml .= sprintf('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>', self::e($code), self::e($href));
                }
            }
            // CR-019 TB 107: Seitenbild (und Bilder der Module) für die Bildersuche
            foreach ($url['images'] ?? [] as $image) {
                $xml .= '<image:image><image:loc>'.self::e($image).'</image:loc></image:image>';
            }
            $xml .= '</url>';
        }

        return $xml.'</urlset>';
    }

    /**
     * Die Adressen einer Sprache, Startseite zuerst.
     *
     * @return list<array{loc: string, lastmod: CarbonInterface|null, alternates: array<string, string>, images?: list<string>}>
     */
    public function urls(Site $site, string $locale, string $base): array
    {
        $base = rtrim($base, '/');
        $out = [];
        $images = $this->images($site);
        foreach ($this->pages->entries($site) as $entry) {
            $entry->setRelation('site', $site);
            if (! $this->listed($entry)) {
                continue;
            }
            $path = $this->pages->ownPathOf($entry, $locale);
            $version = $path === null ? null : $this->pages->versionOf($entry, $locale);
            if ($path === null || $version === null) {
                continue;
            }
            $loc = $base.$this->pages->urlPath($site, $locale, $path);
            $canonical = $entry->setting('canonical', []);
            if (is_array($canonical) && ($canonical['mode'] ?? 'auto') === 'custom' && is_string($canonical['url'] ?? null) && $canonical['url'] !== '' && $canonical['url'] !== $loc) {
                continue;
            }
            $alternates = [];
            foreach ($site->localeCodes() as $code) {
                $altPath = $this->pages->ownPathOf($entry, $code);
                if ($altPath !== null) {
                    $alternates[$code] = $base.$this->pages->urlPath($site, $code, $altPath);
                }
            }
            $mediaId = $entry->setting('image.media_id');
            $out[] = ['loc' => $loc, 'lastmod' => $version->published_at ?? $version->updated_at, 'alternates' => $alternates, 'home' => $path === '/',
                'images' => is_numeric($mediaId) && isset($images[(int) $mediaId]) ? [$base.$images[(int) $mediaId]] : []];
        }
        usort($out, static fn (array $a, array $b): int => [$b['home'], $a['loc']] <=> [$a['home'], $b['loc']]);
        $urls = array_map(static fn (array $u): array => ['loc' => $u['loc'], 'lastmod' => $u['lastmod'], 'alternates' => $u['alternates'], 'images' => $u['images']], $out);
        // CR-006 (TB 41, FO-22): Adressen der Module unter Einhängeseiten (Themen des Forums)
        foreach (app()->tagged(self::TAG) as $more) {
            if (is_callable($more)) {
                array_push($urls, ...$more($site, $locale, $base));
            }
        }

        return $urls;
    }

    /**
     * Pfade der Seitenbilder einer Site (Auslieferung der Site, keine befristeten Adressen), mit einer Abfrage.
     *
     * @return array<int, string> Medien-ID → Pfad
     */
    private function images(Site $site): array
    {
        $ids = [];
        foreach ($this->pages->entries($site) as $entry) {
            $id = $entry->setting('image.media_id');
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Media::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('id', array_unique($ids))->whereNull('deleted_at')->pluck('id') as $id) {
            $out[(int) $id] = route('basalto.media.show', ['media' => (int) $id], false);
        }

        return $out;
    }

    private function listed(Entry $entry): bool
    {
        if (in_array($entry->pageType(), self::EXCLUDED, true) || $entry->setting('expert.in_sitemap', true) === false) {
            return false;
        }
        // TB 13 Scheibe 2: geschützte Seiten (nur für Mitglieder) nie in der Sitemap
        if (str_contains((string) $entry->setting('meta.robots', ''), 'noindex') || $this->pages->isProtected($entry)) {
            return false;
        }
        // TB 35: wie im Kopf der Seite — ohne eigene Robots-Angabe gilt «noindex» des Templates
        if ((string) $entry->setting('meta.robots', '') === '' && $this->templateNoindex($entry)) {
            return false;
        }

        return $this->pages->isVisible($entry);
    }

    private function templateNoindex(Entry $entry): bool
    {
        $site = $entry->site;
        if (! array_key_exists($site->id, $this->themes)) {
            try {
                $theme = app(ThemeLoader::class)->forSite($site);
                $noindex = [];
                foreach ($theme->document->all('template') as $template) {
                    if ($template->value('noindex') === 'true') {
                        $noindex[] = (string) $template->name;
                    }
                }
                $this->themes[$site->id] = ['schema' => ThemeSchema::fromTheme($theme), 'noindex' => $noindex];
            } catch (ThemeException) {
                $this->themes[$site->id] = null;
            }
        }
        $known = $this->themes[$site->id];
        if ($known === null || $known['noindex'] === [] || ! isset($known['schema']->types[$entry->type])) {
            return false;
        }

        return in_array(app(EntryRenderer::class)->templateFor($entry, $known['schema']), $known['noindex'], true);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1);
    }
}
