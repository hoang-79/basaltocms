<?php

declare(strict_types=1);

namespace Basalto\Core\Live;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Anwesenheit an einem Ort (TB 49): eine kurze Liste im Cache, wer gerade da ist — wer an einer Antwort tippt
 * (Community, `Typing`) oder wer eine Seite im Backend bearbeitet. Jeder Eintrag gilt `$ttl` Sekunden und wird mit
 * jeder Meldung verlängert; jede Meldung setzt ein Live-Signal, damit die anderen die neue Liste bekommen (die Liste
 * ist klein, die Signale gehen über Cache und Push).
 */
final class Presence
{
    public function __construct(private readonly Live $live) {}

    /** Da sein (verlängern) oder gehen. */
    public function update(int $siteId, string $key, int $id, string $name, bool $active, int $ttl): void
    {
        $cacheKey = self::cacheKey($siteId, $key);
        try {
            Cache::lock($cacheKey.'.sperre', 3)->block(2, function () use ($siteId, $key, $cacheKey, $id, $name, $active, $ttl): void {
                $list = self::fresh(Cache::get($cacheKey, []));
                $had = isset($list[$id]);
                if ($active) {
                    $list[$id] = ['name' => $name, 'bis' => time() + $ttl];
                } else {
                    unset($list[$id]);
                }
                $list === [] ? Cache::forget($cacheKey) : Cache::put($cacheKey, $list, $ttl * 3);
                if ($active || $had) {
                    $this->live->signal($siteId, $key);
                }
            });
        } catch (LockTimeoutException) {
            // Zwei Meldungen gleichzeitig — die nächste kommt bald
        }
    }

    /**
     * Wer da ist — mit den Sekunden, die die Meldung noch gilt.
     *
     * @return list<array{id: int, name: string, rest: int}>
     */
    public function current(int $siteId, string $key): array
    {
        $now = time();
        $out = [];
        foreach (self::fresh(Cache::get(self::cacheKey($siteId, $key), [])) as $id => $entry) {
            $out[] = ['id' => $id, 'name' => $entry['name'], 'rest' => max(1, $entry['bis'] - $now)];
        }

        return $out;
    }

    /**
     * @return array<int, array{name: string, bis: int}>
     */
    private static function fresh(mixed $list): array
    {
        $now = time();
        $out = [];
        foreach (is_array($list) ? $list : [] as $id => $entry) {
            if (is_array($entry) && is_int($entry['bis'] ?? null) && $entry['bis'] > $now && is_string($entry['name'] ?? null)) {
                $out[(int) $id] = ['name' => $entry['name'], 'bis' => $entry['bis']];
            }
        }

        return $out;
    }

    private static function cacheKey(int $siteId, string $key): string
    {
        return 'basalto.anwesend.'.$siteId.'.'.$key;
    }
}
