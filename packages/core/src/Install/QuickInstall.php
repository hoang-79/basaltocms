<?php

declare(strict_types=1);

namespace Basalto\Core\Install;

use Basalto\Core\Demo\DemoReset;
use Basalto\Core\License\LicenseStatus;
use Basalto\Core\License\LicenseVerifier;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Publish\Publisher;
use Basalto\Core\Settings\Settings;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Einrichten in einem Zug (CR-004, P-04) — was der Web-Installer nach dem Entpacken aufruft: Datenbank migrieren,
 * Assets des Backends veröffentlichen, erste Site mit dem Starter-Theme und der Domain aus der Adresse, erstes Konto
 * als Betreiber, optional der Lizenzschlüssel, Startinhalte veröffentlichen, Einmal-Link zur Anmeldung. Gefragt wurde
 * nur nach Name, E-Mail und Sprache.
 *
 * CR-004 (P-07): Mit `demo` wird statt einer leeren Site die öffentliche Demo aufgesetzt (Funktionsschau, drei Konten für den
 * Schnellzugang, jede Nacht neu); die Rückgabe führt dann auf die Funktionsschau statt in die Anmeldung.
 *
 * @phpstan-type Input array{name: string, email: string, locale: string, url: string, license_key?: string|null, theme?: string|null, demo?: bool}
 * @phpstan-type Result array{url: string, site: string, host: string, live: bool, license: string|null, notes: list<string>}
 */
final class QuickInstall
{
    public const LOCALES = ['de', 'en', 'it', 'fr'];

    public function __construct(private readonly Installer $installer, private readonly Publisher $publisher, private readonly Settings $settings, private readonly DemoReset $demo) {}

    /**
     * @param  Input  $input
     * @return Result
     */
    public function run(array $input): array
    {
        $name = trim($input['name']);
        $email = strtolower(trim($input['email']));
        $locale = in_array($input['locale'], self::LOCALES, true) ? $input['locale'] : 'de';
        $host = strtolower((string) parse_url($input['url'], PHP_URL_HOST));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Name der Website fehlt oder ist zu lang.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Keine gültige E-Mail-Adresse.');
        }
        if ($host === '') {
            throw new InvalidArgumentException('Adresse der Website fehlt.');
        }

        $notes = [];
        $this->installer->migrate();
        // Assets des Backends (wie vendor:publish --tag=basalto-assets, aber zur Laufzeit nach public_path())
        (new Filesystem)->copyDirectory(dirname(__DIR__, 2).'/resources/dist', public_path('vendor/basalto/core'));
        // Wie storage:link, aber zur Laufzeit nach public_path(); manche Hoster verbieten Symlinks
        $link = public_path('storage');
        if (! file_exists($link) && ! @symlink(storage_path('app/public'), $link)) {
            $notes[] = 'Der Link public/storage liess sich nicht anlegen — öffentliche Dateien brauchen ihn.';
        }

        if (($input['demo'] ?? false) === true) {
            $demo = $this->demo->run();

            return ['url' => rtrim($input['url'], '/').'/funktionen', 'site' => $demo['site'], 'host' => $host, 'live' => true, 'license' => null, 'notes' => $notes];
        }

        $handle = Str::slug($name) ?: 'website';
        $result = $this->installer->install($handle, $name, $locale, 'Europe/Zurich', Str::headline(Str::before($email, '@')), $email);
        /** @var Site $site */
        $site = $result['site'];
        if ($site->theme === null) {
            $site->forceFill(['theme' => $input['theme'] ?? 'starter'])->save();
        }
        SiteDomain::query()->where('site_id', $site->id)->where('host', '!=', $host)->update(['is_primary' => false]);
        SiteDomain::query()->updateOrCreate(['site_id' => $site->id, 'host' => $host], ['kind' => 'custom', 'is_primary' => true, 'verified_at' => now()]);

        $license = $this->license($input['license_key'] ?? null, $notes);

        $published = $this->publisher->run($site->refresh());
        if ($published['errors'] > 0) {
            $notes[] = sprintf('Startinhalte mit %d Befund(en) — im Backend unter «Veröffentlichen» ansehen.', $published['errors']);
        }

        return ['url' => $result['url'], 'site' => $site->handle, 'host' => $host, 'live' => (bool) $published['live'], 'license' => $license, 'notes' => $notes];
    }

    /** @param  list<string>  $notes */
    private function license(?string $raw, array &$notes): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $key = LicenseVerifier::fromBase64(config('basalto.license.public_key'))->verify($raw);
        if ($key === null) {
            $notes[] = 'Der Lizenzschlüssel ist ungültig und wurde nicht eingetragen — später unter Verwaltung › Lizenz.';

            return null;
        }
        $this->settings->set(LicenseStatus::SETTING, $raw);

        return $key->id;
    }
}
