<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use RuntimeException;

/** Ein Theme-Verzeichnis im Repo: themes/<name>/theme.lava, blocks/, layouts/, parts/ … */
final class FileSource implements Source
{
    public function __construct(private readonly string $directory) {}

    public function name(): string
    {
        return basename($this->directory);
    }

    public function has(string $path): bool
    {
        return self::isInside($path) && is_file($this->directory.'/'.$path);
    }

    public function read(string $path): string
    {
        if (! self::isInside($path)) {
            throw new RuntimeException(sprintf('Theme-Pfad «%s» verlässt die Quelle', $path));
        }
        $file = $this->directory.'/'.$path;
        if (! is_file($file)) {
            throw new RuntimeException(sprintf('Theme-Datei fehlt: %s', $path));
        }

        return (string) file_get_contents($file);
    }

    public function paths(): array
    {
        $paths = [];
        if (! is_dir($this->directory)) {
            return $paths;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $paths[] = substr((string) $file, strlen($this->directory) + 1);
        }
        sort($paths);

        return $paths;
    }

    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->paths() as $path) {
            $file = $this->directory.'/'.$path;
            $parts[] = $path.':'.(int) filesize($file).':'.(int) filemtime($file);
        }

        return hash('xxh128', implode("\n", $parts));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Ein Pfad bleibt innerhalb der Quelle: relativ, ohne «..»-Glieder, ohne Nullbyte (L-16) —
     * «{% include "../../.env" %}» findet so nie eine Datei.
     */
    public static function isInside(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
