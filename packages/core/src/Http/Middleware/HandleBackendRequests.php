<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Backend\SetupJourney;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Translations;
use Basalto\Core\Core;
use Basalto\Core\Demo\DemoMode;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Extranet\SupportAccess;
use Basalto\Core\Help\HelpPages;
use Basalto\Core\Legal\Cancellation\ContractCancellations;
use Basalto\Core\License\LicenseStatus;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\NotificationCenter;
use Basalto\Core\Notifications\NotificationType;
use Basalto\Core\Operations\ReleaseInfo;
use Basalto\Core\Saas\Offboarding\Lifecycle;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\Trial;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Avatars;
use Basalto\Core\Support\Preferences;
use Basalto\Core\Theme\ThemeStatus;
use Basalto\Core\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

/** Backend-Anfragen: eigene Blade-Wurzel aus dem Paket und geteilte Props (Benutzer, Menü, Sprache). */
final class HandleBackendRequests extends Middleware
{
    protected $rootView = 'basalto::app';

    /** @return list<array{id: int, handle: string, name: string, switch_url: string}> */
    private function sitesOf(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }
        $sites = [];
        foreach (Site::query()->forUser($user)->get()->merge(SupportAccess::sitesFor($user))->unique('id') as $site) {
            $sites[] = ['id' => $site->id, 'handle' => $site->handle, 'name' => $site->name, 'switch_url' => route('basalto.site.switch', $site)];
        }

