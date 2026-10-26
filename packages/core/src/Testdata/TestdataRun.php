<?php

declare(strict_types=1);

namespace Basalto\Core\Testdata;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Ein Lauf der Testdaten (`basalto:testdaten`): Site, Umfang, ein Zufall mit festem Startwert (gleicher Lauf → gleiche
 * Daten, auch in der Demo) und die Kundenkonten, die der Kern anlegt und die Module weiterverwenden — nach Rolle
 * gruppiert («aktiv», «moderation», «team», «gesperrt» …). Alles, was entsteht, trägt die Domain {@see self::DOMAIN};
 * daran erkennt `--entfernen` die Testdaten wieder.
 */
final class TestdataRun
{
    /** Reservierte Domain (RFC 2606): Mails dorthin gehen nirgends hin, und sie markiert die Testdaten. */
    public const DOMAIN = 'testdaten.example';

    /** Umfang → Faktor auf die Grundmengen. */
    public const SCALES = ['klein' => 1, 'mittel' => 4, 'gross' => 20];

    private readonly Randomizer $random;

    /** @var array<string, list<Customer>> */
    private array $pools = [];

    /** @var array<string, int> */
    private array $counts = [];

    private readonly CarbonImmutable $now;

    public function __construct(public readonly Site $site, public readonly int $scale = 1, int $seed = 2026)
    {
        $this->random = new Randomizer(new Mt19937($seed + $site->id));
        $this->now = CarbonImmutable::now();
    }

    /** Grundmenge mal Umfang, mindestens eins. */
    public function times(int $base): int
    {
        return max(1, $base * $this->scale);
    }

    public function int(int $min, int $max): int
    {
        return $max <= $min ? $min : $this->random->getInt($min, $max);
    }

    /** Mit dieser Wahrscheinlichkeit (Prozent) wahr. */
    public function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        $values = array_values($items);

        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    /**
     * Bis zu `$n` verschiedene Einträge.
     *
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return list<T>
     */
    public function picks(array $items, int $n): array
    {
        $values = array_values($items);
        if ($n <= 0 || $values === []) {
            return [];
        }

        return array_slice($this->random->shuffleArray($values), 0, min($n, count($values)));
    }

    /**
     * Gewichtete Wahl: Schlüssel → Gewicht (Zahlen als Schlüssel macht PHP zu int; zurück kommt der Schlüssel als Text).
     *
     * @param  array<int|string, int>  $weights
     */
    public function weighted(array $weights): string
    {
        $roll = $this->random->getInt(1, max(1, array_sum($weights)));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_first($weights);
    }

    /** Ein Zeitpunkt zwischen `$maxDays` und `$minDays` Tagen vor jetzt (zufällige Uhrzeit). */
    public function ago(int $maxDays, int $minDays = 0): CarbonImmutable
    {
        $seconds = $this->random->getInt($minDays * 86400, max($minDays * 86400, $maxDays * 86400));

        return $this->awake($this->now->subSeconds($seconds));
    }

    /**
     * Keine Zeiten zwischen 00:00 und 04:59 UTC: In dieser Spanne liegen die Zeitumstellungen in Europa, und eine
     * MariaDB in der Zeitzone des Servers weist Zeitpunkte in der Lücke (etwa 02:30 am letzten Sonntag im März) ab.
     */
    private function awake(CarbonImmutable $time): CarbonImmutable
    {
        if ($time->hour >= 5) {
            return $time;
        }
        $later = $time->addHours(6);

        return $later->greaterThan($this->now) ? $time->subHours(6) : $later;
    }

    /** Ein Zeitpunkt nach `$after`, höchstens `$maxHours` später und nie in der Zukunft. */
    public function after(CarbonImmutable $after, int $maxHours = 72): CarbonImmutable
    {
        $next = $this->awake($after->addSeconds($this->random->getInt(60, max(60, $maxHours * 3600))));

        return $next->greaterThan($this->now) ? $this->now->subSeconds($this->random->getInt(30, 600)) : $next;
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    /** Eine stabile UUID aus einem Namen — damit die Demo nach jedem Neuaufsetzen dieselbe Prüfsumme hat. */
    public function uuid(string $name): string
    {
        $hash = md5('basalto-testdaten:'.$this->site->handle.':'.$name);

        return sprintf('%s-%s-4%s-%s%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3),
            dechex(8 | (hexdec($hash[16]) & 3)), substr($hash, 17, 3), substr($hash, 20, 12));
    }

    public static function email(string $local): string
    {
        return mb_strtolower($local).'@'.self::DOMAIN;
    }

    public static function isTestEmail(?string $email): bool
    {
        return is_string($email) && str_ends_with(mb_strtolower($email), '@'.self::DOMAIN);
    }

    /** @param  list<Customer>  $customers */
    public function remember(string $pool, array $customers): void
    {
        $this->pools[$pool] = [...($this->pools[$pool] ?? []), ...$customers];
    }

    /** @return list<Customer> */
    public function pool(string $pool): array
    {
        return $this->pools[$pool] ?? [];
    }

    public function count(string $what, int $n = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $n;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }
}
