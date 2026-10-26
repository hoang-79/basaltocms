<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Cloud\CloudCli;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountExport;
use Basalto\Core\Models\Activity;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\LocalAliasFolders;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Löschen nach der Sperre (TB 12 Scheibe 4, M-04 «dann gelöscht und protokolliert»): jede Site des Kontos mit allen
 * Zeilen in Tabellen mit `site_id`, ihren Dateien im Bucket (`sites/<id>/…`, Formular-Dateien `forms/<id>/…`) und ihren
 * Domains in der Cloud; dazu die Exporte. Benutzer ohne weitere Site und ohne weiteres Konto werden gelöscht. Das Konto
 * selbst bleibt **anonymisiert** stehen — Rechnungen (Scheibe 2) hängen daran und bleiben wegen der Aufbewahrungspflicht.
 *
 * Für die Bereinigung der Quelle nach einem Umzug löscht {@see eraseSites()} nur Sites, Exporte und Mitgliedschaften;
 * das Konto bleibt unverändert («umgezogen»).
 */
final class AccountEraser
{
    public function __construct(private readonly CloudCli $cloud, private readonly PageCache $pageCache) {}

    /** @return array{sites: int, files: int, users: int} */
    public function erase(Account $account): array
    {
        $counts = $this->eraseSites($account);
        $columns = ['name' => __('Gelöschtes Konto #:id', ['id' => $account->id]), 'billing' => null, 'status' => Lifecycle::ERASED, 'erased_at' => now(), 'instance_target' => null];
        // Zahlungsmittel (Cashier, Scheibe 2) vergessen; die Stripe-Kennung bleibt für die Zuordnung der Belege
        foreach (['pm_type', 'pm_last_four'] as $column) {
            if (Schema::hasColumn('accounts', $column)) {
                $columns[$column] = null;
            }
        }
        $account->forceFill($columns)->save();
        self::log('account.erased', $account, $counts);

        return $counts;
    }

    /**
     * Protokoll ohne Site und ohne Person: Nach dem Löschen gibt es beide nicht mehr (der Recorder nähme sonst die
     * gewählte Site und die angemeldete Person der Anfrage).
     *
     * @param  array<string, mixed>  $after
     */
    public static function log(string $action, Account $account, array $after): void
    {
        Activity::query()->create(['site_id' => null, 'user_id' => null, 'subject_type' => $account->getMorphClass(), 'subject_id' => $account->id,
            'action' => $action, 'before' => null, 'after' => $after, 'ip_hash' => null, 'created_at' => now()]);
    }

    /** @return array{sites: int, files: int, users: int} */
    public function eraseSites(Account $account): array
    {
        $counts = ['sites' => 0, 'files' => 0, 'users' => 0];
        foreach (AccountExport::query()->where('account_id', $account->id)->get() as $export) {
            $counts['files'] += self::deleteExportFile($export) ? 1 : 0;
            $export->delete();
        }
        $userIds = DB::table('account_user')->where('account_id', $account->id)->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all();
        foreach ($account->sites()->orderBy('id')->get() as $site) {
            $userIds = [...$userIds, ...DB::table('site_user')->where('site_id', $site->id)->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all()];
            $counts['files'] += $this->eraseSite($site);
            $counts['sites']++;
        }
        DB::table('account_user')->where('account_id', $account->id)->delete();
        $counts['users'] = $this->deleteOrphans(array_values(array_unique($userIds)));

        return $counts;
    }

    /** Eine Site ganz: Cloud-Domains, Dateien, alle Zeilen mit ihrer `site_id`, die Site selbst. Zurück: gelöschte Dateien. */
    public function eraseSite(Site $site): int
    {
        foreach (SiteDomain::query()->where('site_id', $site->id)->whereNotNull('cloud_domain_id')->get() as $domain) {
            try {
                $this->cloud->domainDelete((string) $domain->cloud_domain_id);
            } catch (Throwable $e) {
                // Die Löschung der Inhalte hängt nicht an der Cloud — der Eintrag bleibt im Protokoll für den Betrieb
                Log::warning('Cloud-Domain beim Löschen der Site nicht entfernt', ['host' => $domain->host, 'error' => $e->getMessage()]);
            }
        }
        $files = 0;
        $disks = DB::table('media')->where('site_id', $site->id)->distinct()->pluck('disk')->map(static fn (mixed $d): string => (string) $d)->all();
        foreach (array_unique([...$disks, (string) config('filesystems.default')]) as $disk) {
            $files += self::deleteDirectory($disk, 'sites/'.$site->id);
        }
        $files += self::deleteDirectory('local', 'forms/'.$site->id);
        // TB 22: Bilder und Schriften der eigenen Themes
        $files += self::deleteDirectory((string) config('filesystems.default'), 'theme-assets/'.$site->id);
        DB::transaction(static function () use ($site): void {
            if (Schema::hasColumn('entries', 'parent_id')) {
                DB::table('entries')->where('site_id', $site->id)->update(['parent_id' => null]);
            }
            foreach (self::siteTables() as $table) {
                DB::table($table)->where('site_id', $site->id)->delete();
            }
            DB::table('sites')->where('id', $site->id)->delete();
        });
        $this->pageCache->invalidate($site->id);
        // TB 18 (P-08): lokale Verweise der Aliasse dieser Site aufräumen
        LocalAliasFolders::prune();

        return $files;
    }

    public static function deleteExportFile(AccountExport $export): bool
    {
        if ($export->disk === null || $export->path === null) {
            return false;
        }
        try {
            return Storage::disk($export->disk)->delete($export->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Alle Tabellen mit `site_id` (wie der Export-Test sie findet), alphabetisch — abhängige Tabellen hängen mit
     * Fremdschlüsseln (cascade) an ihren Eltern.
     *
     * @return list<string>
     */
    public static function siteTables(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];
            if ($name !== 'sites' && Schema::hasColumn($name, 'site_id')) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return array_values(array_unique($tables));
    }

    private static function deleteDirectory(string $disk, string $directory): int
    {
        try {
            $storage = Storage::disk($disk);
            $count = count($storage->allFiles($directory));
            $storage->deleteDirectory($directory);

            return $count;
        } catch (Throwable $e) {
            Log::warning('Dateien einer Site nicht gelöscht', ['disk' => $disk, 'directory' => $directory, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Benutzer ohne weitere Site und ohne weiteres Konto löschen; hängen noch Zeilen ohne Kaskade an ihnen, werden sie
     * stattdessen anonymisiert und deaktiviert.
     *
     * @param  list<int>  $userIds
     */
    private function deleteOrphans(array $userIds): int
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');
        $count = 0;
        foreach ($userIds as $id) {
            if (DB::table('site_user')->where('user_id', $id)->exists() || DB::table('account_user')->where('user_id', $id)->exists()) {
                continue;
            }
            $user = $model::query()->find($id);
            if ($user === null) {
                continue;
            }
            try {
                DB::transaction(static fn () => $user->delete());
            } catch (QueryException) {
                $user->forceFill(['name' => __('Gelöschter Benutzer'), 'email' => 'geloescht-'.$id.'@invalid.basalto', 'deactivated_at' => now()])->save();
            }
            $count++;
        }

        return $count;
    }
}
