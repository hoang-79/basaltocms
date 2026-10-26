<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use Basalto\Core\Models\NotFoundHit;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Das 404-Protokoll (M-10): jeder Pfad je Site einmal mit Zähler. Damit Scanner die Tabelle nicht fluten, nimmt sie je
 * Site höchstens {@see self::LIMIT} Pfade auf — bekannte Pfade zählen weiter. Alte Zeilen räumt der Löschlauf.
 */
final class NotFoundLog
{
    public const LIMIT = 5000;

    public function record(Site $site, string $locale, string $path, ?string $referrer): void
    {
        $path = Str::limit($path, 250, '');
        $query = NotFoundHit::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
        $hit = (clone $query)->where('path', $path)->first();
        if ($hit === null) {
            if ((clone $query)->count() >= self::LIMIT) {
                return;
            }
            try {
                NotFoundHit::query()->create(['site_id' => $site->id, 'path' => $path, 'locale' => $locale, 'hits' => 1,
                    'referrer' => self::referrer($referrer), 'first_seen_at' => now(), 'last_seen_at' => now()]);

                return;
            } catch (UniqueConstraintViolationException) {
                $hit = (clone $query)->where('path', $path)->first();
                if ($hit === null) {
                    return;
                }
            }
        }
        $hit->forceFill(['hits' => $hit->hits + 1, 'last_seen_at' => now(), 'referrer' => self::referrer($referrer) ?? $hit->referrer])->save();
    }

    private static function referrer(?string $referrer): ?string
    {
        return $referrer === null || $referrer === '' ? null : Str::limit($referrer, 490, '');
    }
}
