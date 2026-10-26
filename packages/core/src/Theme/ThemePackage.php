<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\Source;
use RuntimeException;
use ZipArchive;

/**
 * Das Paketformat eines Themes für Weitergabe und Marktplatz (L-15): ein ZIP mit allen Dateien des Theme-Ordners,
 * dazu `manifest.json` (Format, Name, Version aus `theme.json`, SHA-256 je Datei) und — mit Schlüssel — `signature`
 * (Ed25519 über das Manifest, Base64). Geprüft wird beim Prüfen jede Datei gegen das Manifest und das Manifest gegen
 * die Signatur; eine Datei, die nicht im Manifest steht, ist ein Fehler. Das Produkt-Schlüsselpaar folgt in TB 09.
 *
 * @phpstan-type Manifest array{format: int, name: string, version: string, files: array<string, string>}
 */
final class ThemePackage
{
    public const FORMAT = 1;

    /**
     * @return array{path: string, manifest: Manifest, signed: bool}
     */
    public function pack(string $name, string $outputDir, ?string $secretKey = null): array
    {
        $dir = ThemeCatalog::path().'/'.$name;
        if (! is_file($dir.'/theme.lava')) {
            throw new RuntimeException(__('Theme «:name» gibt es nicht unter :path.', ['name' => $name, 'path' => ThemeCatalog::path()]));
        }

        return $this->packSource(new FileSource($dir), $name, $outputDir, $secretKey);
    }

    /**
     * Ein Theme aus beliebiger Quelle packen — Dateien im Repo oder Datensätze eines Themes aus der Datenbank (L-17).
     *
     * @return array{path: string, manifest: Manifest, signed: bool}
     */
    public function packSource(Source $source, string $name, string $outputDir, ?string $secretKey = null): array
    {
        $meta = $source->has('theme.json') ? json_decode($source->read('theme.json'), true) : [];
        $version = is_array($meta) && is_string($meta['version'] ?? null) ? $meta['version'] : '0.0.0';
        $contents = [];
        foreach ($source->paths() as $path) {
            if (str_starts_with(basename($path), '.')) {
                continue;
            }
            $contents[$path] = $source->read($path);
        }
        $files = array_map(static fn (string $content): string => hash('sha256', $content), $contents);
        $manifest = ['format' => self::FORMAT, 'name' => $name, 'version' => $version, 'files' => $files];
        $json = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException(__('Ordner :path lässt sich nicht anlegen.', ['path' => $outputDir]));
        }
        $path = rtrim($outputDir, '/').'/'.$name.'-'.$version.'.zip';
        @unlink($path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('Paket :path lässt sich nicht schreiben.', ['path' => $path]));
        }
        foreach ($contents as $file => $content) {
            $zip->addFromString($name.'/'.$file, $content);
        }
        $zip->addFromString('manifest.json', $json);
        $signed = false;
        if ($secretKey !== null) {
            $zip->addFromString('signature', base64_encode(sodium_crypto_sign_detached($json, self::key($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES))));
            $signed = true;
        }
        $zip->close();

        return ['path' => $path, 'manifest' => $manifest, 'signed' => $signed];
    }

    /**
     * Die Dateien eines geprüften Pakets (ohne Manifest und Signatur) — für den Import in die Datenbank.
     *
     * @return array<string, string>
     */
    public function files(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('Kein lesbares ZIP.'));
        }
        $json = $zip->getFromName('manifest.json');
        $manifest = is_string($json) ? json_decode($json, true) : null;
        $name = is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : '';
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if ($name !== '' && str_starts_with($entry, $name.'/') && ! str_ends_with($entry, '/')) {
                $relative = substr($entry, strlen($name) + 1);
                if (FileSource::isInside($relative)) {
                    $out[$relative] = (string) $zip->getFromIndex($i);
                }
            }
        }
        $zip->close();

        return $out;
    }

    /**
     * @return array{ok: bool, errors: list<string>, manifest: Manifest|null, signed: bool}
     */
    public function verify(string $path, ?string $publicKey = null): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return ['ok' => false, 'errors' => [__('Kein lesbares ZIP.')], 'manifest' => null, 'signed' => false];
        }
        $json = $zip->getFromName('manifest.json');
        $manifest = is_string($json) ? json_decode($json, true) : null;
        if (! is_string($json) || ! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT || ! is_string($manifest['name'] ?? null) || ! is_array($manifest['files'] ?? null)) {
            $zip->close();

            return ['ok' => false, 'errors' => [__('manifest.json fehlt oder hat ein unbekanntes Format.')], 'manifest' => null, 'signed' => false];
        }
        /** @var Manifest $manifest */
        $errors = [];
        $name = $manifest['name'];
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if ($entry === 'manifest.json' || $entry === 'signature' || str_ends_with($entry, '/')) {
                continue;
            }
            $relative = str_starts_with($entry, $name.'/') ? substr($entry, strlen($name) + 1) : null;
            if ($relative === null || ! FileSource::isInside($relative) || ! isset($manifest['files'][$relative])) {
                $errors[] = __('Datei «:file» steht nicht im Manifest.', ['file' => $entry]);

                continue;
            }
            $seen[$relative] = true;
            if (! hash_equals($manifest['files'][$relative], hash('sha256', (string) $zip->getFromIndex($i)))) {
                $errors[] = __('Datei «:file» wurde verändert (Prüfsumme stimmt nicht).', ['file' => $relative]);
            }
        }
        foreach (array_keys($manifest['files']) as $file) {
            if (! isset($seen[$file])) {
                $errors[] = __('Datei «:file» fehlt im Paket.', ['file' => $file]);
            }
        }
        if (! isset($manifest['files']['theme.lava'])) {
            $errors[] = 'theme.lava fehlt im Paket.';
        }
        $signature = $zip->getFromName('signature');
        $zip->close();
        $signed = is_string($signature);
        if ($publicKey !== null) {
            $raw = is_string($signature) ? base64_decode($signature, true) : false;
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($raw, $json, self::key($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))) {
                $errors[] = $signed ? __('Die Signatur passt nicht zum Manifest oder zum Schlüssel.') : __('Das Paket ist nicht signiert.');
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'manifest' => $manifest, 'signed' => $signed];
    }

    /**
     * Schlüssel als Base64 oder als Pfad zu einer Datei mit Base64.
     *
     * @return non-empty-string
     */
    private static function key(string $value, int $bytes): string
    {
        $text = is_file($value) ? trim((string) file_get_contents($value)) : trim($value);
        $raw = base64_decode($text, true);
        if ($raw === false || $raw === '' || strlen($raw) !== $bytes) {
            throw new RuntimeException(__('Schlüssel ungültig — erwartet werden :bytes Bytes als Base64.', ['bytes' => $bytes]));
        }

        return $raw;
    }
}
