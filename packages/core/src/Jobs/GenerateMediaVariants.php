<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Media\ImageVariant;
use Basalto\Core\Media\ImageVariants;
use Basalto\Core\Models\Media;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Imagick;
use RuntimeException;

/**
 * Erzeugt die Bildvarianten des Themes (K-09) mit GD als WebP — und als AVIF, wo GD oder Imagick es können — und legt
 * sie neben das Original im Objektspeicher ab; der Zuschnitt «exact» folgt dem Fokuspunkt, höhere Dichten entstehen nur,
 * wenn das Original gross genug ist. Läuft in der Warteschlange, nie in der Anfrage; Lesen und Schreiben nur über die Disk.
 */
final class GenerateMediaVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $mediaId) {}

    public function handle(ImageVariants $variants): void
    {
        $media = Media::query()->withoutGlobalScopes()->withTrashed()->findOrFail($this->mediaId);
        if (! $media->isRasterImage()) {
            return;
        }
        $storage = $media->storage();
        $source = @imagecreatefromstring((string) $storage->get($media->path));
        if ($source === false) {
            throw new RuntimeException(__('Bild «:name» konnte nicht gelesen werden', ['name' => $media->name]));
        }
        imagepalettetotruecolor($source);
        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $previous = $media->variantPaths();
        $result = [];
        $base = $media->basePath();
        foreach ($variants->forSite($media->site) as $variant) {
            $files = [];
            foreach ($variant->densities as $density) {
                $target = self::target($variant, $density, $srcW, $srcH, $media->focus_x, $media->focus_y);
                if ($target === null) {
                    continue;
                }
                $scaled = imagecreatetruecolor($target['w'], $target['h']);
                if ($scaled === false) {
                    throw new RuntimeException(__('Variante «:name» konnte nicht angelegt werden', ['name' => $variant->name]));
                }
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagecopyresampled($scaled, $source, 0, 0, $target['x'], $target['y'], $target['w'], $target['h'], $target['cw'], $target['ch']);
                $suffix = $density > 1 ? sprintf('-%s@%dx', $variant->name, $density) : '-'.$variant->name;
                $webp = sprintf('%s%s.webp', $base, $suffix);
                $storage->put($webp, self::encode($scaled, 'webp'));
                $avifBytes = self::encodeAvif($scaled);
                $avif = null;
                if ($avifBytes !== null) {
                    $avif = sprintf('%s%s.avif', $base, $suffix);
                    $storage->put($avif, $avifBytes);
                }
                $files[(string) $density] = ['path' => $webp, 'width' => $target['w'], 'height' => $target['h'], 'avif' => $avif];
            }
            if (! isset($files['1'])) {
                continue;
            }
            $one = $files['1'];
            unset($files['1']);
            $result[$variant->name] = $one + ['densities' => $files];
        }
        $media->forceFill(['width' => $srcW, 'height' => $srcH, 'variants' => $result, 'variants_generated_at' => now()])->save();
        foreach (array_diff($previous, $media->variantPaths()) as $stale) {
            $storage->delete($stale);
        }
    }

    /**
     * Ausschnitt und Zielmasse einer Variante in einer Dichte — null, wenn das Original für diese Dichte zu klein ist
     * (ab 2x) oder nichts zu tun bleibt.
     *
     * @return array{x: int, y: int, cw: int<1, max>, ch: int<1, max>, w: int<1, max>, h: int<1, max>}|null
     */
    public static function target(ImageVariant $variant, int $density, int $srcW, int $srcH, float $focusX, float $focusY): ?array
    {
        $w = $variant->width * $density;
        $h = $variant->height !== null ? $variant->height * $density : null;
        if ($density > 1 && ($srcW < $w || ($h !== null && $srcH < $h))) {
            return null;
        }
        if ($variant->mode === ImageVariant::MODE_EXACT && $h !== null) {
            $ratio = $w / $h;
            $cw = min($srcW, (int) round($srcH * $ratio));
            $ch = min($srcH, (int) round($cw / $ratio));
            $cw = min($cw, (int) round($ch * $ratio));
            $x = (int) round(max(0, min($srcW - $cw, $focusX * $srcW - $cw / 2)));
            $y = (int) round(max(0, min($srcH - $ch, $focusY * $srcH - $ch / 2)));
            $outW = min($w, $cw);

            return ['x' => $x, 'y' => $y, 'cw' => max(1, $cw), 'ch' => max(1, $ch), 'w' => max(1, $outW), 'h' => max(1, (int) round($outW / $ratio))];
        }
        $factor = min(1, $w / $srcW);
        if ($variant->mode === ImageVariant::MODE_FIT && $h !== null) {
            $factor = min($factor, $h / $srcH);
        }

        return ['x' => 0, 'y' => 0, 'cw' => max(1, $srcW), 'ch' => max(1, $srcH), 'w' => max(1, (int) round($srcW * $factor)), 'h' => max(1, (int) round($srcH * $factor))];
    }

    private static function encode(GdImage $image, string $format): string
    {
        ob_start();
        if ($format === 'webp') {
            imagewebp($image, null, 82);
        } else {
            imagepng($image, null, 6);
        }

        return (string) ob_get_clean();
    }

    /** AVIF über GD (wenn mit libavif gebaut), sonst über Imagick, sonst gar nicht — WebP bleibt immer. */
    private static function encodeAvif(GdImage $image): ?string
    {
        if (function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false) === true) {
            ob_start();
            $ok = @imageavif($image, null, 60);
            $bytes = (string) ob_get_clean();

            return $ok && $bytes !== '' ? $bytes : null;
        }
        if (! class_exists(Imagick::class) || Imagick::queryFormats('AVIF') === []) {
            return null;
        }
        try {
            $magick = new Imagick;
            $magick->readImageBlob(self::encode($image, 'png'));
            $magick->setImageFormat('avif');
            $magick->setImageCompressionQuality(60);
            $bytes = $magick->getImageBlob();
            $magick->clear();

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
