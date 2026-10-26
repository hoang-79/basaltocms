<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Models\Media;
use Basalto\Core\Settings\Settings;
use Illuminate\Console\Command;

/** Papierkorb der Mediathek (K-08): Dateien, die länger als die Frist im Papierkorb liegen, endgültig löschen — Original und Varianten. */
final class PurgeMediaCommand extends Command
{
    protected $signature = 'basalto:media-purge {--days= : Frist in Tagen (Standard aus basalto.media.trash_days)}';

    protected $description = 'Löscht Medien endgültig, die länger als die Frist im Papierkorb liegen';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) app(Settings::class)->get('media.trash_days', (int) config('basalto.media.trash_days', 30));
        $limit = now()->subDays($days);
        $count = 0;
        Media::query()->withoutGlobalScopes()->onlyTrashed()->where('deleted_at', '<', $limit)->orderBy('id')->chunkById(100, function ($chunk) use (&$count): void {
            foreach ($chunk as $media) {
                self::purge($media);
                $count++;
            }
        });
        $this->info(sprintf('%d Medi%s endgültig gelöscht (Frist %d Tage).', $count, $count === 1 ? 'um' : 'en', $days));

        return self::SUCCESS;
    }

    /** Dateien und Datensatz entfernen — die Verwendungen fallen über die Fremdschlüssel weg. */
    public static function purge(Media $media): void
    {
        $storage = $media->storage();
        $storage->delete(array_values(array_unique([$media->path, ...$media->variantPaths()])));
        $media->forceDelete();
    }
}