        return $sites;
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $site = app(CurrentSite::class)->get();
        $backend = app(Backend::class);
        $status = app(ThemeStatus::class)->forSite($site);
        $routeName = (string) ($request->route()?->getName() ?? '');
        $user = $request->user();
        $accessCheck = app(Access::class);
        $access = $user === null || $site === null ? ['levels' => [], 'modes' => ['setup' => false, 'admin' => false]]
            : ['levels' => $accessCheck->levels($user, $site), 'modes' => ['setup' => $accessCheck->seesGroup($user, $site, Areas::SETUP), 'admin' => $accessCheck->seesGroup($user, $site, Areas::ADMIN)]];
        // TB 23: drei Modi — Inhalte, Einrichtung (Fahrplan), Verwaltung. Zur Verwaltung gehören die Seiten ihres Menüs
        // (auch die der Module), Profil, Passkeys und das Konto der SaaS; die Grunddaten der Site sind Station «Site»
        $menus = $backend->menuByMode($site);
        $mode = Backend::MODE_CONTENT;
        $adminPrefixes = ['basalto.sites.', 'basalto.users.', 'basalto.roles.', 'basalto.settings.', 'basalto.activity.', 'basalto.profile', 'basalto.passkeys', 'basalto.saas.account.'];
        foreach ($menus[Backend::MODE_ADMIN] as $item) {
            $adminPrefixes[] = str_ends_with($item['route'], '.index') ? substr($item['route'], 0, -5) : $item['route'];
        }
        foreach ($adminPrefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                $mode = Backend::MODE_ADMIN;
            }
        }
        if (str_starts_with($routeName, 'basalto.setup') || $routeName === 'basalto.sites.edit') {
            $mode = Backend::MODE_SETUP;
        }

        return [
            ...parent::share($request),
            // Stand der Installation für den Hinweis unten links (Version, Auslieferung, Umgebung) — auch im Extranet
            'release' => ReleaseInfo::current(),
            // TB 21: das Profilbild für Kopfzeile und Benutzermenü (sonst Initialen)
            'auth' => ['user' => ($user = $request->user()) instanceof Model ? [...$user->only(['id', 'name', 'email']), 'avatar_url' => Avatars::url($user)] : null],
            'backend' => [
                'name' => config('app.name'),
                'locale' => app()->getLocale(),
                'mode' => $mode,
                'menu' => $backend->menuItems($site),
                'menus' => $this->withCounts($menus, $site),
                'modules' => array_map(static fn (array $m): array => ['handle' => $m['handle'], 'label' => __($m['label']), 'switchable' => $m['switchable']], $backend->modules()),
                'site' => $site?->only(['id', 'handle', 'name', 'default_locale']),
                'sites' => $this->sitesOf($request),
                // CR-011 TB 59: «Verträge hier kündigen» unter Anmeldung und Registrierung der SaaS (nur für Gäste)
                'cancellation' => $user === null && $site !== null && Saas::enabled() ? app(ContractCancellations::class)->url($site, app()->getLocale()) : null,
                'logout_url' => route('basalto.logout'),
                // TB 21: Benutzermenü — Profil und Einstellungen sind Reiter derselben Seite
                'profile_url' => route('basalto.profile'),
                'settings_url' => route('basalto.profile', ['reiter' => 'einstellungen']),
                // TB 27: Glocke — ungelesene und Adressen von Glocke, Center und Einstellungen
                'notifications' => $user instanceof Model ? app(NotificationCenter::class)->shared($user, NotificationType::BACKEND) : null,
                // TB 21: Darstellung nach dem Profil — light, dark oder system (der Browser entscheidet)
                'appearance' => Preferences::of($request->user())['theme'],
                // CR-004 (P-01): Adresse des Herstellers für Lizenz- und Kauf-Links
                'vendor' => ['url' => Vendor::url(), 'host' => Vendor::host(), 'license_url' => Vendor::url('lizenz-kaufen')],
                'content_url' => route('basalto.dashboard'),
                'setup_url' => route('basalto.setup'),
                'admin_url' => $menus[Backend::MODE_ADMIN][0]['href'] ?? null,
                // TB 23: Fahrplan der Einrichtung — Stationen mit Frage, Stand und Seiten
                'journey' => $site === null || ! $access['modes']['setup'] ? null : app(SetupJourney::class)->build($site, $user, $status['line'], $routeName),
                'search_url' => $site === null ? null : route('basalto.search'),
                // TB 30: geführtes Blockformular — Linkziele und Live-Vorschau für Block-Formulare
                'editor' => $site === null ? null : ['links_url' => route('basalto.editor.links'), 'preview_url' => route('basalto.editor.preview'), 'theme_images_url' => route('basalto.editor.theme-images')],
                // TB 10 Scheibe 6 (N-09): «?» im Seitenkopf → passende Seite der Redaktions-Hilfe
                'help_url' => ($help = HelpPages::forRoute($routeName)) === null ? null : route('basalto.help', ['page' => $help]),
                // TB 20 (Webhilfe): die Seite zur Route fürs Panel und die Adressen von Panel und Suche
                'help' => ['page' => $help, 'url' => route('basalto.help'), 'panel_url' => route('basalto.help.panel'), 'search_url' => route('basalto.help.search'),
                    'shortcuts' => Preferences::of($request->user())['help_shortcuts'],
                    // TB 29: Breite der Leiste aus dem Profil und wohin eine gezogene Breite gespeichert wird
                    'width' => Preferences::of($request->user())['help_width'], 'width_url' => route('basalto.profile.help-width')],
                // Stationen mit Oberfläche sind Links (B Design, C Layout, D Navigation, E Typen, G Templates)
                // CR-005 (R-06): Stufe je Bereich und welche Modi die Rolle sieht — Einrichtung nur mit einem Bereich darin
                'access' => $access['levels'],
                'modes' => $access['modes'],
                'line' => ! $access['modes']['setup'] ? [] : array_map(static fn (array $row): array => $row + ['href' => match ($row['letter']) {
                    'B' => route('basalto.setup.design'),
                    'C' => route('basalto.setup.layouts.index'),
                    'D' => route('basalto.setup.navigation.index'),
                    'E' => route('basalto.setup.types.index'),
                    'G' => route('basalto.setup.layouts.index', ['tab' => 'templates']),
                    default => null,
                }], $status['line']),
                'theme' => $status['theme'] === null ? null : ['name' => $status['theme']['name'], 'source' => $status['theme']['source']],
                'version' => Core::VERSION,
                // White-Label (G-02): Name der Agentur statt der Marke Basalto
                'brand' => LicenseStatus::brand(),
                'flash' => ['status' => $request->session()->get('status')],
                // TB 11 Scheibe 4 (G-10): Hinweisband der Demo — null ohne Demo-Modus
                'demo' => DemoMode::notice(),
                // TB 12 Scheibe 1 (M-21): Hinweisband «Testphase abgelaufen — Plan wählen» — null ausserhalb der SaaS
                'trial' => Trial::backendNotice($request->user(), $site),
                // TB 13 Scheibe 3: geteilte Props der Module für ihre Teile in Seiten des Kerns (ModuleSlot)
                'extensions' => $backend->shared($site),
                // TB 12 Scheibe 4 (M-21): Hinweisband «Gekündigt … · Löschung am …», «Gesperrt …», «Umzug läuft» — sonst null
                'account_exit' => Lifecycle::backendNotice($request->user(), $site),
                // TB 19 (P-16): Band «Support des Herstellers hat lesenden Zugriff» — für alle in der Site sichtbar
                'support' => SupportAccess::notice($site),
                // Gegenstück zu «Zum CMS» im Extranet (Hoang, 27.10.2026): nur für Superadmin und das Team des Extranets
                'extranet_url' => self::extranetUrl($request),
            ],
        ];
    }

    /**
     * Zahlen an «Freigabe» und «Übersetzung» (Mockup 9) und an Menüeinträgen der Module (Backend::count()): nur wo
     * etwas wartet; kurz zwischengespeichert, weil jede Backend-Anfrage sie braucht.
     *
     * @param  array<string, list<array<string, mixed>>>  $menus
     * @return array<string, list<array<string, mixed>>>
     */
    private function withCounts(array $menus, ?Site $site): array
    {
        if ($site === null) {
            return $menus;
        }
        $counts = Cache::remember('basalto.menu-counts.'.$site->id, 30, static function () use ($site): array {
            $review = EntryVersion::query()->where('status', EntryStatus::InReview->value)
                ->whereHas('entry', static fn ($q) => $q->withoutGlobalScopes()->where('site_id', $site->id))->count();
            $required = array_values(array_diff($site->requiredLocales(), [$site->default_locale]));
            $translations = 0;
            if ($required !== []) {
                $service = app(Translations::class);
                foreach (Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('deleted_at')->with(['versions', 'site.locales'])->latest('updated_at')->limit(100)->get() as $entry) {
                    foreach ($required as $locale) {
                        $state = $service->state($entry, $locale);
                        $translations += $state === Translations::OUTDATED || $state === Translations::MISSING ? 1 : 0;
                    }
                }
            }

            $forms = FormSubmission::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('status', 'new')->count();

            return ['basalto.reviews.index' => $review, 'basalto.translations.index' => $translations, 'basalto.forms.index' => $forms] + app(Backend::class)->counts($site);
        });
        foreach ($menus as $mode => $items) {
            foreach ($items as $i => $item) {
                $menus[$mode][$i]['count'] = $counts[(string) ($item['route'] ?? '')] ?? null;
            }
        }

        return $menus;
    }

    /** «Zum Extranet» — nur wenn es ein Extranet gibt und die Person dazugehört (Inhaber, Administration, Team). */
    private static function extranetUrl(Request $request): ?string
    {
        $user = $request->user();

        return Extranet::enabled() && $user !== null && app(ExtranetAccess::class)->isMember($user) ? Extranet::url('/') : null;
    }
}
