<?php

declare(strict_types=1);

namespace Basalto\Core\Demo;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Publish\Publisher;
use Basalto\Core\Testdata\Testdata;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Lava\Theme\LavaSource;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Die Demo neu aufsetzen (TB 11 Scheibe 4, G-10): Datenbank frisch migrieren, Medien und Formular-Anhänge leeren,
 * die Demo-Site mit dem Seed ihres Themes einspielen und live schalten, die drei Konten des Schnellzugangs anlegen.
 * Das Ergebnis ist jedes Mal derselbe Bestand — belegt mit einer Prüfsumme über die Inhalte ohne Zeitstempel.
 *
 * Läuft nur im Demo-Modus; die Sicherung (Schalter und Bestätigung) liegt im Befehl `basalto:demo-reset`.
 */
final class DemoReset
{
    /** Tabellen, deren Inhalt die Demo ausmacht — Grundlage der Prüfsumme. */
    public const TABLES = ['accounts', 'account_user', 'sites', 'site_locales', 'site_domains', 'site_user', 'users', 'entries', 'entry_versions', 'navigation_items', 'redirects', 'media', 'snippets', 'lava_sources'];

    /** Module, die die Funktionsschau zeigt — eingeschaltet, sofern installiert (das Forum erst, wenn die Demo es mitbringt). */
    public const MODULES = ['comments', 'members', 'ai', 'forum'];

    /** Ordner der Ablage, die das Neuaufsetzen leert — Medien, Formular-Anhänge, Profilbilder, Theme-Bilder. */
    public const DIRECTORIES = ['sites', 'forms', 'avatars', 'theme-assets'];

    /**
     * Markierung in der Ablage: Sie gehört der Demo und darf geleert werden. Ohne sie (und ohne Demo-Site in der
     * Datenbank) bricht das Neuaufsetzen ab, sobald die Ablage Dateien hat — etwa wenn nur die Datenbank auf eine
     * Wegwerf-Datenbank zeigt, die Ablage aber die einer echten Installation ist.
     */
    public const MARKER = '.basalto-demo';

    public function __construct(private readonly Publisher $publisher, private readonly PageCache $pageCache, private readonly Pages $pages, private readonly Backend $backend,
        private readonly Testdata $testdata) {}

    /** @return array{site: string, counts: array<string, int>, fingerprint: string} */
    public function run(): array
    {
        if (! DemoMode::enabled()) {
            throw new RuntimeException('Die Demo lässt sich nur im Demo-Modus neu aufsetzen (BASALTO_DEMO=true).');
        }
        $disk = Storage::disk();
        $handle = (string) config('basalto.demo.site', 'demo');
        if (! self::diskBelongsToDemo($disk, $handle)) {
            throw new RuntimeException(sprintf('Die Ablage «%s» enthält Dateien, gehört aber keiner Demo: weder die Markierung %s noch die Site «%s» in der Datenbank. Abgebrochen, damit keine fremden Medien verloren gehen.',
                (string) config('filesystems.default'), self::MARKER, $handle));
        }
        // Nicht «migrate:fresh»: Das ist in Produktion gesperrt (prohibitDestructiveCommands), die Demo läuft aber dort.
        // Die Sicherung dafür liegt im Demo-Schalter und in der Bestätigung des Befehls.
        Schema::dropAllTables();
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Migrationen fehlgeschlagen: '.trim(Artisan::output()));
        }
        // TB 21: auch die Profilbilder — die Benutzer der Demo entstehen jede Nacht neu
        foreach (self::DIRECTORIES as $directory) {
            $disk->deleteDirectory($directory);
        }
        $disk->put(self::MARKER, 'Ablage der Basalto-Demo: basalto:demo-reset darf sie leeren.');

