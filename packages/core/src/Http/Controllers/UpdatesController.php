<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Marketplace\MarketplaceClient;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeEditorException;
use Basalto\Core\Updates\Installation;
use Basalto\Core\Updates\InstanceId;
use Basalto\Core\Updates\PostSteps;
use Basalto\Core\Updates\UpdateCheck;
use Basalto\Core\Updates\UpdateSource;
use Basalto\Core\Updates\UpdateStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einstellungen › Updates (CR-007): was aktuell ist und was nicht — Kern, mitgelieferte Module, Plugins, Themes —
 * gegen den Hersteller der Umgebung. Kern und Plugins spielt ein eigener Prozess ein (`basalto:update`), die Seite
 * zeigt seinen Fortschritt; wer startet, bekommt einen Freipass für den Wartungsmodus. Themes kommen wie bisher als
 * Entwürfe in die Bibliothek der Site. Kunden der SaaS sehen nur ihre Themes — den Rest spielt der Betreiber ein.
 */
final class UpdatesController
{
    public function __construct(private readonly CurrentSite $current, private readonly UpdateCheck $check) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $installation = app(Installation::class);
        // Kunden der SaaS sehen nur ihre Themes — Kern und Plugins spielt der Betreiber der Installation ein
        $saas = self::customerOfSaas($site);
        $fresh = $request->boolean('neu');
        $cached = $fresh ? null : $this->check->cached();
        $mode = $installation->mode();

