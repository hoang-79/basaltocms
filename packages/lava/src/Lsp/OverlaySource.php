<?php

declare(strict_types=1);

namespace Basalto\Lava\Lsp;

use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\Source;
use RuntimeException;

/**
 * Eine Theme-Quelle mit ungespeicherten Puffern davor: Was der Editor offen hat, gilt vor der Datei auf der Platte
 * (oder dem Datensatz in der Datenbank). So prüft der Sprachdienst, was gerade im Editor steht — mit demselben Compiler.
 */
final class OverlaySource implements Source
{
    /**
     * @param  Source|null  $base  Dateien oder Datenbank darunter; ohne Grundlage zählen nur die Puffer
     * @param  array<string, string>  $buffers  relativer Pfad → Inhalt des Editors
     */
    public function __construct(private readonly ?Source $base, private readonly array $buffers, private readonly string $name = 'editor') {}

    public function name(): string
    {
        return $this->base?->name() ?? $this->name;
    }

    public function has(string $path): bool
    {
        if (! FileSource::isInside($path)) {
            return false;
        }

        return isset($this->buffers[$path]) || ($this->base?->has($path) ?? false);
    }

    public function read(string $path): string
    {
        if (! FileSource::isInside($path)) {
            throw new RuntimeException(sprintf('Theme-Pfad «%s» verlässt die Quelle', $path));
        }
        if (isset($this->buffers[$path])) {
            return $this->buffers[$path];
        }
        if ($this->base === null) {
            throw new RuntimeException(sprintf('Theme-Datei fehlt: %s', $path));
        }

        return $this->base->read($path);
    }

    public function paths(): array
    {
        $paths = array_values(array_unique(array_merge($this->base?->paths() ?? [], array_keys($this->buffers))));
        sort($paths);

        return $paths;
    }

    public function fingerprint(): string
    {
        return hash('xxh128', ($this->base?->fingerprint() ?? '').'|'.serialize($this->buffers));
    }
}
