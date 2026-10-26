<?php

declare(strict_types=1);

namespace Basalto\Core\Media;

use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Value;
use Basalto\Lava\Theme\Theme;

/**
 * Die Bildvarianten einer Site aus `design { images { variant <name> { … } } }` des Themes (K-09, Mockup 27).
 * Ohne Angabe im Theme gelten die Vorgaben; «thumb» (Backend) und «content» (Standardgrösse) sind immer dabei.
 */
final class ImageVariants
{
    /** @var array<string, array{width: int, height: int|null, mode: string}> */
    public const DEFAULTS = [
        'thumb' => ['width' => 320, 'height' => null, 'mode' => ImageVariant::MODE_SCALE],
        'content' => ['width' => 1280, 'height' => null, 'mode' => ImageVariant::MODE_SCALE],
        'wide' => ['width' => 1920, 'height' => null, 'mode' => ImageVariant::MODE_SCALE],
    ];

    public function __construct(private readonly ThemeLoader $themes) {}

    /**
     * Varianten einer Site — bei einem kaputten Theme die Vorgaben, damit Uploads und Backend weiterlaufen.
     *
     * @return array<string, ImageVariant>
     */
    public function forSite(Site $site): array
    {
        try {
            return self::fromTheme($this->themes->forSite($site));
        } catch (ThemeException) {
            return self::defaults();
        }
    }

    /** @return list<string> */
    public function namesFor(Site $site): array
    {
        return array_keys($this->forSite($site));
    }

    /** @return array<string, ImageVariant> */
    public static function fromTheme(Theme $theme): array
    {
        $images = $theme->document->find('design')?->childrenOf('images')[0] ?? null;
        $defined = $images?->childrenOf('variant') ?? [];
        if ($defined === []) {
            return self::defaults();
        }
        $variants = [];
        foreach ($defined as $def) {
            $variant = self::fromDefinition($def);
            if ($variant !== null) {
                $variants[$variant->name] = $variant;
            }
        }
        foreach (['thumb', 'content'] as $required) {
            if (! isset($variants[$required])) {
                $variants[$required] = self::default($required);
            }
        }

        return $variants;
    }

    /** @return array<string, ImageVariant> */
    public static function defaults(): array
    {
        $variants = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            $variants[$name] = self::default($name);
        }

        return $variants;
    }

    private static function fromDefinition(Definition $def): ?ImageVariant
    {
        $name = (string) $def->name;
        $width = (int) ($def->value('width') ?? 0);
        if ($name === '' || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1 || $width < 16 || $width > 8000) {
            return null;
        }
        $height = $def->value('height') !== null ? (int) $def->value('height') : null;
        $mode = $def->value('mode') ?? ($height !== null ? ImageVariant::MODE_EXACT : ImageVariant::MODE_SCALE);
        if (! in_array($mode, [ImageVariant::MODE_SCALE, ImageVariant::MODE_FIT, ImageVariant::MODE_EXACT], true)) {
            $mode = ImageVariant::MODE_SCALE;
        }
        if ($mode !== ImageVariant::MODE_SCALE && ($height === null || $height < 16 || $height > 8000)) {
            $mode = ImageVariant::MODE_SCALE;
            $height = null;
        }
        $densities = [];
        $property = $def->property('densities');
        foreach ($property !== null ? $property->values : [] as $value) {
            if ($value->type === Value::NUMBER && (int) $value->value >= 1 && (int) $value->value <= 3) {
                $densities[] = (int) $value->value;
            }
        }
        $densities = array_values(array_unique($densities ?: [1]));
        sort($densities);
        $sizes = $def->value('sizes');

        return new ImageVariant($name, $width, $height, $mode, $densities, $sizes !== null && trim($sizes) !== '' ? trim($sizes) : null, $def->value('lazy') !== 'false');
    }

    private static function default(string $name): ImageVariant
    {
        $d = self::DEFAULTS[$name] ?? self::DEFAULTS['content'];

        return new ImageVariant($name, $d['width'], $d['height'], $d['mode'], [1], null, true);
    }
}