        return Inertia::render('core::Updates', [
            // Ohne Stand im Cache wird nachgeladen — die Seite steht sofort, die Prüfung beim Hersteller kommt nach
            'check' => $cached !== null ? $this->forPage($cached, $site) : Inertia::defer(fn (): array => $this->forPage($this->check->run($fresh), $site)),
            'installation' => [
                'mode' => $mode, 'version' => $installation->version(), 'known' => $installation->knowsVersion(), 'saas' => $saas,
                'problems' => $saas ? [] : $installation->problems(),
                'modules' => $installation->bundledModules(),
                'commands' => match ($mode) {
                    'git' => "git pull\ncomposer install --no-dev\nphp artisan migrate --force\nphp artisan vendor:publish --tag=basalto-assets --force\nphp artisan optimize:clear",
                    'composer' => "composer update \"basalto/*\" --with-all-dependencies\nphp artisan migrate --force\nphp artisan vendor:publish --tag=basalto-assets --force\nphp artisan optimize:clear",
                    default => 'php artisan basalto:update --kern',
                },
            ],
            'source' => ['url' => UpdateSource::url(), 'host' => UpdateSource::host(), 'stage' => UpdateSource::stage()],
            // TB 56: Was die Prüfung mitschickt, steht hier offen — mit dem Weg zum Abschalten
            'usage' => $saas ? null : ['on' => (bool) app(Settings::class)->get(InstanceId::SETTING, true),
                'url' => route('basalto.settings.index', ['group' => 'operations'])],
            'status' => UpdateStatus::read(),
            'can_apply' => ! $saas && Gate::allows('update', $site),
            'urls' => ['index' => route('basalto.updates'), 'check' => route('basalto.updates.check'), 'core' => route('basalto.updates.core'),
                'plugin' => route('basalto.updates.plugin'), 'status' => route('basalto.updates.status'), 'theme' => route('basalto.updates.theme'), 'themes' => route('basalto.setup.themes')],
        ]);
    }

    /** Stand des laufenden Updates für die Seite (wird während des Laufs alle zwei Sekunden abgefragt). */
    public function status(): JsonResponse
    {
        return response()->json(UpdateStatus::read(), 200, ['Cache-Control' => 'no-store']);
    }

    public function check(): RedirectResponse
    {
        $result = $this->check->run(true);

        return back()->with('status', $result['error'] ?? trans_choice('Geprüft — :count Update verfügbar.|Geprüft — :count Updates verfügbar.', $result['count'], ['count' => $result['count']]));
    }

    public function core(Request $request): RedirectResponse
    {
        return $this->start($request, ['--kern' => true]);
    }

    public function plugin(Request $request): RedirectResponse
    {
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:214', 'regex:#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#']])['name'];

        return $this->start($request, ['--plugin' => $name]);
    }

    /** Ein Theme aus dem Marktplatz in die Bibliothek seiner Site — als Entwürfe zum Prüfen (wie bisher). */
    public function theme(Request $request, MarketplaceClient $marketplace): RedirectResponse
    {
        $data = $request->validate(['site_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120']]);
        $site = Site::query()->findOrFail((int) $data['site_id']);
        Gate::authorize('update', $site);
        abort_if(self::customerOfSaas($this->current->require()) && $site->id !== $this->current->require()->id, 403);
        $version = null;
        foreach ($marketplace->catalog(true)['entries'] as $entry) {
            if ($entry['kind'] === 'theme' && $entry['name'] === $data['name']) {
                $version = $entry['version'];
            }
        }
        $user = $request->user();
        if ($version === null || ! $user instanceof Authenticatable) {
            return back()->withErrors(['theme' => __('Dieses Theme gibt es im Marktplatz nicht (mehr).')]);
        }
        try {
            $result = $marketplace->installTheme($site, (string) $data['name'], $version, $user);
        } catch (ThemeEditorException $e) {
            return back()->withErrors(['theme' => $e->getMessage()]);
        }
        $this->check->forget();

        return back()->with('status', trans_choice('Theme «:theme» :version geladen — :count Entwurf wartet unter Einrichtung › Themes auf Ihre Prüfung.|Theme «:theme» :version geladen — :count Entwürfe warten unter Einrichtung › Themes auf Ihre Prüfung.',
            $result['drafts'], ['theme' => $result['theme'], 'version' => $version, 'count' => $result['drafts']]));
    }

    /**
     * Ein Update im Hintergrund starten: eigener PHP-Prozess (der Webprozess bliebe sonst minutenlang hängen und lüde
     * nach dem Tausch Klassen aus dem neuen `vendor`). Wer startet, bekommt den Freipass für den Wartungsmodus.
     *
     * @param  array<string, string|bool>  $options
     */
    private function start(Request $request, array $options): RedirectResponse
    {
        $site = $this->current->require();
        abort_if(self::customerOfSaas($site), 404);
        Gate::authorize('update', $site);
        if (UpdateStatus::running()) {
            return back()->withErrors(['update' => __('Es läuft schon ein Update.')]);
        }
        $problems = app(Installation::class)->problems();
        if ($problems !== []) {
            return back()->withErrors(['update' => implode(' ', $problems)]);
        }
        $secret = Str::random(40);
        $user = $request->user();
        $arguments = ['basalto:update'];
        foreach ($options as $option => $value) {
            $arguments[] = $value === true ? $option : $option.'='.$value;
        }
        $arguments[] = '--freipass='.$secret;
        $arguments[] = '--von='.($user instanceof Authenticatable ? (string) ($user->getAttribute('name') ?? '') : '');
        // Gleich ein Stand für die Seite — sie lädt nach, solange er «läuft» (der Prozess überschreibt ihn)
        $name = $user instanceof Authenticatable ? (string) ($user->getAttribute('name') ?? '') : null;
        new UpdateStatus(UpdateStatus::file(), isset($options['--kern']) ? 'core' : 'plugin:'.(string) ($options['--plugin'] ?? ''),
            isset($options['--kern']) ? (string) __('Kern') : (string) __('Plugin :name', ['name' => (string) ($options['--plugin'] ?? '')]), '', $name);
        $php = PostSteps::php();
        $command = implode(' ', array_map('escapeshellarg', [$php, base_path('artisan'), ...$arguments])).' > '.escapeshellarg(storage_path('logs/update.log')).' 2>&1 &';
        Process::path(base_path())->timeout(30)->run($command);

        return back()->with('status', __('Das Update läuft — diese Seite zeigt den Fortschritt. Während des Tauschs ist die Website kurz im Wartungsmodus.'))
            ->withCookie(MaintenanceModeBypassCookie::create($secret));
    }

    /** Eine Site eines Kunden auf der SaaS (nicht des Installationskontos)? */
    private static function customerOfSaas(Site $site): bool
    {
        return Saas::enabled() && $site->account_id !== Account::installation()->id;
    }

    /**
     * Für die Seite: auf der SaaS nur die Themes der aktuellen Site.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function forPage(array $result, Site $site): array
    {
        if (self::customerOfSaas($site)) {
            $result['themes'] = array_values(array_filter((array) $result['themes'], static fn (array $t): bool => $t['site_id'] === $site->id));
            $result['plugins'] = [];
        }

        return $result;
    }
}
