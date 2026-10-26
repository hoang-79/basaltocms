<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Http\Requests\SiteRequest;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Theme\ThemeCatalog;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Sites verwalten (K-01, Mockup 18): Liste, anlegen und bearbeiten mit den Reitern Allgemein, Sprachen, Domains, Module. */
final class SiteController
{
    public function __construct(private readonly Backend $backend, private readonly Dates $dates) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $dates = $this->dates;
        $sites = Site::query()->forUser($user)->with(['locales', 'domains'])->withCount('users')->get()->map(static fn (Site $site): array => [
            'id' => $site->id, 'handle' => $site->handle, 'name' => $site->name, 'default_locale' => $site->default_locale,
            'locales' => $site->locales->map(static fn ($l): array => ['locale' => $l->locale, 'is_default' => (bool) $l->is_default, 'is_required' => (bool) $l->is_required, 'fallback_locale' => $l->fallback_locale])->values()->all(),
            'domains' => $site->domains->map(static fn ($d): array => ['host' => $d->host, 'kind' => $d->kind, 'is_primary' => (bool) $d->is_primary, 'verified_at' => $dates->dateTime($d->verified_at)])->values()->all(),
            'users_count' => (int) $site->getAttribute('users_count'), 'edit_url' => route('basalto.sites.edit', $site), 'switch_url' => route('basalto.site.switch', $site),
        ])->values()->all();

