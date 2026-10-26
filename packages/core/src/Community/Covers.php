<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cover-Fotos der Community-Profile (CR-006 FO-18): auf 1500 × 400 zugeschnitten und als WebP neu gespeichert —
 * ohne Ort und Kamera, wie die Profilbilder.
 */
final class Covers
{
    public const WIDTH = 1500;

    public const HEIGHT = 400;

    public static function store(CommunityProfile $profile, UploadedFile $file): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw new RuntimeException('Bild nicht lesbar');
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = self::WIDTH / self::HEIGHT;
        [$cropW, $cropH] = $width / $height > $ratio ? [(int) round($height * $ratio), $height] : [$width, (int) round($width / $ratio)];
        $target = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $cropW, 2), intdiv($height - $cropH, 2), self::WIDTH, self::HEIGHT, $cropW, $cropH);
        ob_start();
        imagewebp($target, null, 80);
        $webp = (string) ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);
        $path = 'community-covers/'.$profile->id.'/'.Str::lower(Str::random(16)).'.webp';
        Storage::disk()->put($path, $webp);
        self::delete($profile);
        $profile->forceFill(['cover_path' => $path])->save();

        return $path;
    }

    public static function delete(CommunityProfile $profile): void
    {
        if (is_string($profile->cover_path) && $profile->cover_path !== '') {
            Storage::disk()->delete($profile->cover_path);
            $profile->forceFill(['cover_path' => null])->save();
        }
    }
}
