<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Core;
use Basalto\Core\Install\Installer;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Transfer\ArchiveReader;
use Basalto\Core\Transfer\ArchiveWriter;
use Basalto\Core\Transfer\SiteExporter;
use Basalto\Core\Transfer\SiteImporter;
use Basalto\Core\Transfer\TransferException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Export eines ganzen Kontos (TB 12 Scheibe 4): `account.json` mit Konto, Benutzern und Konto-Rollen, dazu je Site der
 * Vollexport aus TB 09 unter `sites/<handle>.zip` (M-18, dasselbe Format wie `basalto:export`). Dasselbe Archiv ist der
 * Download «ganzes Konto» im Konto-Bereich und die Fracht eines Umzugs zwischen den Instanzen ({@see import()}).
 *
 * Benutzer gehen mit Profil, ohne Passwort, Passkeys und Zwei-Faktor-Geheimnis (Geheimnisse bleiben in der Instanz;
 * angemeldet wird mit Code per Mail). Konto-Spalten gehen allgemein mit (auch die von Cashier, Scheibe 2) — ausser
 * Kennung, Stand und Instanz, die das Ziel selbst setzt —, dazu die Abos von Cashier (`account_subscriptions` mit
 * Positionen). Rechnungen (`account_invoices`) bleiben auf der Quelle: Nummernkreis je Instanz und Aufbewahrungspflicht.
 *
 * @phpstan-type ImportResult array{account: Account, sites: list<Site>, notes: list<string>, users: int}
 */
final class AccountArchive
{
    public const FORMAT = 'basalto-account';

    public const FORMAT_VERSION = 1;

    public const DATA_FILE = 'account.json';

    /** Konto-Spalten, die das Ziel selbst setzt. */
    private const LOCAL_COLUMNS = ['id', 'status', 'instance', 'instance_target', 'erased_at', 'updated_at'];

    /** Profilfelder der Benutzer, die mitgehen (wenn die Spalte da ist). */
    private const USER_FIELDS = ['name', 'first_name', 'last_name', 'locale', 'timezone', 'phone', 'phone_country', 'street', 'address_extra', 'postal_code', 'city', 'country', 'preferences', 'email_verified_at'];

    public function __construct(private readonly SiteExporter $exporter, private readonly SiteImporter $importer, private readonly Installer $installer, private readonly AccountEraser $eraser) {}

