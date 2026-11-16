<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\SiteIcon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website-Symbol (Favicon): die erzeugten Grössen einer Site unter `/_symbol/<site>/<prüfsumme>/<datei>` (ein Jahr
 * unveränderlich — eine neue Quelle bekommt eine neue Prüfsumme), `/favicon.ico` je Host (ihr Symbol, sonst das von
 * Basalto; einen Tag im Cache) und die Symbole von Basalto für das Backend unter `/_symbol/basalto/<datei>`. Ohne
 * Sitzung und Cookies wie die Dateien eines Themes.
 */
final class SiteIconController
{
    private const HEADERS = ['X-Content-Type-Options' => 'nosniff'];

    public function show(SiteIcon $icons, int $site, string $hash, string $file): Response
    {
        $model = Site::query()->find($site);
        abort_if($model === null, 404);
        $found = $icons->file($model, $hash, $file);
        abort_if($found === null, 404);

        return response()->file($found['path'], ['Content-Type' => $found['mime'], 'Cache-Control' => 'public, max-age=31536000, immutable'] + self::HEADERS);
    }

    public function favicon(SiteIcon $icons, CurrentSite $current): Response
    {
        $found = $icons->favicon($current->get());

        return response()->file($found['path'], ['Content-Type' => $found['mime'], 'Cache-Control' => 'public, max-age=86400'] + self::HEADERS);
    }

    public function basalto(string $file): Response
    {
        $mime = SiteIcon::BASALTO[$file] ?? null;
        abort_if($mime === null, 404);

        return response()->file(SiteIcon::basaltoPath($file), ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=604800'] + self::HEADERS);
    }
}
