<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeAssets;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Erzeugte Vorschaubilder (CR-019, TB 106, E-1): Hat eine Seite weder Seitenbild noch ein Bild im Inhalt und die Site
 * kein eigenes Vorschaubild, zeigt ein geteilter Link trotzdem ein Bild — 1200 × 630 Pixel mit dem Titel der Seite,
 * dem Logo und der Adresse der Site in den Farben des Themes.
 *
 * Beim Rendern der Seite legt Basalto nur Titel und Sprache unter `storage/app/share-images/<site>/<prüfsumme>.json` ab
 * (schnell); das Bild entsteht beim ersten Abruf von `/_teilen/<site>/<prüfsumme>.png` (meist durch ein Netzwerk, das
 * die Vorschau baut) und bleibt liegen. Ändert sich Titel, Name, Farbe oder Logo, ändert sich die Prüfsumme. Gezeichnet
 * wird mit GD und der Schrift DejaVu Sans aus dompdf — ohne `exec()`, auch auf geteiltem Hosting.
 */
final class ShareImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** Steigt, wenn sich die Gestaltung ändert — dann entstehen alle Bilder neu. */
    private const REVISION = 1;

    private const MARGIN = 80;

    public function __construct(private readonly ThemeAssets $assets) {}

    /** Ob GD mit TrueType-Schrift und PNG da ist (sonst bleibt die Seite ohne erzeugtes Bild). */
    public function available(): bool
    {
        return function_exists('imagettftext') && function_exists('imagepng') && is_file(self::font(true));
    }

    /** Adresse (Pfad) des Bilds für einen Titel; legt die Angaben dafür ab, falls sie fehlen. */
    public function url(Site $site, string $title, string $locale): string
    {
        $hash = substr(sha1((string) json_encode([self::REVISION, $site->id, $site->name, $site->publicUrl(), $title, $locale, $this->style($site)])), 0, 32);
        $meta = self::directory($site->id).'/'.$hash.'.json';
        if (! is_file($meta)) {
            @mkdir(dirname($meta), 0775, true);
            @file_put_contents($meta, (string) json_encode(['title' => $title, 'locale' => $locale], JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        return route('basalto.share.image', ['site' => $site->id, 'file' => $hash.'.png'], false);
    }

    /** Pfad der fertigen PNG-Datei — beim ersten Abruf gezeichnet; null, wenn es die Prüfsumme nicht gibt. */
    public function file(Site $site, string $hash): ?string
    {
        $base = self::directory($site->id).'/'.$hash;
        if (! is_file($base.'.json') || ! $this->available()) {
            return null;
        }
        if (is_file($base.'.png')) {
            return $base.'.png';
        }
        $data = json_decode((string) file_get_contents($base.'.json'), true);
        $title = is_array($data) && is_string($data['title'] ?? null) ? $data['title'] : $site->name;
        $image = $this->draw($site, $title);
        $tmp = $base.'.'.bin2hex(random_bytes(4)).'.tmp';
        imagepng($image, $tmp, 9);
        @rename($tmp, $base.'.png');

        return is_file($base.'.png') ? $base.'.png' : null;
    }

    public static function directory(int $siteId): string
    {
        return storage_path('app/share-images/'.$siteId);
    }

    private function draw(Site $site, string $title): GdImage
    {
        $style = $this->style($site);
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($image, true);
        $color = static fn (string $hex): int => (int) imagecolorallocate($image, ...self::rgb($hex));
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $color($style['background']));
        imagefilledrectangle($image, 0, self::HEIGHT - 18, self::WIDTH - 1, self::HEIGHT - 1, $color($style['accent']));

        $top = self::MARGIN;
        $logo = $this->logo($site);
        if ($logo !== null) {
            [$w, $h] = [imagesx($logo), imagesy($logo)];
            $scale = min(84 / $h, 440 / $w, 1.0);
            imagecopyresampled($image, $logo, self::MARGIN, $top, 0, 0, (int) round($w * $scale), (int) round($h * $scale), $w, $h);
            $top += (int) round($h * $scale) + 56;
        } else {
            $top += 40;
        }

        // Titel: so gross wie möglich, höchstens vier Zeilen
        $bold = self::font(true);
        $width = self::WIDTH - 2 * self::MARGIN;
        $room = self::HEIGHT - 150 - $top;
        $size = 64;
        $lines = self::wrap($title, $bold, $size, $width);
        while ((count($lines) > 4 || count($lines) * $size * 1.3 > $room) && $size > 36) {
            $size -= 4;
            $lines = self::wrap($title, $bold, $size, $width);
        }
        $max = max(1, min(4, (int) floor($room / ($size * 1.3))));
        if (count($lines) > $max) {
            $lines = array_slice($lines, 0, $max);
            $lines[$max - 1] = rtrim($lines[$max - 1], ' ,.;:–—-').'…';
        }
        $y = $top + $size;
        foreach ($lines as $line) {
            imagettftext($image, $size, 0, self::MARGIN, (int) $y, $color($style['text']), $bold, $line);
            $y += $size * 1.3;
        }

        $host = (string) (parse_url($site->publicUrl(), PHP_URL_HOST) ?: $site->name);
        imagettftext($image, 28, 0, self::MARGIN, self::HEIGHT - 70, $color($style['muted']), self::font(false), $host);

        return $image;
    }

    /** @return list<string> Zeilen, die in die Breite passen — zu lange Wörter werden geteilt */
    private static function wrap(string $text, string $font, int $size, int $width): array
    {
        $measure = static function (string $s) use ($font, $size): int {
            $box = imagettfbbox($size, 0, $font, $s);

            return $box === false ? 0 : abs($box[2] - $box[0]);
        };
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            while ($measure($word) > $width && mb_strlen($word) > 1) {
                $cut = mb_strlen($word) - 1;
                while ($cut > 1 && $measure(mb_substr($word, 0, $cut)) > $width) {
                    $cut--;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $measure($candidate) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /** @return array{background: string, text: string, accent: string, muted: string, logo: string} */
    private function style(Site $site): array
    {
        try {
            $design = $this->assets->design($site);
            $colors = $design->colors;
            $logo = (string) ($design->logo ?? '');
        } catch (Throwable) {
            $colors = [];
            $logo = '';
        }
        $pick = static fn (string $name, string $fallback): string => is_string($colors[$name] ?? null) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $colors[$name]) === 1 ? $colors[$name] : $fallback;

        return ['background' => $pick('background', '#ffffff'), 'text' => $pick('text', '#1f2328'), 'accent' => $pick('accent', $pick('link', '#1f2328')),
            'muted' => $pick('muted', $pick('text', '#1f2328')), 'logo' => $logo];
    }

    /** Das Logo als Rasterbild: aus der Mediathek (PNG/JPEG/GIF) oder neben dem Logo des Themes dieselbe Datei als `.png`. */
    private function logo(Site $site): ?GdImage
    {
        try {
            $mediaId = ThemeAssets::overrides($site)['logo_media_id'] ?? null;
            $bytes = null;
            if ($mediaId !== null) {
                $media = Media::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereKey($mediaId)->first();
                if ($media !== null && in_array($media->mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                    $bytes = Storage::disk($media->disk)->get($media->path);
                }
            } else {
                $logo = $this->assets->design($site)->logo;
                if (is_string($logo) && str_starts_with($logo, 'assets/')) {
                    $png = preg_match('/\.(png|jpe?g|gif)$/i', $logo) === 1 ? $logo : (string) preg_replace('/\.[a-z0-9]+$/i', '.png', $logo);
                    $bytes = $this->assets->read($site, $png);
                }
            }
            $image = is_string($bytes) && $bytes !== '' ? @imagecreatefromstring($bytes) : false;

            return $image instanceof GdImage ? $image : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $part = static fn (int $at): int => max(0, min(255, (int) hexdec(substr($hex, $at, 2))));

        return [$part(0), $part(2), $part(4)];
    }

    private static function font(bool $bold): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/'.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));
    }
}
