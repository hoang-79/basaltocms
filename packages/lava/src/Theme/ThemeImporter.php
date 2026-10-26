<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

/**
 * Wohin `basalto:lava-import --scope=theme` ein Theme schreibt — die Anwendung bindet eine Umsetzung (im Kern die
 * Theme-Bibliothek der Site, TB 22); ohne sie landen die Dateien im Geltungsbereich «theme».
 */
interface ThemeImporter
{
    /** @return int Anzahl übernommener Dateien */
    public function importTheme(Source $source, int $siteId): int;
}
