<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

/**
 * Der Vite-Dev-Server des Frontends (L-13): Solange `npm run dev` läuft, nennt seine Hot-Datei die Adresse — Seiten
 * tragen dann den Vite-Client, und der Seiten-Cache nimmt nichts auf. Gilt nur lokal und in Tests. Die Datei ist
 * `public/hot`, ausser `basalto.hot_file` nennt eine andere (Tests: eine eigene je Test, siehe HidesViteHotFile).
 */
final class DevServer
{
    public static function hotFile(): string
    {
        $file = config('basalto.hot_file');

        return is_string($file) && $file !== '' ? $file : public_path('hot');
    }

    public static function running(): bool
    {
        return app()->environment(['local', 'testing']) && is_file(self::hotFile());
    }

    /** Adresse des laufenden Dev-Servers ohne Schrägstrich am Ende — oder null. */
    public static function url(): ?string
    {
        if (! self::running()) {
            return null;
        }
        $url = rtrim(trim((string) @file_get_contents(self::hotFile())), '/');

        return $url !== '' ? $url : null;
    }
}
