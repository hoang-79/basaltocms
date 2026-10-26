<?php

declare(strict_types=1);

namespace Basalto\Core\Transfer;

use Illuminate\Contracts\Filesystem\Filesystem;
use ZipArchive;

/**
 * Schreibt ein ZIP mit Manifest (M-18, K-17): Jede Datei steht mit SHA-256 und Grösse in `manifest.json`, damit Import
 * und Wiederherstellungsprüfung Veränderung und Abbruch erkennen. Geschrieben wird in eine Datei neben dem Ziel und
 * erst am Schluss umbenannt — ein halbes Archiv liegt nie unter dem Zielnamen. Bilder und andere schon gepackte Dateien
 * werden nur abgelegt (STORE), Text wird gepackt.
 */
final class ArchiveWriter
{
    public const MANIFEST = 'manifest.json';

    private readonly ZipArchive $zip;

    private readonly string $partial;

    /** @var array<string, array{sha256: string, size: int}> */
    private array $files = [];

    /** @var list<string> Zwischendateien von entfernten Disks — nach dem Schliessen weg */
    private array $temporary = [];

    private bool $closed = false;

    public function __construct(private readonly string $path)
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new TransferException(__('Ordner «:path» lässt sich nicht anlegen.', ['path' => $directory]));
        }
        $this->partial = $path.'.part';
        @unlink($this->partial);
        $this->zip = new ZipArchive;
        if ($this->zip->open($this->partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new TransferException(__('Archiv «:path» lässt sich nicht schreiben.', ['path' => $path]));
        }
    }

    public function addString(string $name, string $contents): void
    {
        $this->guard($name);
        $this->zip->addFromString($name, $contents);
        $this->files[$name] = ['sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
    }

    /** Eine Datei der lokalen Platte (Datenbankabzug) — gelesen wird erst beim Schliessen. */
    public function addLocalFile(string $name, string $absolutePath, bool $compress = true): void
    {
        $this->guard($name);
        $hash = hash_file('sha256', $absolutePath);
        if ($hash === false) {
            throw new TransferException(__('Datei «:path» lässt sich nicht lesen.', ['path' => $absolutePath]));
        }
        $this->zip->addFile($absolutePath, $name);
        if (! $compress) {
            $this->zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $this->files[$name] = ['sha256' => $hash, 'size' => (int) filesize($absolutePath)];
    }

    /**
     * Eine Datei einer Disk: lokal direkt vom Pfad, entfernt (S3, R2) über eine Zwischendatei. Fehlt sie, kommt false
     * zurück — der Aufrufer entscheidet, ob das ein Befund ist.
     */
    public function addDiskFile(string $name, Filesystem $disk, string $path): bool
    {
        if (! $disk->exists($path)) {
            return false;
        }
        // Lokale Platte: direkt vom Pfad; bei S3/R2 ist path() nur der Schlüssel — dann über eine Zwischendatei
        $local = $disk->path($path);
        if (! is_file($local)) {
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }
            $local = (string) tempnam(sys_get_temp_dir(), 'basalto-zip-');
            $target = fopen($local, 'wb');
            if ($target === false) {
                throw new TransferException(__('Zwischendatei lässt sich nicht schreiben.'));
            }
            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);
            $this->temporary[] = $local;
        }
        $this->addLocalFile($name, $local, self::compressible($path));

        return true;
    }

    public function has(string $name): bool
    {
        return isset($this->files[$name]);
    }

    /**
     * Manifest schreiben, Archiv schliessen und unter den Zielnamen legen.
     *
     * @param  array<string, mixed>  $meta  Kopf des Manifests (Format, Version, Erzeugt am …)
     * @return array{path: string, size: int, files: int}
     */
    public function close(array $meta): array
    {
        $manifest = [...$meta, 'files' => $this->files];
        $this->zip->addFromString(self::MANIFEST, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $ok = $this->zip->close();
        $this->closed = true;
        foreach ($this->temporary as $file) {
            @unlink($file);
        }
        if (! $ok || ! @rename($this->partial, $this->path)) {
            @unlink($this->partial);
            throw new TransferException(__('Archiv «:path» liess sich nicht abschliessen.', ['path' => $this->path]));
        }

        return ['path' => $this->path, 'size' => (int) filesize($this->path), 'files' => count($this->files)];
    }

    /** Abbrechen: halbe Datei und Zwischendateien weg. */
    public function discard(): void
    {
        if (! $this->closed) {
            $this->zip->unchangeAll();
            @$this->zip->close();
            $this->closed = true;
        }
        @unlink($this->partial);
        foreach ($this->temporary as $file) {
            @unlink($file);
        }
    }

    private function guard(string $name): void
    {
        if (! ArchiveReader::isSafeName($name) || $name === self::MANIFEST) {
            throw new TransferException(__('Unzulässiger Name im Archiv: «:name».', ['name' => $name]));
        }
        if (isset($this->files[$name])) {
            throw new TransferException(__('«:name» steht zweimal im Archiv.', ['name' => $name]));
        }
    }

    /** Bilder, Videos und Archive sind schon gepackt — nochmals packen kostet nur Zeit. */
    private static function compressible(string $path): bool
    {
        return ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'webm', 'mp3', 'zip', 'gz', 'pdf', 'woff', 'woff2'], true);
    }
}
