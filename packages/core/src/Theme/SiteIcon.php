<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Website-Symbol (Favicon) einer Site (Wunsch Hoang 16.11.2026: nguyen-development.ch zeigte das Symbol von Basalto).
 * Die Quelle in dieser Reihenfolge: ein Bild aus der Mediathek (Einrichtung › Design), `favicon` im `design` des
 * Themes, das Logo (Mediathek bzw. Theme). Ohne Quelle gilt das Symbol von Basalto.
 *
 * Aus einem Rasterbild (PNG, JPEG, GIF, WebP) entstehen beim ersten Abruf `favicon.ico` (32 Pixel, PNG im ICO) und
 * `icon-180.png` (Home-Bildschirm von iPhone und iPad) unter `storage/app/site-icons/<site>/` — gezeichnet mit GD wie
 * die Vorschaubilder, ohne `exec()`. Ist die Quelle ein SVG des Themes, nimmt Basalto für die Rastergrössen dieselbe
 * Datei als `.png` daneben, wenn es sie gibt (wie beim Logo in Mails); ein SVG aus der Mediathek erscheint nur als SVG.
 * Die Prüfsumme in der Adresse ändert sich mit der Quelle — Browser dürfen die Dateien ein Jahr behalten.
 *
 * @phpstan-type Source array{key: string, raster: string|null, svg: string|null, origin: 'media'|'theme'|'logo'}
 */
final class SiteIcon
{
    /** Steigt, wenn sich die Zeichnung ändert — dann entstehen alle Dateien neu. */
    private const REVISION = 1;

    /**
     * Dateien unter `/_symbol/<site>/<prüfsumme>/` → Kantenlänge in Pixel.
     *
     * @var array<string, int<1, max>>
     */
    public const FILES = ['favicon.ico' => 32, 'icon-180.png' => 180];

