<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Install\Installer;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Publish\Publisher;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Eine SaaS-Site anlegen und live schalten (TB 12 Scheibe 1, G-11): Site mit Sprachen und Betreiber über den Installer,
 * Alias `<alias>.basaltocms.com` als primäre Domain — sofort erreichbar über die Wildcard-Domain der Instanz, ohne
 * Cloud-Aufruf —, dann `basalto:publish` (Seed des Themes, Prüfung, Vorwärmen, live). Für die Registrierung und für
 * «Weitere Site» im Konto-Bereich.
 */
final class SiteProvisioner
{
    public function __construct(private readonly Installer $installer, private readonly Publisher $publisher, private readonly ThemeCatalog $catalog) {}

    /**
     * Anlegen und live schalten in einem Zug («Weitere Site» im Konto-Bereich).
     *
     * @param  list<string>  $locales  erste = Standardsprache
     * @return array{site: Site, live: bool, errors: list<string>}
     *
     * @throws ValidationException wenn der Alias inzwischen vergeben ist (Feld «alias»)
     */
    public function create(Account $account, Model $owner, string $name, string $alias, array $locales, string $timezone, string $theme): array
    {
        try {
            $site = DB::transaction(fn (): Site => $this->build($account, $owner, $name, $alias, $locales, $timezone, $theme));
        } catch (UniqueConstraintViolationException) {
            throw self::aliasTaken($alias, $name);
        }

        return ['site' => $site] + $this->publish($site);
    }

    /**
     * Site mit Sprachen, Betreiber und Alias anlegen — ohne eigene Transaktion (der Aufrufer fasst sie mit Konto und
     * Benutzer zusammen) und ohne Veröffentlichung.
     *
     * @param  list<string>  $locales
     */
    public function build(Account $account, Model $owner, string $name, string $alias, array $locales, string $timezone, string $theme): Site
    {
        $site = $this->installer->provision($account, $owner, $alias, $name, $locales, $timezone, $theme);
        $site->domains()->create(['host' => $alias.'.'.Saas::aliasDomain(), 'kind' => SiteDomain::KIND_ALIAS, 'is_primary' => true, 'verified_at' => now()]);
        LocalAliasFolders::link($alias);

        return $site;
    }

    /**
     * `basalto:publish` für die neue Site: Seed des Themes, Prüfung, Vorwärmen, live.
     *
     * @return array{live: bool, errors: list<string>}
     */
    public function publish(Site $site): array
    {
        // Im Scope der neuen Site — aus dem Backend heraus ist sonst die gewählte Site gesetzt und der Scope verbirgt die Inhalte
        $result = app(CurrentSite::class)->within($site, fn (): array => $this->publisher->run($site));
        $errors = [];
        foreach ($result['findings'] as $finding) {
            if ($finding['severity'] === 'error') {
                $errors[] = $finding['message'];
            }
        }
        $site->refresh();

        return ['live' => $result['live'], 'errors' => $errors];
    }

    public static function aliasTaken(string $alias, string $siteName): ValidationException
    {
        return ValidationException::withMessages(['alias' => AliasCheck::check($alias, $siteName)['message']]);
    }

    /**
     * Die Themes zur Wahl: aus `basalto.saas.themes`, sonst alle installierten ohne Fehler; das Vorgabe-Theme zuerst.
     *
     * @return list<array{name: string, label: string, description: string|null, has_preview: bool}>
     */
    public function themes(): array
    {
        $allowed = (array) config('basalto.saas.themes', []);
        $default = (string) config('basalto.saas.default_theme', 'starter');
        $themes = [];
        foreach ($this->catalog->names() as $name) {
            if ($allowed !== [] && ! in_array($name, $allowed, true)) {
                continue;
            }
            $meta = $this->catalog->meta($name);
            if ($meta['errors'] > 0) {
                continue;
            }
            $themes[] = ['name' => $name, 'label' => $meta['label'], 'description' => $meta['description'], 'has_preview' => $meta['preview'] !== null];
        }
        usort($themes, static fn (array $a, array $b): int => [$a['name'] !== $default, $a['label']] <=> [$b['name'] !== $default, $b['label']]);

        return $themes;
    }

    /** Das vorgewählte Theme: das Vorgabe-Theme, wenn es zur Wahl steht, sonst das erste. */
    public function defaultTheme(): ?string
    {
        $names = array_column($this->themes(), 'name');
        $default = (string) config('basalto.saas.default_theme', 'starter');

        return in_array($default, $names, true) ? $default : ($names[0] ?? null);
    }
}
