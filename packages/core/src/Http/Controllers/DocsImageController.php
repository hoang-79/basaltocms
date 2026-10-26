<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Help\HelpImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** TB 29: ein Bild der Doku (Screenshot, Schema) — öffentlich, einen Tag im Cache; SVG ohne Skripte. */
final class DocsImageController
{
    public function __invoke(string $path): BinaryFileResponse
    {
        $file = HelpImages::file($path);
        abort_if($file === null, 404);
        $type = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            default => 'image/webp',
        };

        return response()->file($file, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // Ein SVG ist ein Dokument: keine Skripte, keine fremden Quellen
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
        ]);
    }
}