        return Inertia::render('core::Sites/Index', ['sites' => $sites, 'create_url' => route('basalto.sites.create'), 'can_create' => Gate::allows('create', Site::class)]);
    }

    public function create(): Response|RedirectResponse
    {
        // TB 12 Scheibe 1: Auf der SaaS entsteht eine Site im Konto (Alias, Kontingent) — nicht hier
        if (Saas::enabled()) {
            return redirect()->route('basalto.saas.account.sites');
        }
        Gate::authorize('create', Site::class);

        return $this->form(null);
    }

    public function store(SiteRequest $request): RedirectResponse
    {
        if (Saas::enabled()) {
            return redirect()->route('basalto.saas.account.sites');
        }
        Gate::authorize('create', Site::class);
        $user = $request->user();
        abort_if($user === null, 403);
        $site = DB::transaction(function () use ($request, $user): Site {
            $account = $this->accountFor($user);
            $site = Site::query()->create([...$this->attributes($request), 'account_id' => $account->id]);
            $this->syncRelations($site, $request);
            $site->users()->syncWithoutDetaching([$user->getAuthIdentifier() => ['role' => SiteRole::Owner->value]]);

            return $site;
        });

        return $this->done($request, $site, __('Site «:name» angelegt.', ['name' => $site->name]));
    }

    public function edit(Site $site): Response
    {
        Gate::authorize('view', $site);

        return $this->form($site);
    }

    public function update(SiteRequest $request, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);
        DB::transaction(function () use ($request, $site): void {
            $site->fill($this->attributes($request))->save();
            $this->syncRelations($site, $request);
        });

        return $this->done($request, $site, __('Site «:name» gespeichert.', ['name' => $site->name]));
    }

    private function form(?Site $site): Response
    {
        $modules = array_values(array_filter($this->backend->modules(), static fn (array $m): bool => $m['handle'] !== 'core'));
        $moduleState = [];
        foreach ($modules as $m) {
            // TB 11 Scheibe 1: neue Site mit der Vorgabe des Moduls (Lizenzverkauf ab Werk aus)
            $moduleState[$m['handle']] = $site === null ? $this->backend->enabledByDefault($m['handle']) : $this->backend->moduleEnabled($m['handle'], $site);
        }
        $themes = app(ThemeCatalog::class)->names();

        return Inertia::render('core::Sites/Form', [
            'site' => [
                'id' => $site?->id,
                'handle' => $site !== null ? $site->handle : '',
                'name' => $site !== null ? $site->name : '',
                'default_locale' => $site !== null ? $site->default_locale : 'de',
                'timezone' => $site !== null ? $site->timezone : 'Europe/Zurich',
                'theme' => $site?->theme,
                'locales' => $site === null
                    ? [['locale' => 'de', 'is_default' => true, 'is_required' => true, 'fallback_locale' => null]]
                    : $site->locales->map(static fn ($l): array => ['locale' => $l->locale, 'is_default' => (bool) $l->is_default, 'is_required' => (bool) $l->is_required, 'fallback_locale' => $l->fallback_locale])->values()->all(),
                'domains' => $site === null ? [] : $site->domains->map(static fn ($d): array => ['id' => $d->id, 'host' => $d->host, 'kind' => $d->kind, 'is_primary' => (bool) $d->is_primary])->values()->all(),
                'modules' => $moduleState,
                'media' => ['alt_required' => $site !== null && app(Settings::class)->get('media.alt_required', false, $site) === true],
            ],
            'modules' => array_map(static fn (array $m): array => ['handle' => $m['handle'], 'label' => __($m['label']), 'switchable' => $m['switchable']], $modules),
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::EUROPE),
            'themes' => $themes,
            'available_locales' => ['de', 'en', 'it', 'fr', 'rm', 'es', 'pt', 'nl'],
            'submit_url' => $site === null ? route('basalto.sites.store') : route('basalto.sites.update', $site),
            'method' => $site === null ? 'post' : 'put',
            'back_url' => route('basalto.sites.index'),
            'export_url' => $this->exportUrl($site),
            'title' => $site === null ? __('Site anlegen') : __('Site «:name»', ['name' => $site->name]),
            // TB 12 Scheibe 1: Auf der SaaS gehören Alias und Domains dem Konto (Konto › Domains) — hier nur lesen
            'managed_domains_url' => Saas::enabled() ? route('basalto.saas.account.domains') : null,
            // TB 13 Scheibe 4 (M-25): Anmeldung über SSO — eigene Seite (Secret ohne Autosave), nur für Betreiber und Konto-Verwaltung
            'sso_url' => $site !== null && Gate::allows('manageSso', $site) ? route('basalto.sites.sso', $site) : null,
            'social_url' => $site !== null && Gate::allows('manageSso', $site) ? route('basalto.sites.social', $site) : null,
        ]);
    }

    /** «Export herunterladen» (M-18) — nur für Betreiber und Konto-Verwaltung, nur beim Bearbeiten. */
    private function exportUrl(?Site $site): ?string
    {
        $user = request()->user();

        return $site !== null && $user !== null && SiteExportController::allows($user, $site) ? route('basalto.sites.export', $site) : null;
    }

    /** @return array<string, mixed> */
    private function attributes(SiteRequest $request): array
    {
        $attributes = ['name' => $request->string('name')->toString(), 'handle' => $request->string('handle')->toString(), 'default_locale' => $request->string('default_locale')->toString(),
            'timezone' => $request->string('timezone')->toString(), 'theme' => $request->filled('theme') ? $request->string('theme')->toString() : null];
        // TB 12 Scheibe 1: Auf der SaaS ist der Handle der Alias — er ändert sich nur über Konto › Domains (30 Tage Umleitung)
        if (Saas::enabled()) {
            unset($attributes['handle']);
        }

        return $attributes;
    }

    private function syncRelations(Site $site, SiteRequest $request): void
    {
        // Alt-Text-Pflicht (K-10) ist eine Einstellung mit Verlauf (K-07) — die Veröffentlichung prüft sie
        $user = $request->user();
        app(Settings::class)->set('media.alt_required', $request->boolean('media.alt_required'), $site, $user instanceof Authenticatable ? $user : null);
        $default = $request->string('default_locale')->toString();
        $keep = [];
        foreach (array_values((array) $request->input('locales', [])) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) $row['locale'];
            $keep[] = $code;
            $site->locales()->updateOrCreate(['locale' => $code], [
                'is_default' => $code === $default,
                'is_required' => $code === $default || (bool) ($row['is_required'] ?? false),
                'fallback_locale' => $code === $default ? null : (($row['fallback_locale'] ?? null) ?: $default),
                'sort_order' => $i,
            ]);
        }
        $site->locales()->whereNotIn('locale', $keep)->delete();

        // TB 12 Scheibe 1: Auf der SaaS legt nur das Konto Domains an (Alias sofort, eigene Domain über die Cloud-CLI)
        if (! Saas::enabled()) {
            $this->syncDomains($site, $request);
        }

        foreach ((array) $request->input('modules', []) as $handle => $enabled) {
            $site->setModuleEnabled((string) $handle, (bool) $enabled);
        }
        $site->save();
    }

    private function syncDomains(Site $site, SiteRequest $request): void
    {
        $hosts = [];
        $rows = array_values((array) $request->input('domains', []));
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $host = strtolower((string) $row['host']);
            $hosts[] = $host;
            $site->domains()->updateOrCreate(['host' => $host], ['kind' => (string) $row['kind'], 'is_primary' => (bool) ($row['is_primary'] ?? ($i === 0))]);
        }
        $site->domains()->whereNotIn('host', $hosts)->delete();
    }

    private function accountFor(Authenticatable $user): Account
    {
        $accountId = DB::table('account_user')->where('user_id', $user->getAuthIdentifier())->whereIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])->orderBy('account_id')->value('account_id');

        return (is_numeric($accountId) ? Account::query()->whereKey((int) $accountId)->first() : null) ?? Account::installation();
    }

    private function done(SiteRequest $request, Site $site, string $status): RedirectResponse
    {
        return $request->boolean('close')
            ? redirect()->route('basalto.sites.index')->with('status', $status)
            : redirect()->route('basalto.sites.edit', $site)->with('status', $status);
    }
}
