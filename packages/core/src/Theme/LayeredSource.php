<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Lava\Theme\Source;

/**
 * Theme plus Ergänzungen der Site (M-02): Die Quelle des Themes (Dateien oder Datenbank) bleibt, wie sie ist; die
 * Lava-Quellen der Site (`lava_sources` mit `scope = site`, z. B. `types.lava` mit eigenen Inhaltstypen) erscheinen
 * unter `site/…` und werden am Ende von `theme.lava` eingebunden — derselbe Compiler, ein Dokument, eine Wahrheit.
 */
final class LayeredSource implements Source
{
    public const PREFIX = 'site/';

    public function __construct(private readonly Source $theme, private readonly Source $site) {}

    public function theme(): Source
    {
        return $this->theme;
    }

    public function site(): Source
    {
        return $this->site;
    }

    public function name(): string
    {
        return $this->theme->name();
    }

    public function has(string $path): bool
    {
        return str_starts_with($path, self::PREFIX) ? $this->site->has(substr($path, strlen(self::PREFIX))) : $this->theme->has($path);
    }

    public function read(string $path): string
    {
        if (str_starts_with($path, self::PREFIX)) {
            return $this->site->read(substr($path, strlen(self::PREFIX)));
        }
        $text = $this->theme->read($path);
        if ($path === 'theme.lava') {
            foreach ($this->site->paths() as $sitePath) {
                if (str_ends_with($sitePath, '.lava')) {
                    $text .= "\ninclude \"".self::PREFIX.$sitePath.'"';
                }
            }
        }

        return $text;
    }

    public function paths(): array
    {
        $paths = $this->theme->paths();
        foreach ($this->site->paths() as $sitePath) {
            $paths[] = self::PREFIX.$sitePath;
        }

        return $paths;
    }

    public function fingerprint(): string
    {
        return hash('xxh128', $this->theme->fingerprint().'|'.$this->site->fingerprint());
    }
}