        $account = Account::installation('Basalto Demo');
        $site = Site::query()->create(['account_id' => $account->id, 'handle' => $handle, 'name' => 'Basalto Demo', 'default_locale' => 'de', 'timezone' => 'Europe/Zurich', 'theme' => (string) config('basalto.demo.theme', 'starter')]);
        foreach (['de', 'en', 'fr', 'it'] as $i => $locale) {
            $site->locales()->create(['locale' => $locale, 'is_default' => $locale === 'de', 'is_required' => $locale === 'de', 'fallback_locale' => $locale === 'de' ? null : 'de', 'sort_order' => $i]);
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $site->domains()->create(['host' => strtolower($host), 'kind' => 'custom', 'is_primary' => true]);
        }

        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        foreach (DemoMode::accounts() as $demo) {
            // Ohne Passwort (K-04): zufällig und unbenutzbar — angemeldet wird über den Schnellzugang
            $user = $userModel::query()->create(['name' => $demo['name'], 'email' => $demo['email'], 'password' => Hash::make(Str::random(64))]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $site->users()->attach($user->getKey(), ['role' => $demo['site_role']->value]);
            if ($demo['account_role'] !== null) {
                $account->users()->attach($user->getKey(), ['role' => $demo['account_role']->value]);
            }
        }

        // CR-004 (P-07): die Funktionsschau als Lava der Site (Seed neben dem des Themes) und die Module, die sie zeigt
        LavaSource::query()->create(['site_id' => $site->id, 'scope' => LavaSource::SCOPE_SITE, 'path' => DemoTour::LAVA_PATH, 'source' => DemoTour::lava()]);
        $installed = array_column($this->backend->modules(), 'handle');
        foreach (self::MODULES as $module) {
            if (in_array($module, $installed, true)) {
                $site->setModuleEnabled($module, true);
            }
        }
        $site->save();

        $result = $this->publisher->run($site);
        if ($result['errors'] > 0) {
            $messages = array_map(static fn (array $f): string => $f['message'], array_filter($result['findings'], static fn (array $f): bool => $f['severity'] === 'error'));

            throw new RuntimeException('Die Demo-Site geht nicht live: '.implode(' · ', $messages));
        }
        // Testdaten für die Funktionsschau (BASALTO_DEMO_TESTDATA: klein, mittel, gross oder aus) — Team, Kundenkonten,
        // Kommentare, Mitglieder und, sofern installiert, das Forum. Fester Startwert: jede Nacht derselbe Bestand.
        $scale = TestdataRun::SCALES[(string) config('basalto.demo.testdata', 'klein')] ?? null;
        if ($scale !== null) {
            config(['queue.default' => 'sync']);
            DB::transaction(fn () => $this->testdata->seed(new TestdataRun($site->refresh(), $scale)));
        }
        $this->pageCache->invalidate($site->id);
        $this->pages->forget();

        return ['site' => $handle, 'counts' => self::counts(), 'fingerprint' => self::fingerprint()];
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $counts = [];
        foreach (['sites', 'users', 'entries', 'entry_versions', 'media'] as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $counts;
    }

    /**
     * Prüfsumme über den Bestand der Demo: alle Zeilen der Tabellen aus TABLES nach ID, ohne Zeitstempel, Passwörter
     * und Tokens; JSON mit sortierten Schlüsseln (MySQL ordnet sie selbst um).
     */
    public static function fingerprint(): string
    {
        $state = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $state[$table] = $query->get()->map(static function (object $row): array {
                $clean = [];
                foreach ((array) $row as $column => $value) {
                    if (preg_match('/(_at|password|remember_token|secret|token|recovery_codes)$/', (string) $column) === 1) {
                        continue;
                    }
                    $clean[(string) $column] = self::normalize($value);
                }
                ksort($clean);

                return $clean;
            })->all();
        }

        return hash('sha256', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_string($value) && ($value[0] ?? '') !== '' && in_array($value[0], ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::sortKeys($decoded);
            }
        }

        // Zahlen und Wahrheitswerte liefern die Treiber verschieden (PostgreSQL true, SQLite 1) — als Text vergleichen
        return is_bool($value) ? (string) (int) $value : (is_int($value) || is_float($value) ? (string) $value : $value);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $v): mixed => is_array($v) ? self::sortKeys($v) : $v, $value);
    }

    /** Gehört die Ablage der Demo? Markiert, die Demo-Site in der Datenbank (Demo von vor der Markierung) oder leer. */
    private static function diskBelongsToDemo(Filesystem $disk, string $handle): bool
    {
        if ($disk->exists(self::MARKER) || (Schema::hasTable('sites') && DB::table('sites')->where('handle', $handle)->exists())) {
            return true;
        }
        foreach (self::DIRECTORIES as $directory) {
            if ($disk->allFiles($directory) !== []) {
                return false;
            }
        }

        return true;
    }
}