    /** Bildarten, die als Website-Symbol taugen. */
    public const MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];

    /** Die Symbole von Basalto (Backend, Sites ohne eigenes) — im Kern, nicht in `public/`, damit `/favicon.ico` je Site antwortet. */
    public const BASALTO = ['favicon.ico' => 'image/x-icon', 'favicon.svg' => 'image/svg+xml', 'apple-touch-icon.png' => 'image/png'];

    public function __construct(private readonly ThemeAssets $assets) {}

    /** Zeilen für den Kopf der Seite (`{% assets %}`); leer ohne eigenes Symbol — dann fragt der Browser `/favicon.ico`. */
    public function head(Site $site): string
    {
        $source = $this->source($site);
        if ($source === null) {
            return '';
        }
        $html = '';
        if ($source['raster'] !== null && self::available()) {
            $html .= sprintf('<link rel="icon" href="%s" sizes="32x32">', htmlspecialchars($this->url($site, $source, 'favicon.ico'), ENT_QUOTES));
        }
        if ($source['svg'] !== null) {
            $html .= sprintf('<link rel="icon" href="%s" type="image/svg+xml">', htmlspecialchars($source['svg'], ENT_QUOTES));
        }
        if ($source['raster'] !== null && self::available()) {
            $html .= sprintf('<link rel="apple-touch-icon" href="%s">', htmlspecialchars($this->url($site, $source, 'icon-180.png'), ENT_QUOTES));
        }

        return $html;
    }

    /** Adresse zur Vorschau im Backend: das SVG oder das grösste Rasterbild; null ohne eigenes Symbol. */
    public function previewUrl(Site $site): ?string
    {
        $source = $this->source($site);
        if ($source === null) {
            return null;
        }

        return $source['raster'] !== null && self::available() ? $this->url($site, $source, 'icon-180.png') : $source['svg'];
    }

    /** Woher das Symbol kommt: «media», «theme», «logo» oder null (Symbol von Basalto). */
    public function origin(Site $site): ?string
    {
        return $this->source($site)['origin'] ?? null;
    }

    /**
     * Pfad der fertigen Datei — beim ersten Abruf gezeichnet; null, wenn die Prüfsumme nicht (mehr) zur Quelle passt.
     *
     * @return array{path: string, mime: string}|null
     */
    public function file(Site $site, string $hash, string $name): ?array
    {
        $size = self::FILES[$name] ?? null;
        $source = $this->source($site);
        if ($size === null || $source === null || $source['raster'] === null || ! self::available() || ! hash_equals(self::hash($source), $hash)) {
            return null;
        }
        $path = self::directory($site->id).'/'.$hash.'-'.$name;
        if (! is_file($path)) {
            $png = self::png($source['raster'], $size);
            if ($png === null) {
                return null;
            }
            @mkdir(dirname($path), 0775, true);
            $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            file_put_contents($tmp, str_ends_with($name, '.ico') ? self::ico($png, $size) : $png, LOCK_EX);
            @rename($tmp, $path);
        }

        return is_file($path) ? ['path' => $path, 'mime' => str_ends_with($name, '.ico') ? 'image/x-icon' : 'image/png'] : null;
    }

    /**
     * Antwort auf `/favicon.ico` der Site: ihr Symbol, sonst das von Basalto.
     *
     * @return array{path: string, mime: string}
     */
    public function favicon(?Site $site): array
    {
        if ($site !== null && ($source = $this->source($site)) !== null && $source['raster'] !== null) {
            $file = $this->file($site, self::hash($source), 'favicon.ico');
            if ($file !== null) {
                return $file;
            }
        }

        return ['path' => self::basaltoPath('favicon.ico'), 'mime' => self::BASALTO['favicon.ico']];
    }

    public static function basaltoPath(string $name): string
    {
        return dirname(__DIR__, 2).'/resources/icons/'.$name;
    }

    public static function directory(int $siteId): string
    {
        return storage_path('app/site-icons/'.$siteId);
    }

    /** Ob GD mit PNG da ist (sonst nur das SVG, wenn es eines gibt). */
    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    /**
     * Die Symbole von Basalto aus früheren Fassungen in `public/` entfernen, wenn sie unverändert sind — sonst liefert
     * der Webserver sie vor Basalto aus, und keine Site zeigt ihr eigenes Symbol. Eigene Dateien bleiben.
     *
     * @return list<string> entfernte Dateien
     */
    public static function removeShippedDefaults(string $publicDir): array
    {
        $removed = [];
        foreach (array_keys(self::BASALTO) as $name) {
            $file = rtrim($publicDir, '/').'/'.$name;
            $shipped = self::basaltoPath($name);
            if (is_file($file) && ! is_link($file) && is_file($shipped) && hash_file('sha256', $file) === hash_file('sha256', $shipped) && @unlink($file)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    /** @param  Source  $source */
    private function url(Site $site, array $source, string $name): string
    {
        return route('basalto.site-icon', ['site' => $site->id, 'hash' => self::hash($source), 'file' => $name], false);
    }

    /** @param  Source  $source */
    private static function hash(array $source): string
    {
        return substr(sha1(self::REVISION.'|'.$source['key']), 0, 16);
    }

    /**
     * Die Quelle: Bytes eines Rasterbilds und/oder die Adresse eines SVG, mit einem Schlüssel, der sich mit ihr ändert.
     *
     * @return Source|null
     */
    private function source(Site $site): ?array
    {
        try {
            $overrides = ThemeAssets::overrides($site);
            $design = $this->assets->design($site);
            if (($media = $this->media($site, $overrides['favicon_media_id'] ?? null)) !== null) {
                return $this->fromMedia($media, 'media');
            }
            if ($design->favicon !== null && ($source = $this->fromTheme($site, $design->favicon, 'theme')) !== null) {
                return $source;
            }
            // Das Logo: ein eigenes aus der Mediathek, sonst das des Themes
            if (($logo = $this->media($site, $overrides['logo_media_id'] ?? null)) !== null) {
                return $this->fromMedia($logo, 'logo');
            }

            return $design->logo !== null ? $this->fromTheme($site, $design->logo, 'logo') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function media(Site $site, mixed $id): ?Media
    {
        if (! is_numeric($id)) {
            return null;
        }
        $media = Media::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereKey((int) $id)->whereNull('deleted_at')->first();

        return $media !== null && in_array($media->mime, self::MIMES, true) ? $media : null;
    }

    /**
     * @param  'media'|'theme'|'logo'  $origin
     * @return Source|null
     */
    private function fromMedia(Media $media, string $origin): ?array
    {
        $key = 'media:'.$media->id.':'.$media->path.':'.($media->updated_at?->getTimestamp() ?? 0);
        if ($media->mime === 'image/svg+xml') {
            return ['key' => $key, 'raster' => null, 'svg' => $media->url(), 'origin' => $origin];
        }
        $bytes = Storage::disk($media->disk)->get($media->path);

        return is_string($bytes) && $bytes !== '' ? ['key' => $key, 'raster' => $bytes, 'svg' => null, 'origin' => $origin] : null;
    }

    /**
     * @param  'media'|'theme'|'logo'  $origin
     * @return Source|null
     */
    private function fromTheme(Site $site, string $path, string $origin): ?array
    {
        if (! str_starts_with($path, 'assets/')) {
            return null;
        }
        $svg = str_ends_with(strtolower($path), '.svg');
        $rasterPath = $svg ? (string) preg_replace('/\.svg$/i', '.png', $path) : $path;
        $raster = $this->assets->read($site, $rasterPath);
        $raster = is_string($raster) && $raster !== '' ? $raster : null;
        $svgUrl = $svg ? $this->assets->url($site, $path) : null;
        if ($raster === null && $svgUrl === null) {
            return null;
        }

        return ['key' => 'theme:'.$path.':'.sha1(($raster ?? '').'|'.($svgUrl ?? '')), 'raster' => $raster, 'svg' => $svgUrl, 'origin' => $origin];
    }

    /**
     * Quadratisches PNG: das Bild eingepasst, Ränder durchsichtig.
     *
     * @param  int<1, max>  $size  Kantenlänge in Pixel (aus FILES)
     */
    private static function png(string $bytes, int $size): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            return null;
        }
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = $size / max($w, $h);
        [$tw, $th] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        imagecopyresampled($canvas, $source, intdiv($size - $tw, 2), intdiv($size - $th, 2), 0, 0, $tw, $th, $w, $h);
        ob_start();
        imagepng($canvas, null, 9);

        return (string) ob_get_clean();
    }

    /** ICO-Datei mit einem PNG darin (so lesen es alle heutigen Browser). */
    private static function ico(string $png, int $size): string
    {
        $edge = $size >= 256 ? 0 : $size;

        return pack('vvv', 0, 1, 1).pack('CCCCvvVV', $edge, $edge, 0, 0, 1, 32, strlen($png), 22).$png;
    }
}
