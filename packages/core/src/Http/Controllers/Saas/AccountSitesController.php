<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\AccountContext;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\SiteProvisioner;
use Basalto\Core\Saas\Usage;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Konto › Sites (TB 12 Scheibe 1, Mockup 23): die Sites des Kontos mit Alias, eigenen Domains, Speicher und Benutzern;
 * «Weitere Site» mit Alias im Rahmen des Kontingents (Besitzer und Verwaltung); «Zum Backend» wechselt auf die Site.
 */
final class AccountSitesController
{
    public function __construct(private readonly SiteProvisioner $sites) {}

    /** Verwaltung › Konto führt zum ersten Reiter. */
    public function start(): RedirectResponse
    {
        return redirect()->route('basalto.saas.account.sites');
    }

    public function index(Request $request): Response
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        $sites = $account->sites()->with(['domains', 'locales'])->withCount('users')->orderBy('id')->get();
        $storage = DB::table('media')->whereIn('site_id', $sites->pluck('id'))->groupBy('site_id')->selectRaw('site_id, sum(size) as bytes')->pluck('bytes', 'site_id');
        $current = app(CurrentSite::class)->get();

        return AccountPage::render($request, 'Sites', 'sites', [
            'sites' => $sites->map(static fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'handle' => $site->handle,
                'alias' => $site->domains->firstWhere('kind', SiteDomain::KIND_ALIAS)?->host,
                'url' => Saas::aliasUrl($site->handle),
                'custom_domains' => $site->domains->where('kind', SiteDomain::KIND_CUSTOM)->map(static fn (SiteDomain $d): array => ['host' => $d->host, 'verified' => $d->isVerified()])->values()->all(),
                'live' => $site->isLive(),
                'theme' => $site->theme,
                'locales' => $site->localeCodes(),
                'users' => (int) $site->getAttribute('users_count'),
                'storage' => (int) ($storage[$site->id] ?? 0),
                'current' => $current?->id === $site->id,
                'open_url' => route('basalto.saas.account.sites.open', $site->id),
            ])->values()->all(),
            'can_add' => Usage::canAddSite($account),
            'limit' => Usage::siteLimit($account),
            'themes' => $this->sites->themes(),
            'default_theme' => $this->sites->defaultTheme(),
            'default_timezone' => $sites->first()->timezone ?? 'Europe/Zurich',
            'locales' => RegistrationController::LOCALES,
            'alias_domain' => Saas::aliasDomain(),
            'extra_site_price' => (int) config('basalto.saas.extra_site_price', 15),
            'urls' => ['store' => route('basalto.saas.account.sites.store'), 'alias' => route('basalto.saas.register.alias'), 'plan' => route('basalto.saas.account.plan')],
            'can_create' => $user !== null && AccountContext::manages($user, $account),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        abort_unless($user !== null && AccountContext::manages($user, $account), 403);
        if (! Usage::canAddSite($account)) {
            throw ValidationException::withMessages(['name' => __('Ihr Plan enthält :count Site(s) — für eine weitere wählen Sie einen grösseren Plan oder eine Zusatz-Site.', ['count' => Usage::siteLimit($account)])]);
        }
        $data = $request->validate(RegistrationController::siteRules($this->sites));
        $timezone = (string) $data['timezone'];
        /** @var list<string> $locales */
        $locales = array_values(array_unique(array_map('strval', (array) $data['locales'])));
        $result = $this->sites->create($account, $user, (string) $data['name'], strtolower((string) $data['alias']), $locales, $timezone, (string) $data['theme']);
        $site = $result['site'];

        return redirect()->route('basalto.saas.account.sites')->with('status', $result['live']
            ? __('Site «:name» ist live unter :url.', ['name' => $site->name, 'url' => Saas::aliasUrl($site->handle)])
            : __('Site «:name» ist angelegt, aber noch nicht live: :reason', ['name' => $site->name, 'reason' => $result['errors'][0] ?? '—']));
    }

    /**
     * «Zum Backend»: auf eine Site des Kontos wechseln — fremde Sites sind 404. Besitzer und Verwaltung des Kontos, die
     * noch nicht Mitglied sind, werden Administrator der Site (ihr Konto-Recht umfasst sie ohnehin, SitePolicy).
     */
    public function open(Request $request, int $site, Recorder $recorder): RedirectResponse
    {
        $account = AccountContext::from($request);
        $target = AccountContext::site($account, $site);
        $user = $request->user();
        abort_if($user === null, 404);
        if ($target->roleOf($user) === null) {
            if (! AccountContext::manages($user, $account)) {
                return back()->withErrors(['site' => __('Sie sind nicht Mitglied dieser Site — die Verwaltung des Kontos lädt Sie ein.')]);
            }
            $target->users()->attach($user->getAuthIdentifier(), ['role' => SiteRole::Administrator->value]);
            $recorder->record('user.role_changed', $user, ['role' => null], ['role' => SiteRole::Administrator->value, 'via' => 'account'], $target);
        }
        $request->session()->put(SelectBackendSite::SESSION_KEY, $target->id);

        return redirect()->route('basalto.dashboard');
    }
}
