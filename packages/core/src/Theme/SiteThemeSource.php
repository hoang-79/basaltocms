<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Models\Site;
use Basalto\Lava\Theme\SiteSourceResolver;
use Basalto\Lava\Theme\Source;
use Basalto\Lava\Theme\ThemeImporter;

/**
 * Die Quelle, die `basalto:check --site` prüft — dieselbe wie Backend und Auslieferung (L-05). Und das Ziel von
 * `basalto:lava-import --scope=theme`: die Theme-Bibliothek der Site (TB 22).
 */
final class SiteThemeSource implements SiteSourceResolver, ThemeImporter
{
    public function __construct(private readonly ThemeLoader $themes, private readonly ThemeLibrary $library) {}

    public function importTheme(Source $source, int $siteId): int
    {
        $n = $this->library->importSource(Site::query()->withoutGlobalScopes()->findOrFail($siteId), $source);
        $this->themes->forget();

        return $n;
    }

    public function forSite(string $handle): ?array
    {
        $site = Site::query()->withoutGlobalScopes()->where('handle', $handle)->first();
        if ($site === null) {
            return null;
        }
        $resolved = $this->themes->sourceFor($site);

        return ['source' => $resolved['source'], 'kind' => $resolved['kind']];
    }
}
