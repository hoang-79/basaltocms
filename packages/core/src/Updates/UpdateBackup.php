<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Backup\Backups;

/** Die Sicherung vor einem Update (CR-007, U-05) — Datenbank und Medien wie `basalto:backup`; in Tests ersetzbar. */
class UpdateBackup
{
    public function __construct(private readonly Backups $backups) {}

    /** @return string Pfad der Sicherung */
    public function create(string $directory): string
    {
        return (string) $this->backups->create($directory)['path'];
    }
}
