<?php

declare(strict_types=1);

use Basalto\Core\Theme\SiteIcon;
use Illuminate\Database\Migrations\Migration;

/**
 * Website-Symbol je Site (1.3.41): Die Symbole von Basalto lagen bis 1.3.40 in `public/` — der Webserver lieferte sie
 * vor Basalto aus, und keine Site zeigte ihr eigenes. Ein Update tauscht Datei für Datei und lässt liegen, was nicht im
 * Archiv steht; deshalb räumt dieser Schritt sie weg, und nur, wenn sie unverändert sind (eigene Dateien bleiben). Die
 * Datenbank ändert er nicht — die Migration ist der Schritt, der nach jedem Update sicher mit dem neuen Code läuft.
 */
return new class extends Migration
{
    public function up(): void
    {
        SiteIcon::removeShippedDefaults(public_path());
    }

    public function down(): void
    {
        // Die Symbole von Basalto liefert der Kern jetzt selbst aus (/_symbol/basalto/…, /favicon.ico)
    }
};
