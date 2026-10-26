<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

/**
 * Die Quelle des Themes einer Site, wie die Anwendung sie sieht (L-05: Konsole und Backend zeigen denselben Stand) —
 * Theme aus Dateien oder Datenbank, dahinter die Ergänzungen der Site (Typen, Formulare). Die Anwendung bindet eine
 * Umsetzung; ohne sie liest `basalto:check --site` nur die Theme-Quellen aus der Datenbank.
 */
interface SiteSourceResolver
{
    /** @return array{source: Source, kind: string}|null Quelle und Art («files» oder «database»), null ohne Site */
    public function forSite(string $handle): ?array;
}
