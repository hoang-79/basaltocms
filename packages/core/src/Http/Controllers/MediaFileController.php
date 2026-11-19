<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Models\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert eine Datei der Mediathek für öffentliche Seiten, Mails und Vorschaubilder aus (19.11.2026) — unter einer
 * dauerhaft signierten Adresse (`Media::publicUrl()`), ohne Sitzung und Cookies wie die Dateien eines Themes.
 *
 * Vorher standen in Seiten befristete Adressen (30 Minuten): Der Seiten-Cache hält eine Seite aber einen Tag, ein
 * Fliesstext speichert die Adresse beim Einfügen für immer, Netzwerke und Mailprogramme laden Bilder noch Tage später —
 * abgemeldet fehlten Bilder aus der Mediathek (Befund Hoang, Porträt auf nguyen-development.ch). Die Signatur hält die
 * Adresse geheim wie bisher: Ohne sie lässt sich keine Datei über ihre Nummer abrufen.
 *
 * Lokaler Speicher: die Datei selbst, einen Tag im Cache (ersetzt man eine Datei, bleibt die Adresse gleich). Ein
 * Objektspeicher (Bucket), der befristete Adressen kann: Weiterleitung auf eine frische, kurz gültige Adresse.
 */
final class MediaFileController
{
    public function __invoke(Request $request, int $media, ?string $variant = null): Response|RedirectResponse
    {
        $record = Media::query()->withoutGlobalScopes()->find($media);
        abort_if($record === null, 404);
        $format = $request->query('format') === 'avif' ? 'avif' : 'webp';
        $density = max(1, (int) $request->query('density', 1));
        $path = $variant !== null ? ($record->variantPath($variant, $format, $density) ?? $record->path) : $record->path;
        $storage = $record->storage();
        abort_unless($storage->exists($path), 404);

        if (config('filesystems.disks.'.$record->disk.'.driver') !== 'local' && method_exists($storage, 'providesTemporaryUrls') && $storage->providesTemporaryUrls()) {
            return redirect()->away($storage->temporaryUrl($path, now()->addMinutes(10)))->setPrivate()->setMaxAge(300);
        }

        return $storage->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
