<?php

declare(strict_types=1);

use Basalto\Core\Install\QuickInstall;
use Illuminate\Contracts\Console\Kernel;

/*
 * Brücke für den Web-Installer (CR-004, P-04): Der Installer (eine einzelne index.php ohne Laravel) entpackt das
 * Release, schreibt die .env und ruft diese Datei. Sie bootet Basalto und richtet die erste Site in einem Zug ein.
 * Rückgabe: Einmal-Link zur Anmeldung und Notizen.
 */
return static function (array $input): array {
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app->make(QuickInstall::class)->run($input);
};
