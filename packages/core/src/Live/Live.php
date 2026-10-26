<?php

declare(strict_types=1);

namespace Basalto\Core\Live;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Live-Aktualisierung (CR-008): Ändert sich etwas, setzt `signal()` für einen Schlüssel (z. B. `forum.thema.45`) eine
 * neue Version — ohne Inhalt. Seiten, die den Schlüssel beobachten, tragen ihn mit Unterschrift (`token()`, ausgestellt
 * beim Ausliefern, also nach der Rechteprüfung); der Browser fragt die Versionen über `/_live` ab oder bekommt das
 * Signal per Push und holt den Inhalt dann über die gewöhnliche Adresse. Persönliche Schlüssel (`konto.…`,
 * `benutzer.…`) erscheinen nur in persönlichen Seiten, die nie aus dem Seiten-Cache kommen.
 */
final class Live
{
    /** Erlaubte Schlüssel: Teile aus Kleinbuchstaben, Ziffern, «-» und «_», getrennt durch Punkte. */
    public const KEY = '/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+){0,4}$/';

    public function __construct(private readonly LivePush $push) {}

    /**
     * Etwas hat sich geändert: neue Version setzen und — wenn eingerichtet — ein Push-Signal senden. Erst nach dem
     * Abschluss einer laufenden Transaktion, sonst holte ein Browser den Inhalt, bevor er sichtbar ist.
     */
    public function signal(?int $siteId, string $key): void
    {
        self::assertKey($key);
        DB::afterCommit(function () use ($siteId, $key): void {
            $version = (int) floor(microtime(true) * 1000);
            Cache::forever(self::cacheKey((int) $siteId, $key), max($version, $this->version($siteId, $key) + 1));
            $this->push->send((int) $siteId, $key, $version);
        });
    }

    public function version(?int $siteId, string $key): int
    {
        return (int) Cache::get(self::cacheKey((int) $siteId, $key), 0);
    }

    /**
     * Versionen mehrerer Schlüssel einer Site — ein Lesezugriff auf den Cache.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function versions(int $siteId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $cacheKeys = [];
        foreach ($keys as $key) {
            $cacheKeys[self::cacheKey($siteId, $key)] = $key;
        }
        $out = [];
        foreach (Cache::many(array_keys($cacheKeys)) as $cacheKey => $version) {
            $out[$cacheKeys[$cacheKey]] = (int) $version;
        }

        return $out;
    }

    /** Unterschrift für einen Schlüssel einer Site — wer sie hat, darf die Versionen abfragen und den Kanal abonnieren. */
    public function token(?int $siteId, string $key): string
    {
        return substr(hash_hmac('sha256', 'live|'.(int) $siteId.'|'.$key, (string) config('app.key')), 0, 32);
    }

    public function valid(?int $siteId, string $key, string $token): bool
    {
        return preg_match(self::KEY, $key) === 1 && hash_equals($this->token($siteId, $key), $token);
    }

    /** Abfrage-Takt in Sekunden (`BASALTO_LIVE_INTERVAL`, mindestens 5). */
    public function interval(): int
    {
        return max(5, (int) config('basalto.live.interval', 15));
    }

    /**
     * Attribute für eine beobachtete Stelle im Markup — die Insel `live.ts` übernimmt sie. Aktionen: `hinweis` (Element
     * einblenden), `zaehler` (Zahl aus den Nutzdaten), `nachladen` (Fragment holen und auf Klick einfügen); weitere
     * Angaben werden zu `data-live-…`.
     *
     * @param  array<string, string|int>  $data
     */
    public function attributes(?int $siteId, string $key, string $action, array $data = []): string
    {
        self::assertKey($key);
        $attributes = ['live' => $key, 'live-token' => $this->token($siteId, $key), 'live-site' => (int) $siteId, 'live-version' => $this->version($siteId, $key),
            'live-aktion' => $action, 'live-takt' => $this->interval()];
        foreach ($data as $name => $value) {
            $attributes['live-'.$name] = $value;
        }
        $out = '';
        foreach ($attributes as $name => $value) {
            $out .= sprintf(' data-%s="%s"', $name, e((string) $value));
        }

        return $out;
    }

    public static function cacheKey(int $siteId, string $key): string
    {
        return 'basalto-live:'.$siteId.':'.$key;
    }

    private static function assertKey(string $key): void
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException('Ungültiger Live-Schlüssel «'.$key.'»');
        }
    }
}
