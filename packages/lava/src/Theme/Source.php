<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

/** Woher ein Theme kommt: Dateien im Repo oder Datensätze in der Datenbank (R-97) — derselbe Compiler für beide. */
interface Source
{
    public function name(): string;

    public function has(string $path): bool;

    public function read(string $path): string;

    /** @return list<string> alle Pfade, z. B. «theme.lava», «blocks/hero.lava.html» */
    public function paths(): array;

    /**
     * Fingerabdruck des Inhalts: ändert sich, sobald eine Datei dazukommt, wegfällt oder anders wird —
     * Schlüssel des Baum-Caches je Instanz (ADR-013). Muss günstig sein (Pfade, Grössen, Zeitstempel; kein Volltext).
     */
    public function fingerprint(): string;
}
