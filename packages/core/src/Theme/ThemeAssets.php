<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteTheme;
use Basalto\Lava\Theme\Design;
use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\Source;

/**
 * Dateien eines Themes im Browser (L-10, M-15): alles unter `assets/` im Theme-Ordner — Stylesheets, Skripte, Schriften,
 * Logo, Bilder — unter einer Adresse mit Prüfsumme des Inhalts, damit Browser und CDN sie ein Jahr behalten dürfen und
 * jede Änderung sofort eine neue Adresse bekommt. Kein Build: Was im Ordner liegt, wird ausgeliefert.
 *
 * Ein Theme aus der Datenbank (`lava_sources`) trägt Textdateien selbst; Binäres (Schriften, Bilder) kommt aus dem
 * Theme-Ordner gleichen Namens auf der Platte. Dazu `design` als CSS-Variablen mit den Überschreibungen der Site aus dem
 * Backend (`sites.settings.design`) — die wirken ohne Build und ohne Deployment.
 *
 * @phpstan-type Overrides array{palette?: string, colors?: array<string, string>, fonts?: array<string, string>, logo_media_id?: int|null}
 */
final class ThemeAssets
{
    /** @var array<string, string> erlaubte Endungen → Inhaltstyp */
    public const TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
    ];

    /** @var array<string, string|null> Prüfsummen je Site und Pfad für diese Anfrage */
    private array $hashes = [];

    public function __construct(private readonly ThemeLoader $themes) {}

    /** Inhalt einer Theme-Datei oder null, wenn der Pfad nicht erlaubt ist oder die Datei fehlt. */
    public function read(Site $site, string $path): ?string
    {
        if (! self::allowed($path)) {
            return null;
        }
        foreach ($this->sources($site) as $source) {
            if ($source->has($path)) {
                return $source->read($path);
            }
        }

        return null;
    }

    /** Öffentliche Adresse mit Prüfsumme, oder null, wenn die Datei fehlt. */
    public function url(Site $site, string $path): ?string
    {
        $key = $site->id.':'.$path;
        if (! array_key_exists($key, $this->hashes)) {
            $this->hashes[$key] = $this->hash($site, $path);
        }
        $hash = $this->hashes[$key];

        return $hash === null ? null : route('basalto.theme.asset', ['site' => $site->id, 'hash' => $hash, 'path' => $path], false);
    }

    /** Die Prüfsumme, unter der die Datei gerade ausgeliefert wird. */
    public function hash(Site $site, string $path): ?string
    {
        if (! self::allowed($path)) {
            return null;
        }
        foreach ($this->sources($site) as $source) {
            if ($source->has($path)) {
                // Bilder eines eigenen Themes: Prüfsumme aus dem Verzeichnis, ohne die Datei von der Disk zu holen
                return $source instanceof AssetStore ? $source->hash($path) : substr(hash('xxh128', $source->read($path)), 0, 12);
            }
        }

        return null;
    }

    /** Endungen, die als Bild gelten — für die Auswahl «Bilder des Themes» im Blockformular. */
    public const IMAGE_TYPES = ['svg', 'png', 'jpg', 'jpeg', 'webp', 'avif'];

    /**
     * Die Bilder unter `assets/` des Themes mit Adresse — der Pfad ohne «assets/», so wie ihn ein Bildfeld speichert
     * (z. B. «landing/buehne-foto.webp»). Die wirksame Quelle gewinnt, wie beim Ausliefern.
     *
     * @return list<array{path: string, url: string}>
     */
    public function images(Site $site): array
    {
        $paths = [];
        foreach ($this->sources($site) as $source) {
            foreach ($source->paths() as $path) {
                if (self::allowed($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_TYPES, true)) {
                    $paths[$path] = true;
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths);
        $images = [];
        foreach ($paths as $path) {
            $url = $this->url($site, $path);
            if ($url !== null) {
                $images[] = ['path' => substr($path, strlen('assets/')), 'url' => $url];
            }
        }

        return $images;
    }

    /** `design` des Themes mit den Überschreibungen der Site (Farben, Schriften, Logo aus der Mediathek). */
    public function design(Site $site): Design
    {
        $overrides = self::overrides($site);
        $logo = null;
        if (($overrides['logo_media_id'] ?? null) !== null) {
            $logo = Media::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereKey($overrides['logo_media_id'])->first()?->url();
        }

        // CR-015/TB 83: zuerst die gewählte Farbvariante des Themes, darüber die Überschreibungen der Site
        return Design::of($this->themes->forSite($site))->palette($overrides['palette'] ?? null)
            ->with(['colors' => $overrides['colors'] ?? [], 'fonts' => $overrides['fonts'] ?? [], 'logo' => $logo]);
    }

    /** Adresse des Logos: aus der Mediathek (Überschreibung) oder aus dem Theme-Ordner; null ohne Logo. */
    public function logoUrl(Site $site): ?string
    {
        $logo = $this->design($site)->logo;
        if ($logo === null) {
            return null;
        }

        return str_starts_with($logo, 'assets/') ? $this->url($site, $logo) : $logo;
    }

    /**
     * Der Teil von `{% assets %}`, der vom Theme kommt: Variablen und `@font-face` inline (klein, kein zusätzlicher
     * Abruf vor dem ersten Zeichnen), danach die Stylesheets des Themes.
     */
    public function head(Site $site): string
    {
        $design = $this->design($site);
        $css = $design->css(fn (string $path): ?string => $this->url($site, $path));
        // TB 21: die Wahl hell/dunkel der Besucher vor dem ersten Zeichnen (der Seiten-Cache liefert für alle dasselbe HTML)
        $html = $design->hasDark() ? '<script>try{var a=localStorage.getItem("'.self::APPEARANCE_KEY.'");if(a==="dark"||a==="light"){document.documentElement.dataset.theme=a}}catch(e){}</script>' : '';
        $html .= $css === '' ? '' : '<style id="basalto-design">'.$css.'</style>';
        foreach ($design->styles as $path) {
            $url = $this->url($site, $path);
            if ($url !== null) {
                $html .= sprintf('<link rel="stylesheet" href="%s">', htmlspecialchars($url, ENT_QUOTES));
            }
        }

        return $html;
    }

    /**
     * Umschalter hell/dunkel für Besucher (`{% appearance %}`, TB 21) — nur, wenn das Theme dunkle Farben hat. Ein Knopf
     * mit Sonne und Mond; die Wahl liegt im Browser (localStorage), ohne sie ist die Seite hell — auch bei dunklem
     * Betriebssystem (Hoang, 27.10.2026).
     */
    public function appearanceToggle(Site $site, string $language): string
    {
        if (! $this->design($site)->hasDark()) {
            return '';
        }
        $label = match ($language) {
            'en' => 'Dark mode', 'fr' => 'Mode sombre', 'it' => 'Modalità scura', default => 'Dunkle Darstellung',
        };
        $icon = static fn (string $class, string $path): string => '<svg class="'.$class.'" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
        $moon = $icon('appearance-moon', '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>');
        $sun = $icon('appearance-sun', '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>');
        $key = self::APPEARANCE_KEY;

        return '<button type="button" class="appearance-toggle" aria-pressed="false" aria-label="'.htmlspecialchars($label, ENT_QUOTES).'" title="'.htmlspecialchars($label, ENT_QUOTES).'">'.$moon.$sun.'</button>'
            .'<script>(function(b){var r=document.documentElement;'
            .'function dark(){return r.dataset.theme==="dark"}'
            .'function show(){b.setAttribute("aria-pressed",dark()?"true":"false")}'
            .'b.addEventListener("click",function(){var a=dark()?"light":"dark";r.dataset.theme=a;try{localStorage.setItem("'.$key.'",a)}catch(e){}show()});'
            .'show()})(document.currentScript.previousElementSibling)</script>';
    }

    /** Schlüssel der Wahl hell/dunkel im Browser der Besucher. */
    public const APPEARANCE_KEY = 'basalto-erscheinung';

    /** @return Overrides */
    public static function overrides(Site $site): array
    {
        $design = $site->setting('design', []);

        return is_array($design) ? $design : [];
    }

    /** Nur Dateien unter `assets/` mit bekannter Endung, ohne Ausbruch aus dem Ordner (L-16). */
    public static function allowed(string $path): bool
    {
        return str_starts_with($path, 'assets/') && FileSource::isInside($path) && isset(self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
    }

    /**
     * @return list<Source> zuerst die wirksame Quelle (Datenbank oder Ordner), bei einem eigenen Theme dann seine Bilder
     *                      und Schriften, zuletzt der Theme-Ordner gleichen Namens
     */
    private function sources(Site $site): array
    {
        $resolved = $this->themes->sourceFor($site, false);
        // Die Bibliothek bringt Bilder und Schriften als AssetStore mit (unten, mit Prüfsumme aus dem Verzeichnis)
        $sources = [$resolved['source'] instanceof LibrarySource ? $resolved['source']->text() : $resolved['source']];
        if ($resolved['kind'] !== 'files') {
            $own = SiteTheme::query()->where('site_id', $site->id)->where('name', $resolved['name'])->first();
            if ($own !== null) {
                $sources[] = new AssetStore($own);
            }
            if (ThemeLibrary::hasFolder($resolved['name'])) {
                $sources[] = new FileSource(ThemeCatalog::path().'/'.$resolved['name']);
            }
        }

        return $sources;
    }
}
