<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Marketplace\MarketplaceClient;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Plugins\PluginInstaller;
use Basalto\Core\Plugins\PluginLoader;
use Basalto\Core\Plugins\PluginPackage;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeEditorException;
use Basalto\Core\Updates\Installation;
use Basalto\Core\Updates\UpdateCheck;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * TB 11 Scheibe 2 (M-20, Mockup 20): Verwaltung › Module jeder Installation — Reiter «Module» (installierte Module
 * und ihr Stand auf dieser Site; geschaltet wird in der Site unter «Module») und «Marktplatz» (Katalog von basaltocms.com
 * mit Installationsdialog: Plugins per Composer-Befehl, kein ZIP-Upload — CR-001 § 3.4; Themes mit «Theme
 * übernehmen» als Entwurf). Nur, wer die Site verwaltet.
 */
final class ModulesController
{
    public function __construct(private readonly CurrentSite $current, private readonly Backend $backend, private readonly MarketplaceClient $client) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $labels = [];
        foreach ($this->backend->modules() as $module) {
            $labels[$module['handle']] = __($module['label']);
        }
        $modules = array_values(array_filter($this->backend->modules(), static fn (array $m): bool => $m['handle'] !== 'core'));
        $fresh = $request->boolean('neu');

        return Inertia::render('core::Modules/Index', [
            'tab' => $request->query('reiter') === 'marktplatz' ? 'marketplace' : 'modules',
            'modules' => array_map(fn (array $m): array => [
                'handle' => $m['handle'], 'label' => $labels[$m['handle']], 'switchable' => $m['switchable'], 'enabled' => $this->backend->moduleEnabled($m['handle'], $site),
                'api' => is_int($m['manifest']['api'] ?? null) ? $m['manifest']['api'] : null,
                'requires' => array_values(array_map(static fn (string $h): string => $labels[$h] ?? $h, array_filter((array) ($m['manifest']['requires'] ?? []), 'is_string'))),
            ], $modules),
            'site' => ['name' => $site->name, 'edit_url' => route('basalto.sites.edit', $site)],
            'marketplace' => ['url' => $this->client->baseUrl(), 'host' => $this->client->host(), 'repository' => $this->client->baseUrl().'/composer'],
            'catalog' => Inertia::defer(fn (): array => $this->client->catalog($fresh)),
            // CR-018 (TB 98): Plugins im Backend — installiert, installierbar, Kundenkonto
            'plugins' => $this->plugins(),
            'plugin_install' => $this->pluginInstall($site),
            'urls' => ['index' => route('basalto.modules'), 'install_theme' => route('basalto.modules.marketplace.theme'), 'theme_editor' => route('basalto.setup.theme-editor'), 'try_on' => route('basalto.modules.marketplace.try'),
                'install_plugin' => route('basalto.modules.plugins.install'), 'toggle_plugin' => route('basalto.modules.plugins.toggle'), 'remove_plugin' => route('basalto.modules.plugins.remove'),
                'account' => route('basalto.modules.marketplace.account'), 'updates' => route('basalto.updates')],
        ]);
    }

    /** CR-018: Plugin aus dem Marktplatz im Backend installieren (ohne Composer). */
    public function installPlugin(Request $request): RedirectResponse
    {
        $name = $this->pluginName($request);

        return $this->pluginAction(fn (PluginInstaller $installer, ?string $by): string => $installer->install($name, $by));
    }

    public function togglePlugin(Request $request): RedirectResponse
    {
        $name = $this->pluginName($request);
        $enabled = $request->boolean('enabled');

        return $this->pluginAction(fn (PluginInstaller $installer, ?string $by): string => $installer->enable($name, $enabled, $by));
    }

    public function removePlugin(Request $request): RedirectResponse
    {
        $name = $this->pluginName($request);
        $deleteData = $request->boolean('delete_data');

        return $this->pluginAction(fn (PluginInstaller $installer, ?string $by): string => $installer->remove($name, $deleteData, $by));
    }

    /** Kundenkonto von basaltocms.com verbinden — für Plugins und gekaufte Themes, ohne Zugang zum Server. */
    public function connectAccount(Request $request): RedirectResponse
    {
        $this->guardInstallation();
        $data = $request->validate(['email' => ['required', 'email', 'max:190'], 'token' => ['required', 'string', 'min:20', 'max:200']], [], ['email' => __('E-Mail'), 'token' => __('Token')]);
        try {
            $this->client->connect((string) $data['email'], (string) $data['token']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        return back()->with('status', __('Kundenkonto verbunden — Basalto lädt Plugins und gekaufte Themes jetzt damit.'));
    }

    public function disconnectAccount(): RedirectResponse
    {
        $this->guardInstallation();
        $this->client->disconnect();

        return back()->with('status', __('Kundenkonto getrennt.'));
    }

    /**
     * @return list<array{name: string, label: string, module: string, version: string, enabled: bool, error: string|null}>
     */
    private function plugins(): array
    {
        $loader = app(PluginLoader::class);
        $out = [];
        foreach ($loader->all() as $name => $record) {
            $out[] = ['name' => $name, 'label' => $record['label'], 'module' => $record['module'], 'version' => $record['version'], 'enabled' => $record['enabled'],
                'error' => $loader->failures()[$name] ?? ($record['error'] ?? null)];
        }

        return $out;
    }

    /**
     * @return array{manageable: bool, available: bool, problems: list<string>, installed: list<string>, account: array{source: string|null, email: string|null}}
     */
    private function pluginInstall(Site $site): array
    {
        $saas = self::customerOfSaas($site);

        return [
            'manageable' => ! $saas && Gate::allows('update', $site),
            'available' => ! $saas && Gate::allows('update', $site) && app(PluginInstaller::class)->problems() === [],
            'problems' => $saas ? [] : app(PluginInstaller::class)->problems(),
            'installed' => array_keys(app(Installation::class)->plugins()),
            'account' => $saas ? ['source' => null, 'email' => null] : $this->client->account(),
        ];
    }

    private function pluginName(Request $request): string
    {
        $this->guardInstallation();

        return (string) $request->validate(['name' => ['required', 'string', 'max:214', 'regex:'.PluginPackage::NAME]])['name'];
    }

    /** @param  callable(PluginInstaller, string|null): string  $action */
    private function pluginAction(callable $action): RedirectResponse
    {
        $user = request()->user();
        $by = $user instanceof Authenticatable ? (string) ($user->getAttribute('name') ?? '') : null;
        try {
            $message = $action(app(PluginInstaller::class), $by);
        } catch (RuntimeException $e) {
            return back()->withErrors(['plugin' => $e->getMessage()]);
        }
        app(UpdateCheck::class)->forget();

        return back()->with('status', $message);
    }

    /** Plugins und Kundenkonto gehören der Installation: im Abo der Betreiber, sonst wer Updates einspielen darf (E-4). */
    private function guardInstallation(): void
    {
        $site = $this->current->require();
        abort_if(self::customerOfSaas($site), 404);
        Gate::authorize('update', $site);
    }

    private static function customerOfSaas(Site $site): bool
    {
        return Saas::enabled() && $site->account_id !== Account::installation()->id;
    }

    public function installTheme(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $request->validate(['name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'], 'version' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Za-z.+-]+$/']]);
        $user = $request->user() ?? abort(403);
        try {
            $result = $this->client->installTheme($site, (string) $data['name'], (string) $data['version'], $user);
        } catch (ThemeEditorException $e) {
            return back()->withErrors(['marketplace' => $e->getMessage()]);
        }

        if ($result['created']) {
            return redirect()->route('basalto.setup.themes')->with('status', __('Theme «:name» aus dem Marktplatz (signiert) in die Bibliothek aufgenommen — Vorschau ansehen und dann wählen.', ['name' => $result['theme']]));
        }

        return redirect()->route('basalto.setup.theme-editor', ['theme' => $result['theme']])->with('status', __('Theme «:name» aus dem Marktplatz (signiert) als :count Entwurf/Entwürfe übernommen — prüfen und veröffentlichen.',
            ['name' => $result['name'], 'count' => $result['drafts']]));
    }

    /**
     * TB 24: «Mit meiner Site anprobieren» aus dem Katalog — zuerst eine Bestätigung (ein Link allein ändert nichts),
     * dann holt die Installation das Theme in ihre Bibliothek und zeigt die Site damit in der eigenen Sitzung.
     */
    public function tryOn(Request $request): Response
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $name = (string) $request->query('theme', '');
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) === 1, 404);
        $entry = null;
        $catalog = $this->client->catalog();
        foreach ($catalog['entries'] as $candidate) {
            if ($candidate['kind'] === 'theme' && $candidate['name'] === $name) {
                $entry = $candidate;
            }
        }

        return Inertia::render('core::Modules/TryOn', [
            'name' => $name,
            'entry' => $entry === null ? null : ['title' => $entry['title'], 'version' => $entry['version'], 'author' => $entry['author'], 'free' => $entry['free'],
                'preview' => $entry['preview'], 'summary' => $entry['summary'], 'badges' => $entry['badges']],
            'error' => $catalog['ok'] ? null : $catalog['error'],
            'site' => ['name' => $site->name],
            'urls' => ['start' => route('basalto.modules.marketplace.try.start'), 'modules' => route('basalto.modules'), 'themes' => route('basalto.setup.themes')],
        ]);
    }

    public function startTryOn(Request $request): RedirectResponse|SymfonyResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $data = $request->validate(['name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'], 'version' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Za-z.+-]+$/']]);
        try {
            $result = $this->client->installTheme($site, (string) $data['name'], (string) $data['version'], $request->user() ?? abort(403));
        } catch (ThemeEditorException $e) {
            return back()->withErrors(['marketplace' => $e->getMessage()]);
        }
        // Die Vorschau zeigt das Theme mit den Inhalten der Site — gewählt wird es erst unter Einrichtung › Themes
        $request->session()->put('basalto.theme_preview.'.$site->id, $result['theme']);

        return Inertia::location(url('/'));
    }
}
