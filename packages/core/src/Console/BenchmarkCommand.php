<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lasttest für das Mandantenmodell (N-08, Prüfpunkt 8): füllt eine Installation mit vielen Sites, Inhalten und Medien
 * und misst die Abfragen, die jede Anfrage trägt — Host → Site, Slug → Fassung, Listen, Zählungen — samt Leck-Prüfung
 * über Site-Grenzen. Bench-Sites heissen «bench-n» und lassen sich mit --clean wieder entfernen.
 */
final class BenchmarkCommand extends Command
{
    protected $signature = 'basalto:benchmark
        {--sites=100 : Sites}
        {--entries=1000 : Inhalte je Site}
        {--media=3334 : Medien je Site (drei Varianten je Medium)}
        {--locales=2 : Sprachen je Site (de, en, it, fr)}
        {--runs=30 : Wiederholungen je Messung}
        {--keep : Vorhandene Bench-Daten behalten und nur messen}
        {--clean : Bench-Daten entfernen und beenden}
        {--json : Ergebnis als JSON}';

    protected $description = 'Lasttest: viele Sites, Inhalte und Medien anlegen und die tragenden Abfragen messen';

    private const LOCALES = ['de', 'en', 'it', 'fr'];

    private const CHUNK = 1000;

    public function handle(CurrentSite $current): int
    {
        if ($this->option('clean')) {
            $deleted = $this->clean();
            $this->components->info(sprintf('%d Bench-Sites entfernt.', $deleted));

            return self::SUCCESS;
        }
        $sites = max(1, (int) $this->option('sites'));
        $entries = max(1, (int) $this->option('entries'));
        $media = max(0, (int) $this->option('media'));
        $locales = array_slice(self::LOCALES, 0, max(1, min(4, (int) $this->option('locales'))));
        $runs = max(1, (int) $this->option('runs'));

        $seeding = 0.0;
        if (! $this->option('keep') || Site::query()->where('handle', 'like', 'bench-%')->doesntExist()) {
            $this->clean();
            $start = hrtime(true);
            $this->seed($sites, $entries, $media, $locales);
            $seeding = (hrtime(true) - $start) / 1e6;
        }
        $siteIds = [];
        foreach (Site::query()->where('handle', 'like', 'bench-%')->pluck('id') as $id) {
            $siteIds[] = (int) $id;
        }
        if ($siteIds === []) {
            $this->components->error('Keine Bench-Sites vorhanden.');

            return self::FAILURE;
        }
        $results = $this->measure($current, $siteIds, $locales, $runs);
        $counts = [
            'sites' => count($siteIds),
            'entries' => (int) Entry::query()->withoutGlobalScope(SiteScope::class)->whereIn('site_id', $siteIds)->count(),
            'versions' => (int) EntryVersion::query()->whereIn('entry_id', Entry::query()->withoutGlobalScope(SiteScope::class)->whereIn('site_id', $siteIds)->select('id'))->count(),
            'media' => (int) Media::query()->whereIn('site_id', $siteIds)->count(),
        ];
        $report = ['driver' => DB::connection()->getDriverName(), 'counts' => $counts, 'seeding_ms' => round($seeding), 'database_mb' => $this->databaseSize(), 'runs' => $runs, 'measurements' => $results];
        if ($this->option('json')) {
            // Direkt auf den Symfony-Output: laravel/pao kollabiert sonst die Einrückung
            $this->output->getOutput()->write((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

            return self::SUCCESS;
        }
        $this->components->twoColumnDetail('Treiber', $report['driver']);
        $this->components->twoColumnDetail('Sites / Inhalte / Fassungen / Medien', sprintf('%d / %d / %d / %d', $counts['sites'], $counts['entries'], $counts['versions'], $counts['media']));
        $this->components->twoColumnDetail('Datenbank', sprintf('%.1f MB', $report['database_mb']));
        if ($seeding > 0) {
            $this->components->twoColumnDetail('Befüllen', sprintf('%.1f s', $seeding / 1000));
        }
        foreach ($results as $name => $row) {
            $this->components->twoColumnDetail(sprintf('%s (%s)', $name, $row['label']), sprintf('Median %.1f ms · p95 %.1f ms · %s', $row['median_ms'], $row['p95_ms'], $row['note']));
        }

        return self::SUCCESS;
    }

    private function clean(): int
    {
        $ids = Site::query()->where('handle', 'like', 'bench-%')->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        DB::table('media')->whereIn('site_id', $ids)->delete();
        DB::table('entry_versions')->whereIn('entry_id', DB::table('entries')->whereIn('site_id', $ids)->select('id'))->delete();
        DB::table('entries')->whereIn('site_id', $ids)->delete();
        DB::table('sites')->whereIn('id', $ids)->delete();

        return $ids->count();
    }

    /** @param  list<string>  $locales */
    private function seed(int $sites, int $entries, int $media, array $locales): void
    {
        $now = now()->toDateTimeString();
        $bar = $this->output->createProgressBar($sites);
        for ($s = 1; $s <= $sites; $s++) {
            $siteId = (int) DB::table('sites')->insertGetId(['handle' => 'bench-'.$s, 'name' => 'Bench '.$s, 'default_locale' => $locales[0], 'timezone' => 'Europe/Zurich', 'theme' => 'nguyen-development', 'created_at' => $now, 'updated_at' => $now]);
            $localeRows = [];
            foreach ($locales as $i => $locale) {
                $localeRows[] = ['site_id' => $siteId, 'locale' => $locale, 'is_default' => $i === 0, 'is_required' => $i === 0, 'fallback_locale' => $i === 0 ? null : $locales[0], 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('site_locales')->insert($localeRows);
            $domains = [['site_id' => $siteId, 'host' => sprintf('bench-%d.basaltocms.test', $s), 'kind' => SiteDomain::KIND_ALIAS, 'is_primary' => $s % 5 !== 0, 'verified_at' => null, 'created_at' => $now, 'updated_at' => $now]];
            if ($s % 5 === 0) {
                $domains[] = ['site_id' => $siteId, 'host' => sprintf('www.kunde-%d.test', $s), 'kind' => SiteDomain::KIND_CUSTOM, 'is_primary' => true, 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('site_domains')->insert($domains);
            $this->seedEntries($siteId, $entries, $locales, $now);
            $this->seedMedia($siteId, $media, $now);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    /** @param  list<string>  $locales */
    private function seedEntries(int $siteId, int $count, array $locales, string $now): void
    {
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = ['site_id' => $siteId, 'type' => $n <= max(1, intdiv($count, 10)) ? 'page' : 'post', 'uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === self::CHUNK) {
                DB::table('entries')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('entries')->insert($rows);
        }
        $entryIds = DB::table('entries')->where('site_id', $siteId)->orderBy('id')->pluck('id');
        $versions = [];
        $n = 0;
        foreach ($entryIds as $entryId) {
            $n++;
            foreach ($locales as $locale) {
                $versions[] = $this->version((int) $entryId, $locale, 1, EntryStatus::Published, $n, $now);
                if ($n % 5 === 0) {
                    $versions[] = $this->version((int) $entryId, $locale, 2, EntryStatus::Draft, $n, $now);
                }
                if (count($versions) >= self::CHUNK) {
                    DB::table('entry_versions')->insert($versions);
                    $versions = [];
                }
            }
        }
        if ($versions !== []) {
            DB::table('entry_versions')->insert($versions);
        }
    }

    /** @return array<string, mixed> */
    private function version(int $entryId, string $locale, int $number, EntryStatus $status, int $n, string $now): array
    {
        $text = str_repeat('Basalt entsteht, wenn Lava erstarrt — schnell, dicht und dauerhaft. ', 9);

        return ['entry_id' => $entryId, 'locale' => $locale, 'number' => $number, 'status' => $status->value, 'title' => sprintf('Inhalt %d (%s)', $n, $locale), 'slug' => sprintf('inhalt-%d-%s', $n, $locale),
            'data' => json_encode(['intro' => 'Nummer '.$n, 'blocks' => [['type' => 'hero', 'fields' => ['title' => 'Titel '.$n, 'text' => $text]], ['type' => 'text', 'fields' => ['body' => $text.$text]], ['type' => 'cta', 'fields' => ['label' => 'Kontakt', 'url' => '/kontakt']]]], JSON_UNESCAPED_UNICODE),
            'published_at' => $status === EntryStatus::Published ? $now : null, 'created_at' => $now, 'updated_at' => $now];
    }

    private function seedMedia(int $siteId, int $count, string $now): void
    {
        $rows = [];
        for ($m = 1; $m <= $count; $m++) {
            $base = sprintf('media/%d/%06d', $siteId, $m);
            $rows[] = ['site_id' => $siteId, 'disk' => 's3', 'path' => $base.'.jpg', 'name' => sprintf('bild-%d.jpg', $m), 'mime' => 'image/jpeg', 'size' => 240000 + $m, 'width' => 2400, 'height' => 1600,
                'variants' => json_encode(['thumb' => ['path' => $base.'-thumb.webp', 'width' => 320, 'height' => 213], 'content' => ['path' => $base.'-content.webp', 'width' => 1280, 'height' => 853], 'wide' => ['path' => $base.'-wide.webp', 'width' => 1920, 'height' => 1280]]),
                'variants_generated_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === self::CHUNK) {
                DB::table('media')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('media')->insert($rows);
        }
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $locales
     * @return array<string, array{label: string, median_ms: float, p95_ms: float, note: string}>
     */
    private function measure(CurrentSite $current, array $siteIds, array $locales, int $runs): array
    {
        $pick = static fn (): int => $siteIds[array_rand($siteIds)];
        $perSite = max(1, (int) Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $siteIds[0])->count());
        $results = [];
        $leaks = 0;

        $results['host'] = $this->timed('Host → Site', $runs, static function () use ($pick): string {
            $site = Site::query()->whereKey($pick())->firstOrFail();
            $host = sprintf('bench-%d.basaltocms.test', (int) substr($site->handle, 6));
            $found = SiteDomain::query()->where('host', $host)->first()?->site;

            return $found?->id === $site->id ? 'ok' : 'fehlt';
        });
        $results['slug'] = $this->timed('Slug → veröffentlichte Fassung', $runs, static function () use ($pick, $locales, $perSite): string {
            $n = random_int(1, $perSite);
            $version = EntryVersion::query()->join('entries', 'entries.id', '=', 'entry_versions.entry_id')
                ->where('entries.site_id', $pick())->where('entry_versions.locale', $locales[0])->where('entry_versions.slug', sprintf('inhalt-%d-%s', $n, $locales[0]))
                ->where('entry_versions.status', EntryStatus::Published->value)->select('entry_versions.*')->first();

            return $version !== null ? 'ok' : 'fehlt';
        });
        $results['list_page'] = $this->timed('Inhaltsliste, 50 mit Fassungen', $runs, static function () use ($pick, $current): string {
            $site = Site::query()->whereKey($pick())->firstOrFail();

            return (string) $current->within($site, static fn (): int => count(Entry::query()->with('versions')->latest('id')->limit(50)->get()));
        });
        $results['list_all'] = $this->timed('Inhaltsliste, alle mit Fassungen (heutiger Controller)', min($runs, 5), static function () use ($pick, $current): string {
            $site = Site::query()->whereKey($pick())->firstOrFail();

            return (string) $current->within($site, static fn (): int => count(Entry::query()->with('versions')->latest('id')->get()));
        });
        $results['count'] = $this->timed('Inhalte zählen je Site', $runs, static function () use ($pick, $current): string {
            $site = Site::query()->whereKey($pick())->firstOrFail();

            return (string) $current->within($site, static fn (): int => Entry::query()->count());
        });
        $results['media'] = $this->timed('Medienliste, 100 je Site', $runs, static function () use ($pick): string {
            $site = Site::query()->whereKey($pick())->firstOrFail();

            // Medien tragen noch keinen Site-Scope (Pflicht ab Stufe 1) — hier ausdrücklich gefiltert
            return (string) count(Media::query()->where('site_id', $site->id)->latest('id')->limit(100)->get());
        });
        $results['leak'] = $this->timed('Leck-Prüfung: fremde Site im Scope', $runs, static function () use ($pick, $current, $siteIds, &$leaks): string {
            $own = Site::query()->whereKey($pick())->firstOrFail();
            $otherId = $siteIds[array_rand($siteIds)];
            if ($otherId === $own->id) {
                $otherId = $siteIds[0] === $own->id ? $siteIds[count($siteIds) - 1] : $siteIds[0];
            }
            $foreign = Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $otherId)->first();
            if ($foreign === null) {
                return 'keine fremden Daten';
            }
            $seen = $current->within($own, static fn (): bool => Entry::query()->whereKey($foreign->id)->exists() || Entry::query()->count() !== Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $own->id)->count());
            if ($seen) {
                $leaks++;
            }

            return $seen ? 'LECK' : 'dicht';
        });
        $results['leak']['note'] = sprintf('Lecks: %d von %d', $leaks, $runs);

        return $results;
    }

    /**
     * @param  \Closure(): string  $probe
     * @return array{label: string, median_ms: float, p95_ms: float, note: string}
     */
    private function timed(string $label, int $runs, \Closure $probe): array
    {
        $times = [];
        $last = '';
        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $last = $probe();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        $median = $times[intdiv(count($times), 2)];
        $p95 = $times[min(count($times) - 1, (int) floor(count($times) * 0.95))];

        return ['label' => $label, 'median_ms' => round($median, 2), 'p95_ms' => round($p95, 2), 'note' => 'letztes Ergebnis: '.$last];
    }

    private function databaseSize(): float
    {
        $connection = DB::connection();
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // InnoDB führt die Statistiken erst nach ANALYZE nach — sonst meldet information_schema den alten Stand
            $connection->statement('analyze table sites, site_domains, entries, entry_versions, media');
        }
        $bytes = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => (float) ($connection->selectOne('select coalesce(sum(data_length + index_length), 0) as b from information_schema.tables where table_schema = database()')->b ?? 0),
            'pgsql' => (float) ($connection->selectOne('select pg_database_size(current_database()) as b')->b ?? 0),
            'sqlite' => (float) (is_file((string) $connection->getConfig('database')) ? filesize((string) $connection->getConfig('database')) : 0),
            default => 0.0,
        };

        return round($bytes / 1048576, 1);
    }
}
