<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteTheme;
use Basalto\Core\Models\ThemeDraft;
use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Theme\DatabaseSource;
use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\LavaSource;
use Basalto\Lava\Theme\Source;
use Basalto\Lava\Theme\Theme;
use Basalto\Lava\Theme\ThemeCache;
use RuntimeException;

/**
 * Das Theme einer Site: ein eigenes Theme aus ihrer Bibliothek (`site_themes`, Dateien in `lava_sources`), sonst aus
 * `themes/<theme>`; je Anfrage einmal kompiliert.
 * In der Vorschau des Theme-Editors liegen die Entwürfe (`theme_drafts`) über der Datenbank-Fassung.
 */
final class ThemeLoader
{
    /** @var array<string, Theme> */
    private array $cache = [];

    /** @var array<int, true> Sites, deren Entwürfe in dieser Anfrage gelten (Vorschau im Theme-Editor, L-17) */
    private array $draftPreview = [];

    /** @var array<int, string> Theme je Site für diese Anfrage (Vorschau eines anderen Themes, TB 22) */
    private array $previewTheme = [];

    /** Der Baum-Cache je Instanz (ADR-013): kompiliert mit der Registry des Containers, damit Modul-Feldtypen gelten. */
    private readonly ThemeCache $themeCache;

    public function __construct(?ThemeCache $themeCache = null)
    {
        $this->themeCache = $themeCache ?? new ThemeCache(null, app(Registry::class));
    }

    /**
     * Kompiliert die Quelle einer Site über den Baum-Cache — auch mit Befunden (für die rote Linie).
     *
     * @return array{theme: ?Theme, diagnostics: list<Diagnostic>}
     */
    public function compileFor(Site $site): array
    {
        return $this->themeCache->compile($this->sourceFor($site)['source']);
    }

    /**
     * Quelle des Themes einer Site: Datenbank, wenn Theme-Quellen für die Site liegen, sonst der Theme-Ordner —
     * jeweils mit den Lava-Ergänzungen der Site (eigene Typen) dahinter, ausser `$withSiteLayer` ist aus.
     *
     * @return array{source: Source, name: string, kind: string}
     */
    public function sourceFor(Site $site, bool $withSiteLayer = true): array
    {
        $name = $this->previewTheme[$site->id] ?? ThemeLibrary::activeName($site);
        $kind = 'files';
        $source = new FileSource(ThemeCatalog::path().'/'.$name);
        // TB 22: ein eigenes Theme gleichen Namens aus der Bibliothek der Site geht dem Ordner vor
        $own = SiteTheme::query()->where('site_id', $site->id)->where('name', $name)->first(['id', 'site_id', 'name', 'assets']);
        if ($own !== null) {
            $kind = 'database';
            $source = new DatabaseSource($site->id, $own->scope(), $site->handle);
            if (isset($this->draftPreview[$site->id])) {
                $drafts = ThemeDraft::query()->where('site_theme_id', $own->id)->pluck('source', 'path')->all();
                if ($drafts !== []) {
                    $kind = 'drafts';
                    $source = self::overlay($source, $drafts, $site->handle);
                }
            }
            // Bilder und Schriften der Bibliothek gehören zum Theme (Prüfung von css, logo und Schriften)
            $source = new LibrarySource($source, new AssetStore($own));
        }
        if ($withSiteLayer) {
            $siteSource = new DatabaseSource($site->id, LavaSource::SCOPE_SITE, $site->handle.'/site');
            if ($siteSource->paths() !== []) {
                $source = new LayeredSource($source, $siteSource);
            }
        }

        return ['source' => $source, 'name' => $name, 'kind' => $kind];
    }

    public function forSite(Site $site): Theme
    {
        $resolved = $this->sourceFor($site);
        $key = $site->id.':'.$resolved['kind'].':'.$resolved['name'];
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $source = $resolved['source'];
        try {
            $result = $this->themeCache->compile($source);
        } catch (RuntimeException $e) {
            throw new ThemeException($source->name(), [new Diagnostic(Diagnostic::ERROR, $e->getMessage(), 'theme.lava', 1, 1, 'A')]);
        }
        $theme = $result['theme'];
        $errors = array_filter($result['diagnostics'], static fn (Diagnostic $d): bool => $d->isError());
        if ($theme === null || $errors !== []) {
            throw new ThemeException($source->name(), array_values($errors ?: $result['diagnostics']));
        }

        return $this->cache[$key] = $theme;
    }

    /** Die Site zeigt in dieser Anfrage ihre Theme-Entwürfe statt der Live-Fassung (nur für Angemeldete, L-17). */
    public function previewDrafts(Site $site): void
    {
        $this->draftPreview[$site->id] = true;
    }

    /** Die Site zeigt in dieser Anfrage ein anderes Theme — eigenes oder Ordner (Vorschau, TB 22); gespeichert wird nichts. */
    public function previewTheme(Site $site, string $name): void
    {
        $this->previewTheme[$site->id] = $name;
        $this->cache = [];
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** Zwischen zwei Anfragen (Octane): auch die Entwurfsvorschau endet. */
    public function reset(): void
    {
        $this->cache = [];
        $this->draftPreview = [];
        $this->previewTheme = [];
    }

    /**
     * Die Textdateien einer Quelle mit Änderungen darüber — `null` streicht eine Datei. Binäres (Schriften, Bilder)
     * bleibt draussen, das liegt im Theme-Ordner.
     *
     * @param  array<string, string|null>  $changes
     */
    public static function overlay(Source $base, array $changes, string $name): MemorySource
    {
        $files = [];
        foreach ($base->paths() as $path) {
            $content = $base->read($path);
            if (mb_check_encoding($content, 'UTF-8')) {
                $files[$path] = $content;
            }
        }
        foreach ($changes as $path => $content) {
            if ($content === null) {
                unset($files[$path]);
            } else {
                $files[$path] = $content;
            }
        }

        return new MemorySource($files, $name);
    }
}
