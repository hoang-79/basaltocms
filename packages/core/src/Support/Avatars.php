<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Profilbilder (TB 21): Ein hochgeladenes Bild wird mittig quadratisch zugeschnitten, auf 256 × 256 verkleinert und als
 * WebP auf der Standard-Disk abgelegt (`avatars/<id>/<zufall>.webp`) — das Original bleibt nicht liegen, Metadaten
 * (Ort, Kamera) fallen damit weg. Der Zufallsname dient als Version in der Adresse; ohne Bild zeigt das Backend Initialen.
 */
final class Avatars
{
    public const SIZE = 256;

    /** Adresse des Bildes oder null — für Kopfzeile, Menü, Profil und Benutzerliste. */
    public static function url(Model $user): ?string
    {
        $path = $user->getAttribute('avatar_path');

        return is_string($path) && $path !== '' ? route('basalto.profile.avatar', ['user' => $user->getKey(), 'file' => basename($path)]) : null;
    }

    /** Neues Bild speichern, das alte löschen; gibt den neuen Pfad zurück. CR-006: Community-Profile legen in einem eigenen Ordner ab. */
    public static function store(Model $user, UploadedFile $file, string $folder = 'avatars'): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw new RuntimeException('Bild nicht lesbar');
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopyresampled($square, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagewebp($square, null, 82);
        $webp = (string) ob_get_clean();
        imagedestroy($square);
        imagedestroy($source);

        $path = $folder.'/'.$user->getKey().'/'.Str::lower(Str::random(16)).'.webp';
        Storage::disk()->put($path, $webp);
        self::delete($user);
        $user->forceFill(['avatar_path' => $path])->save();

        return $path;
    }

    /** Bild löschen (Datei und Verweis). */
    public static function delete(Model $user): void
    {
        $path = $user->getAttribute('avatar_path');
        if (is_string($path) && $path !== '') {
            Storage::disk()->delete($path);
        }
        if ($user->getAttribute('avatar_path') !== null) {
            $user->forceFill(['avatar_path' => null])->save();
        }
    }
}
