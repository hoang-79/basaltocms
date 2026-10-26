<?php

declare(strict_types=1);

namespace Basalto\Core\Testdata;

use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * TB 54: Seitenaufrufe für die Statistik als Testdaten — 45 Tage mit Sitzungen, Verweisen, Kampagnen, Geräten und
 * einem Anteil ohne Einwilligung (anonym) und Bots. Eigener Zufallsstrom mit festem Startwert, damit die übrigen
 * Testdaten (Community, Forum) gleich bleiben; markiert mit `is_synthetic`, so lassen sie sich wieder entfernen.
 */
final class StatisticsTestdata
{
    private Randomizer $random;

    /** @var list<string> Besucher, die schon da waren */
    private array $known = [];

    public function __construct(private readonly Pages $pages) {}

    public function seed(Site $site, int $scale = 1): int
    {
        $this->random = new Randomizer(new Mt19937(4711));
        $this->known = [];
        $paths = $this->paths($site);
        if ($paths === []) {
            return 0;
        }
        $locales = $site->localeCodes();
        $zone = $site->timezone ?: (string) config('app.timezone');
        $today = CarbonImmutable::now($zone)->startOfDay();
        $rows = [];
        for ($day = 44; $day >= 0; $day--) {
            $date = $today->subDays($day);
            // Wochentage stärker, ein langsamer Anstieg über den Monat
            $sessions = (int) round(($date->isWeekend() ? 18 : 32) * (1 + (44 - $day) / 90) * $scale);
            for ($s = 0; $s < $sessions; $s++) {
                $rows = [...$rows, ...$this->session($site, $date, $paths, $locales)];
            }
            for ($b = 0; $b < $this->int(2, 6); $b++) {
                $rows[] = $this->row($site, $date->addMinutes($this->int(0, 1439))->utc(), $this->pick($paths), '', '', ['browser' => 'Andere', 'os' => 'Unbekannt', 'device_type' => 'bot', 'is_bot' => true, 'locale' => $locales[0]]);
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('analytics_page_views')->insert($chunk);
        }

        return count($rows);
    }

    public function remove(Site $site): void
    {
        DB::table('analytics_page_views')->where('site_id', $site->id)->where('is_synthetic', true)->delete();
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function session(Site $site, CarbonImmutable $date, array $paths, array $locales): array
    {
        // Tagesgang: wenig nachts, Spitzen am Vormittag und am Abend
        $hour = $this->weighted([7 => 3, 8 => 6, 9 => 9, 10 => 10, 11 => 9, 12 => 6, 13 => 7, 14 => 8, 15 => 8, 16 => 7, 17 => 6, 18 => 6, 19 => 7, 20 => 8, 21 => 6, 22 => 3, 23 => 1, 0 => 1, 6 => 2]);
        $at = $date->setTime($hour, $this->int(0, 59), $this->int(0, 59));
        $consent = $this->int(1, 100) <= 62;
        // Etwa jeder Dritte kommt wieder
        $visitor = ! $consent ? '' : ($this->known !== [] && $this->int(1, 100) <= 35 ? $this->pick($this->known) : 'td'.$this->token(26));
        if ($visitor !== '' && ! in_array($visitor, $this->known, true)) {
            $this->known[] = $visitor;
        }
        $session = $consent ? 'td'.$this->token(26) : '';
        $device = $this->weighted(['desktop' => 52, 'mobile' => 41, 'tablet' => 7]);
        $client = match ($device) {
            'mobile' => $this->pick([['Safari', 'iOS'], ['Chrome', 'Android'], ['Safari', 'iOS'], ['Samsung Internet', 'Android'], ['Firefox', 'Android']]),
            'tablet' => $this->pick([['Safari', 'iOS'], ['Chrome', 'Android']]),
            default => $this->pick([['Chrome', 'Windows'], ['Chrome', 'macOS'], ['Safari', 'macOS'], ['Edge', 'Windows'], ['Firefox', 'Windows'], ['Firefox', 'Linux'], ['Chrome', 'Windows']]),
        };
        $source = $this->weighted(['direct' => 34, 'google' => 30, 'bing' => 4, 'duckduckgo' => 3, 'linkedin' => 6, 'facebook' => 3, 'newsletter' => 8, 'partner' => 7, 'github' => 5]);
        $referrer = ['google' => 'www.google.ch', 'bing' => 'www.bing.com', 'duckduckgo' => 'duckduckgo.com', 'linkedin' => 'www.linkedin.com', 'facebook' => 'www.facebook.com',
            'partner' => $this->pick(['www.verein-beispiel.ch', 'blog.example.org', 'news.example.ch']), 'github' => 'github.com'][$source] ?? null;
        $locale = $this->int(1, 100) <= 70 ? $locales[0] : $this->pick($locales);
        $views = $this->weighted([1 => 38, 2 => 24, 3 => 17, 4 => 10, 5 => 6, 6 => 3, 8 => 2]);
        $rows = [];
        for ($v = 0; $v < $views; $v++) {
            $rows[] = $this->row($site, $at->utc(), $v === 0 ? $this->pick(array_slice($paths, 0, 6)) : $this->pick($paths), $visitor, $session, [
                'browser' => $client[0], 'os' => $client[1], 'device_type' => $device, 'locale' => $locale,
                'referrer_host' => $v === 0 ? $referrer : null,
                'utm_source' => $v === 0 && $source === 'newsletter' ? 'newsletter' : null,
                'utm_medium' => $v === 0 && $source === 'newsletter' ? 'email' : null,
                'utm_campaign' => $v === 0 && $source === 'newsletter' ? $this->pick(['herbst-2026', 'update-1-2']) : null,
                'country' => $this->weighted(['CH' => 64, 'DE' => 18, 'AT' => 6, 'IT' => 5, 'FR' => 4, 'US' => 3]),
            ]);
            $at = $at->addSeconds($this->int(20, 240));
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function row(Site $site, CarbonImmutable $at, string $path, string $visitor, string $session, array $values): array
    {
        return [...['site_id' => $site->id, 'visitor_id' => $visitor, 'session_id' => $session, 'customer_id' => null, 'path' => $path, 'status' => 200,
            'response_time_ms' => $this->int(1, 100) <= 85 ? $this->int(25, 180) : $this->int(180, 900), 'referrer_host' => null, 'utm_source' => null, 'utm_medium' => null,
            'utm_campaign' => null, 'browser' => null, 'os' => null, 'device_type' => null, 'is_bot' => false, 'country' => null, 'locale' => null, 'is_synthetic' => true,
            'created_at' => $at], ...$values];
    }

    /** @return list<string> Adressen der veröffentlichten Seiten (Startseite zuerst) */
    private function paths(Site $site): array
    {
        $paths = [];
        foreach ($this->pages->entries($site) as $entry) {
            $path = $this->pages->pathOf($entry, $site->default_locale);
            if (is_string($path) && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }
        usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        return array_slice($paths, 0, 40);
    }

    private function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    /**
     * @template K of array-key
     *
     * @param  array<K, int>  $weights
     * @return K
     */
    private function weighted(array $weights): int|string
    {
        $roll = $this->random->getInt(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    /** @param  int<1, max>  $length */
    private function token(int $length): string
    {
        return $this->random->getBytesFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $length);
    }
}
