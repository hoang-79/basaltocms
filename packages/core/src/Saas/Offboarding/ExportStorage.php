<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

/**
 * Wo Exporte und Umzugsarchive liegen (TB 12 Scheibe 4): im privaten Speicher der Instanz (M-03: ein privater Bucket je
 * Instanz) — `basalto.saas.export_disk`, ohne Angabe die Vorgabe-Disk. Nie öffentlich: Abruf nur über die signierte,
 * angemeldete Route bzw. die signierte Schnittstelle zwischen den Instanzen.
 */
final class ExportStorage
{
    public static function diskName(): string
    {
        $disk = config('basalto.saas.export_disk');

        return is_string($disk) && $disk !== '' ? $disk : (string) config('filesystems.default', 'local');
    }

    /** Tage, die ein Export abrufbar bleibt (Mockup 24: «7 Tage verfügbar»). */
    public static function days(): int
    {
        return max(1, (int) config('basalto.saas.export_days', 7));
    }
}
