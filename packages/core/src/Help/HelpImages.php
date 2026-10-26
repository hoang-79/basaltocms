<?php

declare(strict_types=1);

namespace Basalto\Core\Help;

use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Vendor;

/**
 * Bilder der Doku (TB 29): Screenshots und Schemas liegen neben dem Text in `docs/<bereich>/bilder/` (Übersetzungen
 * dürfen eigene in `docs/<sprache>/<bereich>/bilder/` haben, sonst gilt das deutsche). Ausgeliefert über
 * `/_doku/{pfad}` — öffentlich wie die Doku selbst, nur Dateien in einem Ordner `bilder`, nur WebP, PNG und SVG.
 */
final class HelpImages
{
    public const PATTERN = '([a-z]{2}/)?[a-z0-9_-]+/bilder/[a-z0-9_-]+\.(webp|png|svg)';

    /** Der Ordner `docs/` (aus dem Ordner der Hilfe abgeleitet, damit `basalto.help_path` auch hier gilt). */
    public static function root(): string
    {
        return dirname(HelpPages::directory('de', 'redaktion'));
    }

    /** Datei zu einem Pfad relativ zu `docs/` — mit Rückfall von der Übersetzung aufs englische, dann aufs deutsche Bild. */
    public static function file(string $path): ?string
    {
        if (preg_match('#^'.self::PATTERN.'$#', $path) !== 1) {
            return null;
        }
        $root = (string) realpath(self::root());
        $candidates = [$path];
        if (preg_match('#^([a-z]{2})/#', $path, $m) === 1) {
            if ($m[1] !== 'en') {
                $candidates[] = 'en/'.substr($path, 3);
            }
            $candidates[] = substr($path, 3);
        }
        foreach ($candidates as $candidate) {
            $real = realpath($root.'/'.$candidate);
            if ($real !== false && $root !== '' && str_starts_with($real, $root.'/') && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Ein relativer Bildpfad aus einer Seite (`bilder/seiten-baum.webp`, `../redaktion/bilder/x.svg`) → Pfad relativ zu
     * `docs/`, null für fremde Adressen oder Pfade ausserhalb.
     */
    public static function resolve(string $section, string $locale, string $src): ?string
    {
        if (preg_match('#^[a-z]+:|^/#i', $src) === 1) {
            return null;
        }
        $parts = array_values(array_filter([$locale === 'de' ? '' : $locale, ...explode('/', $section)], static fn (string $p): bool => $p !== '' && $p !== '.'));
        foreach (explode('/', $src) as $part) {
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $path = implode('/', $parts);

        return preg_match('#^'.self::PATTERN.'$#', $path) === 1 ? $path : null;
    }

    public static function url(string $path): string
    {
        $relative = route('basalto.docs.image', ['path' => $path], false);

        // TB 29: Auf dem Host des Extranets gibt es keine Frontend-Routen — die Bilder kommen von der Hersteller-Site
        return Extranet::isRequest(request()) ? Vendor::url(ltrim($relative, '/')) : $relative;
    }
}
