<?php

declare(strict_types=1);

namespace Basalto\Core\Media;

/**
 * Eine Bildvariante aus dem Theme (K-09, Station B): Name, Zielmasse, Modus, Dichten, `sizes` und Lazy-Laden.
 *
 * Modi: «scale» skaliert proportional auf die Breite, «fit» passt das Bild in Breite × Höhe ein (ohne Zuschnitt),
 * «exact» liefert genau Breite × Höhe — der Zuschnitt folgt dem Fokuspunkt. Nichts wird vergrössert.
 */
final class ImageVariant
{
    public const MODE_SCALE = 'scale';

    public const MODE_FIT = 'fit';

    public const MODE_EXACT = 'exact';

    /** @param  list<int>  $densities */
    public function __construct(
        public readonly string $name,
        public readonly int $width,
        public readonly ?int $height,
        public readonly string $mode,
        public readonly array $densities,
        public readonly ?string $sizes,
        public readonly bool $lazy,
    ) {}

    /** @return array{name: string, width: int, height: int|null, mode: string, densities: list<int>, sizes: string|null, lazy: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'width' => $this->width, 'height' => $this->height, 'mode' => $this->mode, 'densities' => $this->densities, 'sizes' => $this->sizes, 'lazy' => $this->lazy];
    }

    /** Die Beschreibung für die Oberfläche: «1920 × 1080 · exakt · 1x, 2x». */
    public function describe(): string
    {
        $mode = match ($this->mode) {
            self::MODE_EXACT => __('exakt'),
            self::MODE_FIT => __('Rahmen'),
            default => __('proportional'),
        };
        $size = $this->height !== null ? sprintf('%d × %d', $this->width, $this->height) : __(':width breit', ['width' => $this->width]);

        return sprintf('%s · %s · %s', $size, $mode, implode(', ', array_map(static fn (int $d): string => $d.'x', $this->densities)));
    }
}
