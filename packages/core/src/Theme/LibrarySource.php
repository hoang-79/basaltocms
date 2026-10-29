<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Lava\Theme\Source;

/**
 * Ein Theme aus der Bibliothek der Site (TB 22): Text liegt in der Datenbank, Bilder und Schriften in der Ablage
 * (AssetStore). Für den Compiler gehören beide zum Theme — sonst meldete Station B die Schrift `src assets/fonts/…`
 * eines installierten Marktplatz-Themes als fehlend (TB 82). Gelesen und aufgelistet wird weiter nur der Text.
 */
final class LibrarySource implements Source
{
    public function __construct(private readonly Source $text, private readonly AssetStore $assets) {}

    public function text(): Source
    {
        return $this->text;
    }

    public function name(): string
    {
        return $this->text->name();
    }

    public function has(string $path): bool
    {
        return $this->text->has($path) || $this->assets->has($path);
    }

    public function read(string $path): string
    {
        return $this->text->has($path) || ! $this->assets->has($path) ? $this->text->read($path) : $this->assets->read($path);
    }

    public function paths(): array
    {
        return $this->text->paths();
    }

    public function fingerprint(): string
    {
        return hash('xxh128', $this->text->fingerprint().'|'.$this->assets->fingerprint());
    }
}
