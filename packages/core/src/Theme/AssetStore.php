<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Models\SiteTheme;
use Basalto\Lava\Theme\Source;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Bilder und Schriften eines eigenen Themes (TB 22) auf der Standard-Disk — welche es gibt und ihre Prüfsummen stehen
 * im Theme selbst (`site_themes.assets`), damit Seiten Adressen bilden, ohne die Dateien zu lesen.
 */
final class AssetStore implements Source
{
    public function __construct(private readonly SiteTheme $theme) {}

    public function name(): string
    {
        return $this->theme->name.'/assets';
    }

    public function has(string $path): bool
    {
        return isset(($this->theme->assets ?? [])[$path]);
    }

    public function read(string $path): string
    {
        if (! $this->has($path)) {
            throw new RuntimeException(sprintf('Theme-Datei fehlt: %s (%s)', $path, $this->theme->name));
        }

        return (string) Storage::disk()->get($this->theme->assetDirectory().'/'.$path);
    }

    public function paths(): array
    {
        $paths = array_keys($this->theme->assets ?? []);
        sort($paths);

        return $paths;
    }

    public function fingerprint(): string
    {
        return hash('xxh128', (string) json_encode($this->theme->assets ?? []));
    }

    /** Prüfsumme, unter der die Datei ausgeliefert wird — aus dem Verzeichnis, ohne Lesen. */
    public function hash(string $path): ?string
    {
        return ($this->theme->assets ?? [])[$path]['hash'] ?? null;
    }
}
