<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Lava\Theme\Source;
use RuntimeException;

/** Lava-Quellen im Speicher — für Probe-Kompilierungen, bevor etwas in die Datenbank geschrieben wird. */
final class MemorySource implements Source
{
    /** @param  array<string, string>  $files  Pfad → Inhalt */
    public function __construct(private readonly array $files, private readonly string $name = 'memory') {}

    public function name(): string
    {
        return $this->name;
    }

    public function has(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function read(string $path): string
    {
        return $this->files[$path] ?? throw new RuntimeException(sprintf('Quelle fehlt im Speicher: %s', $path));
    }

    public function paths(): array
    {
        $paths = array_keys($this->files);
        sort($paths);

        return $paths;
    }

    public function fingerprint(): string
    {
        return hash('xxh128', serialize($this->files));
    }
}