    /**
     * @param  null|Closure(int): void  $progress  Fortschritt 0–100 nach jeder Site
     * @return array{path: string, size: int, sites: int}
     */
    public function write(Account $account, string $path, ?Closure $progress = null): array
    {
        $writer = new ArchiveWriter($path);
        $temporary = [];
        try {
            $sites = $account->sites()->orderBy('id')->get();
            $entries = [];
            foreach ($sites->values() as $i => $site) {
                $file = sys_get_temp_dir().'/basalto-account-'.Str::random(12).'-'.$site->handle.'.zip';
                $temporary[] = $file;
                $this->exporter->export($site, $file);
                $writer->addLocalFile('sites/'.$site->handle.'.zip', $file, compress: false);
                $entries[] = ['handle' => $site->handle, 'name' => $site->name, 'file' => 'sites/'.$site->handle.'.zip'];
                if ($progress !== null) {
                    $progress((int) floor(90 * ($i + 1) / max(1, $sites->count())));
                }
            }
            $payload = $this->payload($account, $entries);
            $writer->addString(self::DATA_FILE, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $result = $writer->close(['format' => self::FORMAT, 'format_version' => self::FORMAT_VERSION, 'generated_at' => $payload['generated_at'], 'basalto_version' => Core::VERSION, 'account' => $account->name]);
        } catch (Throwable $e) {
            $writer->discard();

            throw $e;
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        return ['path' => $result['path'], 'size' => $result['size'], 'sites' => count($entries)];
    }

    /**
     * Ein Konto-Archiv in diese Instanz übernehmen (Ziel eines Umzugs): Konto, Benutzer und Rollen, dann jede Site über
     * den Import aus TB 09 — mit Konto, Mitgliedern und Domains. Ist ein Handle schon vergeben, wird nichts angelegt;
     * scheitert eine Site, werden die schon übernommenen wieder gelöscht (ganz oder gar nicht).
     *
     * @return ImportResult
     *
     * @throws TransferException
     */
    public function import(string $zip): array
    {
        $reader = new ArchiveReader($zip);
        $manifest = $reader->verify();
        if (($manifest['format'] ?? null) !== self::FORMAT || ! $reader->has(self::DATA_FILE)) {
            throw new TransferException('Kein Konto-Export von Basalto (format «'.self::FORMAT.'» und account.json erwartet).');
        }
        $payload = json_decode($reader->get(self::DATA_FILE), true);
        if (! is_array($payload) || ! is_array($payload['account'] ?? null) || ($payload['format_version'] ?? null) !== self::FORMAT_VERSION) {
            throw new TransferException('account.json ist kein gültiger Konto-Export dieser Version.');
        }
        /** @var list<array{handle: string, name: string, file: string}> $sites */
        $sites = array_values(array_filter((array) ($payload['sites'] ?? []), 'is_array'));
        foreach ($sites as $entry) {
            if (! $reader->has((string) $entry['file'])) {
                throw new TransferException(sprintf('Site «%s» fehlt im Archiv.', $entry['handle']));
            }
            if (Site::query()->where('handle', (string) $entry['handle'])->exists()) {
                throw new TransferException(sprintf('Site «%s» gibt es in dieser Instanz schon — Umzug abgebrochen, nichts angelegt.', $entry['handle']));
            }
        }

        [$account, $newUsers] = DB::transaction(fn (): array => $this->createAccount($payload));
        $imported = [];
        $notes = [];
        try {
            foreach ($sites as $entry) {
                $file = sys_get_temp_dir().'/basalto-move-'.Str::random(12).'.zip';
                try {
                    $reader->extractTo((string) $entry['file'], $file);
                    $result = $this->importer->import($file, (string) $entry['handle'], $account);
                } finally {
                    @unlink($file);
                }
                $imported[] = $result['site'];
                $notes = [...$notes, ...$result['notes']];
            }
        } catch (Throwable $e) {
            foreach ($imported as $site) {
                $this->eraser->eraseSite($site);
            }
            DB::table('account_user')->where('account_id', $account->id)->delete();
            $account->delete();
            foreach ($newUsers as $user) {
                $user->delete();
            }

            throw $e;
        }

        return ['account' => $account, 'sites' => $imported, 'notes' => $notes, 'users' => count((array) ($payload['users'] ?? []))];
    }

    /**
     * @param  list<array{handle: string, name: string, file: string}>  $sites
     * @return array<string, mixed>
     */
    private function payload(Account $account, array $sites): array
    {
        $row = (array) DB::table('accounts')->where('id', $account->id)->first();
        $columns = array_diff_key($row, array_flip(self::LOCAL_COLUMNS));
        $userIds = DB::table('account_user')->where('account_id', $account->id)->pluck('user_id')
            ->merge(DB::table('site_user')->whereIn('site_id', $account->sites()->pluck('id'))->pluck('user_id'))->unique()->values()->all();
        $userColumns = array_values(array_intersect(self::USER_FIELDS, Schema::getColumnListing('users')));
        $users = DB::table('users')->whereIn('id', $userIds)->orderBy('id')->get(['id', 'email', ...$userColumns]);
        $emails = $users->pluck('email', 'id')->map(static fn (mixed $e): string => strtolower((string) $e))->all();

        return [
            'format' => self::FORMAT, 'format_version' => self::FORMAT_VERSION, 'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'basalto_version' => Core::VERSION, 'instance' => Saas::instance(),
            'account' => $columns,
            'users' => $users->map(static function (object $user) use ($userColumns): array {
                $out = ['email' => strtolower((string) $user->email)];
                foreach ($userColumns as $column) {
                    $out[$column] = $user->{$column};
                }

                return $out;
            })->values()->all(),
            'roles' => DB::table('account_user')->where('account_id', $account->id)->orderBy('id')->get(['user_id', 'role'])
                ->map(static fn (object $r): array => ['email' => $emails[(int) $r->user_id] ?? '', 'role' => (string) $r->role])->filter(static fn (array $r): bool => $r['email'] !== '')->values()->all(),
            'sites' => $sites,
            'subscriptions' => self::subscriptions($account),
        ];
    }

    /**
     * Abos von Cashier am Konto (Scheibe 2: `account_subscriptions` mit Positionen) — damit das Ziel das laufende Abo kennt.
     *
     * @return list<array<string, mixed>>
     */
    private static function subscriptions(Account $account): array
    {
        if (! Schema::hasTable('account_subscriptions')) {
            return [];
        }
        $out = [];
        foreach (DB::table('account_subscriptions')->where('account_id', $account->id)->orderBy('id')->get() as $row) {
            $items = Schema::hasTable('account_subscription_items')
                ? DB::table('account_subscription_items')->where('subscription_id', $row->id)->orderBy('id')->get()
                    ->map(static fn (object $item): array => array_diff_key((array) $item, array_flip(['id', 'subscription_id'])))->values()->all()
                : [];
            $out[] = array_diff_key((array) $row, array_flip(['id', 'account_id'])) + ['items' => $items];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Account, 1: list<Model>}
     */
    private function createAccount(array $payload): array
    {
        $known = Schema::getColumnListing('accounts');
        $columns = array_intersect_key((array) $payload['account'], array_flip($known));
        $columns = array_diff_key($columns, array_flip(self::LOCAL_COLUMNS));
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s');
        $id = (int) DB::table('accounts')->insertGetId([...$columns, 'status' => Lifecycle::ACTIVE, 'instance' => Saas::instance(), 'instance_target' => null, 'updated_at' => $now,
            'created_at' => $columns['created_at'] ?? $now]);
        $account = Account::query()->findOrFail($id);

        $userColumns = array_values(array_intersect(self::USER_FIELDS, Schema::getColumnListing('users')));
        $users = [];
        $created = [];
        foreach ((array) ($payload['users'] ?? []) as $data) {
            if (! is_array($data) || ! is_string($data['email'] ?? null) || $data['email'] === '') {
                continue;
            }
            $email = strtolower($data['email']);
            $existing = DB::table('users')->where('email', $email)->exists();
            $user = $this->installer->user((string) ($data['name'] ?? $email), $email);
            if (! $existing) {
                $created[] = $user;
                $fill = [];
                foreach ($userColumns as $column) {
                    if (array_key_exists($column, $data) && $column !== 'name') {
                        $fill[$column] = $data[$column];
                    }
                }
                if ($fill !== []) {
                    DB::table('users')->where('id', $user->getKey())->update($fill);
                }
            }
            $users[$email] = $user;
        }
        foreach ((array) ($payload['roles'] ?? []) as $role) {
            $user = is_array($role) ? ($users[strtolower((string) ($role['email'] ?? ''))] ?? null) : null;
            $value = is_array($role) ? AccountRole::tryFrom((string) ($role['role'] ?? '')) : null;
            if ($user !== null && $value !== null) {
                $account->users()->syncWithoutDetaching([$user->getKey() => ['role' => $value->value]]);
            }
        }

        if (Schema::hasTable('account_subscriptions')) {
            $subscriptionColumns = Schema::getColumnListing('account_subscriptions');
            $itemColumns = Schema::hasTable('account_subscription_items') ? Schema::getColumnListing('account_subscription_items') : [];
            foreach ((array) ($payload['subscriptions'] ?? []) as $subscription) {
                if (! is_array($subscription)) {
                    continue;
                }
                $items = (array) ($subscription['items'] ?? []);
                $row = array_intersect_key($subscription, array_flip(array_diff($subscriptionColumns, ['id', 'account_id'])));
                $subscriptionId = (int) DB::table('account_subscriptions')->insertGetId([...$row, 'account_id' => $account->id]);
                foreach ($items as $item) {
                    if (is_array($item) && $itemColumns !== []) {
                        DB::table('account_subscription_items')->insert([...array_intersect_key($item, array_flip(array_diff($itemColumns, ['id', 'subscription_id']))), 'subscription_id' => $subscriptionId]);
                    }
                }
            }
        }

        return [$account, $created];
    }
}
