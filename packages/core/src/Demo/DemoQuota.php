<?php

declare(strict_types=1);

namespace Basalto\Core\Demo;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Speicher der öffentlichen Demo (TB 66 Nachtrag): Dort ist jede Person Betreiberin und darf hochladen — bis zum
 * nächtlichen Neuaufsetzen belegt das den Speicher des ganzen Hostings. Gezählt wird, was Besucher füllen können: die
 * Standard-Disk (Medien, Formular-Anhänge, Profilbilder, Theme-Bilder, Forum) und die öffentliche Ablage; nicht der
 * Rückweg des Updaters und keine Sicherungen. Grenze `BASALTO_DEMO_QUOTA_MB` (Vorgabe 200), 0 schaltet sie ab.
 * Ausserhalb der Demo gilt nichts davon.
 */
final class DemoQuota
{
    public static function limitBytes(): int
    {
        return max(0, (int) config('basalto.demo.quota_megabytes', 200)) * 1024 * 1024;
    }

    public static function usedBytes(): int
    {
        $disks = array_unique([(string) config('filesystems.default'), 'public']);
        $used = 0;
        foreach ($disks as $name) {
            if (! is_array(config('filesystems.disks.'.$name))) {
                continue;
            }
            $disk = Storage::disk($name);
            foreach ($disk->allFiles() as $path) {
                $used += self::size($disk, $path);
            }
        }

        return $used;
    }

    /** Fehlertext, wenn weitere `$bytes` nicht mehr Platz haben; sonst — und ausserhalb der Demo — null. */
    public static function refuse(int $bytes): ?string
    {
        $limit = self::limitBytes();
        if (! DemoMode::enabled() || $limit === 0 || $bytes <= 0 || self::usedBytes() + $bytes <= $limit) {
            return null;
        }

        return (string) __('Die Demo hat ihren Speicher für heute aufgebraucht (:mb MB). Sie wird jede Nacht zurückgesetzt — danach lassen sich wieder Dateien hochladen.', ['mb' => intdiv($limit, 1024 * 1024)]);
    }

    private static function size(Filesystem $disk, string $path): int
    {
        try {
            return (int) $disk->size($path);
        } catch (Throwable) {
            return 0;
        }
    }
}
