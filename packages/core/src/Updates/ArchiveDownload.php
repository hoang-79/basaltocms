<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Core;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Archive laden und entpacken (CR-007, CR-018) — gemeinsam für Updates (Kern, Plugins in `vendor`) und das
 * Installieren von Plugins im Backend: Grösse und SHA-256 prüfen, beim Entpacken keinen Pfad aus dem Ziel heraus.
 */
final class ArchiveDownload
{
    /**
     * Laden mit Prüfung von Grösse und SHA-256.
     *
     * @param  array{0: string, 1: string}|null  $basicAuth
     */
    public static function fetch(string $url, string $target, int $maxBytes, ?string $sha256, ?int $size = null, ?array $basicAuth = null): void
    {
        try {
            $request = Http::timeout(600)->connectTimeout(10)->withUserAgent('Basalto/'.Core::VERSION)->sink($target);
            $response = ($basicAuth !== null ? $request->withBasicAuth($basicAuth[0], $basicAuth[1]) : $request)->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException((string) __(':host ist nicht erreichbar.', ['host' => (string) parse_url($url, PHP_URL_HOST)]));
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException((string) __('Kein Zugriff auf das Paket (Kundenkonto, Token oder Update-Jahr prüfen).'));
        }
        if (! $response->successful()) {
            throw new RuntimeException((string) __('Der Download antwortet mit :status.', ['status' => $response->status()]));
        }
        $bytes = (int) @filesize($target);
        if ($bytes === 0 || $bytes > $maxBytes || ($size !== null && $bytes !== $size)) {
            throw new RuntimeException((string) __('Das Archiv hat nicht die erwartete Grösse.'));
        }
        if ($sha256 !== null && ! hash_equals($sha256, (string) hash_file('sha256', $target))) {
            throw new RuntimeException((string) __('Die Prüfsumme des Archivs passt nicht.'));
        }
    }

    /** Entpacken in einen neuen Ordner — ein Pfad aus dem Archiv heraus bricht ab, bevor etwas geschrieben wird. */
    public static function extract(string $archive, string $stage): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException((string) __('Das Archiv lässt sich nicht öffnen.'));
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                $zip->close();
                throw new RuntimeException((string) __('Das Archiv enthält ungültige Pfade.'));
            }
        }
        @mkdir($stage, 0775, true);
        if (! $zip->extractTo($stage)) {
            $zip->close();
            throw new RuntimeException((string) __('Das Archiv lässt sich nicht entpacken.'));
        }
        $zip->close();
    }
}
