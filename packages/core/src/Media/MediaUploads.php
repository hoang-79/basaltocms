<?php

declare(strict_types=1);

namespace Basalto\Core\Media;

use Basalto\Core\Demo\DemoQuota;
use Basalto\Core\Events\MediaUploaded;
use Basalto\Core\Jobs\GenerateMediaVariants;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Der eine Weg in die Mediathek (K-08) — für Backend, Einfügen im Editor und REST-API (M-17): Abweisungsgründe über
 * Typ und Grösse hinaus (Kontingent, Skripte in SVG), Ablage auf der Standard-Disk unter `sites/<id>/media/JJJJ/MM`,
 * Datensatz, Ereignis (Protokoll), Varianten in die Warteschlange.
 */
final class MediaUploads
{
    /** Ablage: Datei auf die Disk, Datensatz, Ereignis, Varianten in die Warteschlange. */
    public function store(UploadedFile $file, Site $site, ?int $folderId, mixed $userId): Media
    {
        $size = @getimagesize($file->getRealPath());
        $media = Media::query()->create([
            'site_id' => $site->id, 'folder_id' => $folderId, 'disk' => (string) config('filesystems.default'), 'path' => $this->put($file, $site),
            'name' => $file->getClientOriginalName(), 'mime' => (string) $file->getMimeType(), 'size' => $file->getSize(),
            'width' => is_array($size) ? (int) $size[0] : null, 'height' => is_array($size) ? (int) $size[1] : null,
            'alt' => [], 'created_by' => is_numeric($userId) ? (int) $userId : null,
        ]);
        event(new MediaUploaded($media));
        if ($media->isRasterImage()) {
            GenerateMediaVariants::dispatch($media->id);
        }

        return $media;
    }

    public function put(UploadedFile $file, Site $site): string
    {
        $path = Storage::disk()->putFile(sprintf('sites/%d/media/%s', $site->id, now()->format('Y/m')), $file);
        if ($path === false) {
            abort(500, __('Datei konnte nicht gespeichert werden'));
        }

        return $path;
    }

    /** Gründe, eine Datei trotz gültigem Typ abzuweisen: Kontingent der Demo und der Site, Skripte in SVG. */
    public function refuse(UploadedFile $file, Site $site, int $replacing = 0): ?string
    {
        // TB 66 Nachtrag: auch, was ohne Datei in der Anfrage ankommt (Bild aus der Zwischenablage, Abruf einer Adresse)
        if (($demo = DemoQuota::refuse((int) $file->getSize() - $replacing)) !== null) {
            return $demo;
        }
        $quotaMb = config('basalto.media.quota_megabytes');
        if (is_int($quotaMb) && $quotaMb > 0) {
            $used = (int) Media::query()->withoutGlobalScopes()->withTrashed()->where('site_id', $site->id)->sum('size') - $replacing;
            if ($used + (int) $file->getSize() > $quotaMb * 1024 * 1024) {
                return __('Kontingent von :mb MB erreicht — Upload gesperrt, bis Platz frei wird.', ['mb' => $quotaMb]);
            }
        }
        if ($file->getMimeType() === 'image/svg+xml') {
            $svg = (string) file_get_contents((string) $file->getRealPath());
            if (preg_match('/<script|\son[a-z]+\s*=|javascript:/i', $svg) === 1) {
                return __('SVG mit Skript oder Ereignis-Attributen wird nicht angenommen.');
            }
        }

        return null;
    }
}
