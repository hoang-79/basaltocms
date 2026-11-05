<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Models\Site;
use Basalto\Core\Seo\ShareImage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erzeugtes Vorschaubild einer Seite (CR-019, TB 106): `/_teilen/<site>/<prüfsumme>.png`, beim ersten Abruf gezeichnet,
 * danach ein Jahr unveränderlich im Cache — ein neuer Titel bekommt eine neue Prüfsumme.
 */
final class ShareImageController
{
    public function __invoke(ShareImage $images, int $site, string $file): Response
    {
        $model = Site::query()->find($site);
        abort_if($model === null, 404);
        $path = $images->file($model, substr($file, 0, -4));
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
