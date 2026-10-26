<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use RuntimeException;
use ZipArchive;

/**
 * Das Archiv eines Plugins aus dem Marktplatz prüfen (CR-007, U-08): `basalto-manifest.json` und `basalto-signature`
 * an der Wurzel. Die Signatur (Ed25519, Marktplatz-Schlüssel) gilt dem Manifest; das Manifest nennt Name, Version und
 * die SHA-256 jeder Datei. Jede Datei muss passen, keine darf fehlen oder dazukommen.
 */
final class PluginArchive
{
    public static function verify(string $path, string $name, string $version, string $publicKey): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException((string) __('Das Archiv lässt sich nicht öffnen.'));
        }
        try {
            $manifestText = $zip->getFromName('basalto-manifest.json');
            $signature = base64_decode(trim((string) $zip->getFromName('basalto-signature')), true);
            $key = base64_decode(trim($publicKey), true);
            if (! is_string($manifestText) || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || ! sodium_crypto_sign_verify_detached($signature, $manifestText, $key)) {
                throw new RuntimeException((string) __('Die Signatur des Marktplatzes fehlt oder passt nicht.'));
            }
            $manifest = json_decode($manifestText, true);
            if (! is_array($manifest) || ($manifest['name'] ?? null) !== $name || ($manifest['version'] ?? null) !== $version || ! is_array($manifest['files'] ?? null)) {
                throw new RuntimeException((string) __('Das Archiv gehört nicht zu :name :version.', ['name' => $name, 'version' => $version]));
            }
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (str_ends_with($entry, '/') || in_array($entry, ['basalto-manifest.json', 'basalto-signature'], true)) {
                    continue;
                }
                $expected = $manifest['files'][$entry] ?? null;
                if (! is_string($expected) || ! hash_equals($expected, hash('sha256', (string) $zip->getFromIndex($i)))) {
                    throw new RuntimeException((string) __('Die Datei :file passt nicht zum Manifest.', ['file' => $entry]));
                }
                $seen[$entry] = true;
            }
            if (count($seen) !== count($manifest['files'])) {
                throw new RuntimeException((string) __('Im Archiv fehlen Dateien aus dem Manifest.'));
            }
        } finally {
            $zip->close();
        }
    }
}
