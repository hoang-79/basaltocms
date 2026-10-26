<?php

declare(strict_types=1);

namespace Basalto\Core\Install;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Auth\LoginLinks;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;

/**
 * Erst-Setup (K-05), gemeinsam für `basalto:install` und den Installer im Browser (Mockup 19): Datenbank prüfen und
 * migrieren, erste Site mit Standardsprache, erstes Konto als Betreiber, Einmal-Link zur ersten Anmeldung — ohne
 * Mail-Server und ohne Passwort. Läuft mehrfach gefahrlos.
 *
 * @phpstan-type DatabaseState array{ok: bool, driver: string, database: string, version: string|null, pending: int, error: string|null}
 */
final class Installer
{
    public function __construct(private readonly LoginLinks $links) {}

    /** Eingerichtet ist eine Installation mit mindestens einer Site und einem Konto — dann ist der Browser-Schritt zu. */
    public function installed(): bool
    {
        try {
            if (! Schema::hasTable('sites') || ! Schema::hasTable('users')) {
                return false;
            }
            $userModel = self::userModel();

            return Site::query()->exists() && $userModel::query()->exists();
        } catch (QueryException|PDOException) {
            return false;
        }
    }

    /** @return DatabaseState */
    public function database(): array
    {
        $connection = DB::connection();
        $state = ['ok' => false, 'driver' => (string) $connection->getDriverName(), 'database' => (string) $connection->getDatabaseName(), 'version' => null, 'pending' => 0, 'error' => null];
        try {
            $connection->getPdo();
            $state['ok'] = true;
            $state['version'] = (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                $state['pending'] = count($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
            } else {
                $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
                $state['pending'] = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
            }
        } catch (QueryException|PDOException $e) {
            // Ohne Zugangsdaten und Passwörter — nur, was beim Einrichten weiterhilft
            $state['error'] = __('Keine Verbindung zur Datenbank. Bitte DB_CONNECTION, DB_HOST, DB_DATABASE, DB_USERNAME und DB_PASSWORD in .env prüfen.');
        }

        return $state;
    }

    public function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * @return array{site: Site, user: Model, url: string, site_created: bool, user_created: bool}
     */
    public function install(string $handle, string $name, string $locale, string $timezone, string $userName, string $email, int $minutes = 60): array
    {
        $handle = Str::slug($handle);
        $email = strtolower(trim($email));
        // Download-Installation = genau ein Konto über den Sites (ADR-008)
        $account = Account::installation($name);
        $site = Site::query()->firstOrCreate(['handle' => $handle], ['account_id' => $account->id, 'name' => $name, 'default_locale' => $locale, 'timezone' => $timezone]);
        if ($site->account_id === null) {
            $site->forceFill(['account_id' => $account->id])->save();
        }
        $site->locales()->firstOrCreate(['locale' => $locale], ['is_default' => true, 'is_required' => true, 'sort_order' => 0]);
        $user = $this->user($userName, $email);
        $this->makeOwner($account, $site, $user);

        return ['site' => $site, 'user' => $user, 'url' => $this->links->create((int) $user->getKey(), 'install', $minutes),
            'site_created' => $site->wasRecentlyCreated, 'user_created' => $user->wasRecentlyCreated];
    }

    /**
     * TB 12 Scheibe 1 (M-21): eine neue Site in einem gegebenen Konto — die SaaS legt je Kunde ein Konto an statt des
     * einen Installationskontos. Sprachen in Reihenfolge, die erste ist Standard und Pflicht; Fallback auf die erste.
     * `$owner` wird Betreiber der Site; seine Rolle am Konto setzt der Aufrufer (Besitzer bei der Registrierung).
     *
     * @param  list<string>  $locales
     */
    public function provision(Account $account, Model $owner, string $handle, string $name, array $locales, string $timezone, ?string $theme): Site
    {
        $default = $locales[0] ?? 'de';
        $site = Site::query()->create(['account_id' => $account->id, 'handle' => $handle, 'name' => $name, 'default_locale' => $default, 'timezone' => $timezone, 'theme' => $theme]);
        foreach (array_values(array_unique($locales)) as $i => $code) {
            $site->locales()->create(['locale' => $code, 'is_default' => $i === 0, 'is_required' => $i === 0, 'fallback_locale' => $i === 0 ? null : $default, 'sort_order' => $i]);
        }
        $site->users()->syncWithoutDetaching([$owner->getKey() => ['role' => SiteRole::Owner->value]]);

        return $site;
    }

    /** Benutzer zur Adresse — vorhanden oder neu, passwortlos (K-04) und mit bestätigter Adresse. */
    public function user(string $name, string $email): Model
    {
        $userModel = self::userModel();
        // Passwortlos (K-04): die Spalte bleibt, der Wert ist zufällig und unbenutzbar.
        $user = $userModel::query()->firstOrCreate(['email' => strtolower(trim($email))], ['name' => $name, 'password' => Hash::make(Str::random(64))]);
        if ($user->getAttribute('email_verified_at') === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    /** Betreiber der Site und Besitzer des Kontos. */
    private function makeOwner(Account $account, Site $site, Model $user): void
    {
        $site->users()->syncWithoutDetaching([$user->getKey() => ['role' => SiteRole::Owner->value]]);
        $account->users()->syncWithoutDetaching([$user->getKey() => ['role' => AccountRole::Owner->value]]);
    }

    /** @return class-string<Model> */
    private static function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model;
    }
}
