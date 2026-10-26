<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

/**
 * Was ein Modul unter seiner Einhängeseite ausgibt (CR-006 FO-K1). Der Kern ruft es für die Seite selbst (`rest` leer)
 * und für jeden Pfad darunter; das Ergebnis steht an der Stelle der Einleitung im Layout des Themes.
 */
interface MountHandler
{
    public function handle(MountRequest $request): MountResult;
}
