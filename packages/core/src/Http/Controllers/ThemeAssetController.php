<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeAssets;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert Dateien aus `assets/` eines Themes (L-10). Mit passender Prüfsumme ein Jahr unveränderlich im Cache; mit
 * veralteter Prüfsumme (eine Seite aus dem Cache nennt noch die alte Adresse) der aktuelle Stand, aber nur kurz gültig.
 */
final class ThemeAssetController
{
    public function __invoke(ThemeAssets $assets, int $site, string $hash, string $path): Response
    {
        $model = Site::query()->find($site);
        abort_if($model === null, 404);
        $content = $assets->read($model, $path);
        abort_if($content === null, 404);
        $current = $assets->hash($model, $path) === $hash;

        return response($content, 200, [
            'Content-Type' => ThemeAssets::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))],
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
