<?php

declare(strict_types=1);

namespace Basalto\Core\Transfer;

use ZipArchive;

/**
 * Liest ein ZIP aus `ArchiveWriter` und traut ihm erst nach der Prüfung (M-18, K-17): jeder Name ohne `..`, ohne
 * absoluten Pfad, ohne Laufwerk und ohne Backslash (ZIP-Slip); jede Datei steht im Manifest und umgekehrt; Grösse und
 * SHA-256 stimmen. Entpackt wird nie ins Dateisystem nach dem Namen im Archiv — Dateien gehen als Strom an ein Ziel,
 * das der Aufrufer bestimmt.
 */
final class ArchiveReader
{
    private readonly ZipArchive $zip;

    /** @var array<string, mixed> */
    private array $manifest = [];

    /** @var array<string, array{sha256: string, size: int}> */
    private array $files = [];

    public function __construct(private readonly string $path)
    {
        if (! is_file($path)) {
            throw new TransferException(sprintf('Archiv «%s» gibt es nicht.', $path));
        }
        $this->zip = new ZipArchive;
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new TransferException(sprintf('«%s» ist kein lesbares ZIP-Archiv.', basename($path)));
        }
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    /**
     * Ein Name im Archiv ist sicher, wenn er relativ ist, nur Vorwärtsschrägstriche, keine leeren, `.`- oder `..`-Teile,
     * kein Laufwerk und keine Steuerzeichen enthält.
     */
    public static function isSafeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 1024 || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1f]/', $name) === 1
            || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Namen, Manifest, Grössen und Prüfsummen prüfen — vor jedem Lesen. Gibt den Kopf des Manifests zurück.
     *
     * @return array<string, mixed>
     *
     * @throws TransferException beim ersten Befund
     */
    public function verify(): array
    {
        $names = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);
            if (! self::isSafeName($name)) {
                throw new TransferException(sprintf('Unsicherer Pfad im Archiv abgelehnt: «%s» (ZIP-Slip-Schutz).', $name));
            }
            $names[$name] = $i;
        }
        if (! isset($names[ArchiveWriter::MANIFEST])) {
            throw new TransferException('Im Archiv fehlt manifest.json — kein Archiv von Basalto oder unvollständig.');
        }
        $manifest = json_decode((string) $this->zip->getFromName(ArchiveWriter::MANIFEST), true);
        if (! is_array($manifest) || ! is_array($manifest['files'] ?? null)) {
            throw new TransferException('manifest.json ist kein gültiges Manifest.');
        }
        $files = [];
        foreach ($manifest['files'] as $name => $file) {
            $name = (string) $name;
            if (! self::isSafeName($name) || ! is_array($file) || ! is_string($file['sha256'] ?? null) || ! is_int($file['size'] ?? null)) {
                throw new TransferException(sprintf('Ungültiger Eintrag im Manifest: «%s».', $name));
            }
            if (! isset($names[$name])) {
                throw new TransferException(sprintf('Datei «%s» steht im Manifest, fehlt aber im Archiv.', $name));
            }
            $files[$name] = ['sha256' => strtolower($file['sha256']), 'size' => $file['size']];
        }
        foreach (array_keys($names) as $name) {
            if ($name !== ArchiveWriter::MANIFEST && ! isset($files[$name])) {
                throw new TransferException(sprintf('Datei «%s» liegt im Archiv, steht aber nicht im Manifest.', $name));
            }
        }
        foreach ($files as $name => $file) {
            $stat = $this->zip->statName($name);
            if ($stat === false || (int) $stat['size'] !== $file['size']) {
                throw new TransferException(sprintf('Grösse von «%s» stimmt nicht mit dem Manifest überein.', $name));
            }
            $stream = $this->zip->getStream($name);
            if ($stream === false) {
                throw new TransferException(sprintf('«%s» lässt sich nicht lesen.', $name));
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);
            if (! hash_equals($file['sha256'], hash_final($context))) {
                throw new TransferException(sprintf('Prüfsumme von «%s» stimmt nicht — die Datei wurde verändert oder ist beschädigt.', $name));
            }
        }
        unset($manifest['files']);
        $this->manifest = $manifest;
        $this->files = $files;

        return $manifest;
    }

    /** @return array<string, array{sha256: string, size: int}> geprüfte Dateien (erst nach `verify()`) */
    public function files(): array
    {
        return $this->files;
    }

    public function has(string $name): bool
    {
        return isset($this->files[$name]);
    }

    public function get(string $name): string
    {
        $this->assertVerified($name);

        return (string) $this->zip->getFromName($name);
    }

    /** @return resource */
    public function stream(string $name)
    {
        $this->assertVerified($name);
        $stream = $this->zip->getStream($name);
        if ($stream === false) {
            throw new TransferException(sprintf('«%s» lässt sich nicht lesen.', $name));
        }

        return $stream;
    }

    /** Eine Datei an einen vom Aufrufer gewählten Ort kopieren (nie nach dem Namen im Archiv). */
    public function extractTo(string $name, string $target): void
    {
        $stream = $this->stream($name);
        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new TransferException(sprintf('«%s» lässt sich nicht schreiben.', $target));
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    private function assertVerified(string $name): void
    {
        if (! isset($this->files[$name])) {
            throw new TransferException(sprintf('«%s» ist nicht im geprüften Archiv.', $name));
        }
    }
}
