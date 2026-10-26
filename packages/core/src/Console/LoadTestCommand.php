<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Operations\LoadStats;
use CurlHandle;
use CurlMultiHandle;
use Illuminate\Console\Command;

/**
 * HTTP-Lasttest (N-08, TB 12): ruft die angegebenen Adressen gleichzeitig ab — eine feste Zahl Anfragen oder eine Dauer
 * lang — und meldet Anfragen je Sekunde, p50/p95/p99, Fehler, Statuscodes und Treffer des Seiten-Caches. Ohne Paket,
 * mit `curl_multi`. Fremde Hosts nur mit `--remote` (gedacht für die eigene Instanz «start» nach der Cloud-Freigabe).
 */
final class LoadTestCommand extends Command
{
    protected $signature = 'basalto:load-test
        {url* : Adressen, die reihum abgerufen werden}
        {--concurrency=10 : gleichzeitige Anfragen}
        {--duration=30 : Dauer in Sekunden (wenn --requests 0 ist)}
        {--requests=0 : feste Zahl Anfragen statt einer Dauer}
        {--timeout=30 : Zeitlimit je Anfrage in Sekunden}
        {--max-p95=0 : Ziel für p95 in ms — überschritten heisst Exit 1}
        {--max-errors=1 : höchste Fehlerquote in Prozent — überschritten heisst Exit 1}
        {--remote : auch Hosts ausserhalb von localhost, *.test und *.localhost (nur eigene Instanzen!)}
        {--json : Ergebnis als JSON}';

    protected $description = 'HTTP-Lasttest gegen eigene Adressen: Anfragen je Sekunde, p95, Fehler';

    public function handle(): int
    {
        /** @var list<string> $urls */
        $urls = array_values(array_filter((array) $this->argument('url'), 'is_string'));
        foreach ($urls as $url) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || $host === '') {
                $this->error(sprintf('Keine gültige http(s)-Adresse: %s', $url));

                return self::INVALID;
            }
            if (! self::isLocal($host) && ! $this->option('remote')) {
                $this->error(sprintf('%s ist kein lokaler Host — für die eigene Instanz mit --remote bestätigen.', $host));

                return self::INVALID;
            }
        }
        $concurrency = max(1, (int) $this->option('concurrency'));
        $requests = max(0, (int) $this->option('requests'));
        $duration = max(1, (int) $this->option('duration'));
        $timeout = max(1, (int) $this->option('timeout'));

        $stats = new LoadStats;
        $started = microtime(true);
        $this->fire($urls, $concurrency, $requests, $duration, $timeout, $stats);
        $summary = $stats->summary(microtime(true) - $started);

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Anfragen', 'Sekunden', 'je Sekunde', 'Fehler', 'Cache-Treffer', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms'], [[
                $summary['requests'], $summary['seconds'], $summary['rps'], $summary['errors'].' ('.$summary['error_rate'].' %)',
                $summary['cache_hits'], $summary['p50'], $summary['p95'], $summary['p99'], $summary['max'],
            ]]);
            $this->line('Statuscodes: '.implode(', ', array_map(static fn (int|string $code, int $n): string => $code.' × '.$n, array_keys($summary['statuses']), $summary['statuses'])));
        }

        $maxP95 = (float) $this->option('max-p95');
        if ($maxP95 > 0 && $summary['p95'] > $maxP95) {
            $this->error(sprintf('p95 %.1f ms liegt über dem Ziel %.1f ms.', $summary['p95'], $maxP95));

            return self::FAILURE;
        }
        if ($summary['error_rate'] > (float) $this->option('max-errors')) {
            $this->error(sprintf('Fehlerquote %.2f %% liegt über %.2f %%.', $summary['error_rate'], (float) $this->option('max-errors')));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public static function isLocal(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    }

    /** @param list<string> $urls */
    private function fire(array $urls, int $concurrency, int $requests, int $duration, int $timeout, LoadStats $stats): void
    {
        $multi = curl_multi_init();
        $deadline = microtime(true) + $duration;
        $sent = 0;
        // Mindestens eine Anfrage geht immer hinaus (--requests ≥ 1 oder --duration ≥ 1), dann bis zur Gleichzeitigkeit auffüllen
        $active = 0;
        do {
            $this->add($multi, $urls[$sent % count($urls)], $timeout);
            $sent++;
            $active++;
        } while ($active < $concurrency && self::more($sent, $requests, $deadline));
        while ($active > 0) {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
            while (($done = curl_multi_info_read($multi)) !== false) {
                /** @var CurlHandle $handle */
                $handle = $done['handle'];
                $error = $done['result'] !== CURLE_OK ? curl_error($handle) : null;
                $headers = (string) curl_multi_getcontent($handle);
                $stats->record((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    preg_match('/^x-basalto-cache:\s*hit/im', $headers) === 1, $error);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                $active--;
                if (self::more($sent, $requests, $deadline)) {
                    $this->add($multi, $urls[$sent % count($urls)], $timeout);
                    $sent++;
                    $active++;
                }
            }
        }
        curl_multi_close($multi);
    }

    /** Noch eine Anfrage? Feste Zahl oder bis zum Ende der Dauer. */
    private static function more(int $sent, int $requests, float $deadline): bool
    {
        return $requests > 0 ? $sent < $requests : microtime(true) < $deadline;
    }

    private function add(CurlMultiHandle $multi, string $url, int $timeout): void
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'basalto-load-test',
        ]);
        curl_multi_add_handle($multi, $handle);
    }
}
