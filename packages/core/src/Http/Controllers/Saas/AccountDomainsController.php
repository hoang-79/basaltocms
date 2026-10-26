<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Backend\Dates;
use Basalto\Core\Cloud\CloudCliException;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\AccountContext;
use Basalto\Core\Saas\Domains;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\Usage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Konto › Domains (TB 12 Scheibe 3, G-11, Mockup 23 «Domains»): je Site der Alias (ändern, der alte leitet 30 Tage um)
 * und die eigenen Domains mit den DNS-Einträgen aus der Cloud-CLI, Stand (wartet auf DNS, Zertifikat, aktiv, Fehler),
 * «Jetzt prüfen» (einmal je Minute und Domain), «Zur Hauptadresse» und «Entfernen». Über dem Kontingent gehört zum
 * Hinzufügen die Bestellung einer Zusatzdomain ({@see Domains::orderExtraDomain()}) — keine Sperre; in der Testphase
 * gibt es noch keine eigene Domain (erst ein Plan, an dem der Zusatz hängen kann).
 *
 * Ändern dürfen Besitzer und Verwaltung des Kontos, bestellen Besitzer und Abrechnung; fremde Sites und Domains sind 404.
 */
final class AccountDomainsController
{
    /** Sekunden zwischen zwei Prüfungen derselben Domain über den Knopf. */
    public const CHECK_DECAY = 60;

    public function __invoke(Request $request, Dates $dates): Response
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        $quota = Domains::quota($account);
        $relative = $dates->relativeFormatter();

        return AccountPage::render($request, 'Domains', 'domains', [
            'sites' => $account->sites()->with('domains')->orderBy('id')->get()->map(fn (Site $site): array => $this->site($site, $dates, $relative))->values()->all(),
            'quota' => collect(Usage::of($account))->firstWhere('key', Usage::DOMAINS),
            'domain_quota' => $quota,
            'needs_extra' => Domains::needsExtra($account),
            'in_trial' => $account->plan === Account::PLAN_TRIAL,
            'can_manage' => $user !== null && AccountContext::manages($user, $account),
            'can_order' => $user !== null && AccountContext::bills($user, $account),
            'orders' => array_column(Domains::orders($account), 'host'),
            'alias_domain' => Saas::aliasDomain(),
            'extra_price' => (int) config('basalto.saas.extra_domain_price', 5),
            'urls' => ['alias_check' => route('basalto.saas.register.alias'), 'plan' => route('basalto.saas.account.plan'), 'billing' => route('basalto.saas.account.billing')],
        ]);
    }

    /** Alias ändern: sofort aktiv, der alte leitet 30 Tage um. */
    public function alias(Request $request, int $site, Domains $domains): RedirectResponse
    {
        [$account, $target, $user] = $this->managed($request, $site);
        $data = $request->validate(['alias' => ['required', 'string', 'max:40']]);
        $domain = $domains->changeAlias($target, (string) $data['alias'], $user);

        return back()->with('status', __('Alias geändert — :host ist sofort erreichbar; der alte leitet :days Tage um.', ['host' => $domain->host, 'days' => Domains::REDIRECT_DAYS]));
    }

    /** Eigene Domain hinzufügen — über dem Kontingent nur mit Bestellung einer Zusatzdomain (Besitzer, Abrechnung). */
    public function store(Request $request, int $site, Domains $domains): RedirectResponse
    {
        [$account, $target, $user] = $this->managed($request, $site);
        $data = $request->validate(['host' => ['required', 'string', 'max:253'], 'order_extra' => ['sometimes', 'boolean']]);
        $order = false;
        if (Domains::needsExtra($account)) {
            $order = $this->checkOrder($account, $user, (bool) ($data['order_extra'] ?? false));
        }
        $domain = $domains->add($target, (string) $data['host'], $user);
        if ($order) {
            $domains->orderExtraDomain($account, $target, $domain->host, $user);
        }

        return back()->with('status', $order
            ? __(':host ist angelegt und eine Zusatzdomain bestellt — tragen Sie jetzt die DNS-Einträge bei Ihrem Anbieter ein.', ['host' => $domain->host])
            : __(':host ist angelegt — tragen Sie jetzt die DNS-Einträge bei Ihrem Anbieter ein.', ['host' => $domain->host]));
    }

    /** «Jetzt prüfen» — einmal je Minute und Domain; sonst prüft der Scheduler alle zehn Minuten. */
    public function check(Request $request, int $site, int $domain, Domains $domains): RedirectResponse
    {
        [, $target] = $this->managed($request, $site);
        $row = Domains::main($this->domainOf($target, $domain));
        $key = 'basalto-saas-domain-check:'.$row->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->withErrors(['domain' => __('Gerade geprüft — die nächste Prüfung ist in :seconds Sekunden möglich.', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        RateLimiter::hit($key, self::CHECK_DECAY);
        try {
            $row = $domains->verify($row);
        } catch (CloudCliException) {
            return back()->withErrors(['domain' => __('Die Prüfung von :host ist gescheitert — die Meldung steht bei der Domain.', ['host' => $row->host])]);
        }

        return back()->with('status', match (Domains::state($row)) {
            Domains::ACTIVE => __(':host ist verbunden und aktiv.', ['host' => $row->host]),
            Domains::CERTIFICATE => __(':host: DNS gefunden — das Zertifikat wird ausgestellt.', ['host' => $row->host]),
            default => __(':host: Die DNS-Einträge sind noch nicht vollständig — wir prüfen alle 10 Minuten weiter.', ['host' => $row->host]),
        });
    }

    /** Eine verbundene Domain (oder wieder der Alias) wird Hauptadresse. */
    public function primary(Request $request, int $site, int $domain, Domains $domains): RedirectResponse
    {
        [, $target, $user] = $this->managed($request, $site);
        $row = $this->domainOf($target, $domain);
        $domains->makePrimary($row, $user);

        return back()->with('status', __(':host ist jetzt die Hauptadresse — die übrigen Adressen leiten dorthin um.', ['host' => $row->host]));
    }

    /** Eigene Domain entfernen: in der Cloud und hier; war sie Hauptadresse, wird es wieder der Alias. */
    public function destroy(Request $request, int $site, int $domain, Domains $domains): RedirectResponse
    {
        [, $target, $user] = $this->managed($request, $site);
        $row = Domains::main($this->domainOf($target, $domain));
        $domains->remove($row, $user);

        return back()->with('status', __(':host ist entfernt.', ['host' => $row->host]));
    }

    /**
     * Konto, Site des Kontos (sonst 404) und Benutzer mit Konto-Recht «verwalten» (sonst 403).
     *
     * @return array{0: Account, 1: Site, 2: Authenticatable}
     */
    private function managed(Request $request, int $siteId): array
    {
        $account = AccountContext::from($request);
        $site = AccountContext::site($account, $siteId);
        $user = $request->user();
        abort_unless($user !== null && AccountContext::manages($user, $account), 403);

        return [$account, $site, $user];
    }

    /** Eine Domain dieser Site — fremde sind 404. */
    private function domainOf(Site $site, int $domainId): SiteDomain
    {
        $domain = $site->domains()->whereKey($domainId)->first();
        abort_if($domain === null, 404);

        return $domain;
    }

    /** Über dem Kontingent: Testphase → erst ein Plan; sonst Bestellung bestätigt und von Besitzer oder Abrechnung. */
    private function checkOrder(Account $account, Authenticatable $user, bool $confirmed): bool
    {
        $quota = Domains::quota($account);
        if ($account->plan === Account::PLAN_TRIAL) {
            throw ValidationException::withMessages(['host' => __('In der Testphase gibt es noch keine eigene Domain — wählen Sie zuerst einen Plan (ab Standard ist eine Domain inklusive).')]);
        }
        if (! AccountContext::bills($user, $account)) {
            throw ValidationException::withMessages(['host' => trans_choice('Ihr Plan enthält :count eigene Domain, und sie ist belegt. Eine Zusatzdomain bestellen Besitzer oder Abrechnung des Kontos.|Ihr Plan enthält :count eigene Domains, und sie sind belegt. Eine Zusatzdomain bestellen Besitzer oder Abrechnung des Kontos.', $quota['limit'], ['count' => $quota['limit']])]);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['order_extra' => __('Bestätigen Sie die Zusatzdomain für CHF :price/Monat — oder wählen Sie einen grösseren Plan.', ['price' => (int) config('basalto.saas.extra_domain_price', 5)])]);
        }

        return true;
    }

    /**
     * @param  \Closure(mixed): ?string  $relative
     * @return array<string, mixed>
     */
    private function site(Site $site, Dates $dates, \Closure $relative): array
    {
        $all = $site->domains->sortBy('id');
        $alias = $all->firstWhere('kind', SiteDomain::KIND_ALIAS);
        $primary = $all->first(static fn (SiteDomain $d): bool => $d->is_primary && $d->isVerified());
        $custom = $all->where('kind', SiteDomain::KIND_CUSTOM)->whereNull('pair_id');

        return [
            'id' => $site->id,
            'name' => $site->name,
            'primary' => $primary?->host,
            'primary_url' => $primary !== null ? Domains::url($primary->host) : Saas::aliasUrl($site->handle),
            'alias' => $alias === null ? null : [
                'id' => $alias->id, 'host' => $alias->host, 'name' => substr($alias->host, 0, -strlen('.'.Saas::aliasDomain())), 'primary' => $alias->is_primary,
                'primary_url' => route('basalto.saas.account.domains.primary', ['site' => $site->id, 'domain' => $alias->id]),
            ],
            'old_aliases' => $all->where('kind', SiteDomain::KIND_REDIRECT)->filter(static fn (SiteDomain $d): bool => ! $d->isExpired())->map(static fn (SiteDomain $d): array => [
                'host' => $d->host, 'until' => $dates->date($d->redirect_until),
            ])->values()->all(),
            'domains' => $custom->map(function (SiteDomain $d) use ($all, $relative, $site): array {
                $pair = $all->firstWhere('pair_id', $d->id);
                $group = $pair instanceof SiteDomain ? [$d, $pair] : [$d];

                return [
                    'id' => $d->id,
                    'host' => $d->host,
                    'hosts' => array_map(static fn (SiteDomain $h): array => ['id' => $h->id, 'host' => $h->host, 'primary' => $h->is_primary && $h->isVerified(),
                        'primary_url' => route('basalto.saas.account.domains.primary', ['site' => $site->id, 'domain' => $h->id])], $group),
                    'state' => Domains::state($d),
                    'cloud_status' => $d->cloud_status,
                    'error' => $d->last_error,
                    'records' => Domains::records($d->cloud_response),
                    'checked' => $relative($d->checked_at),
                    'verified' => $d->isVerified(),
                    'urls' => [
                        'check' => route('basalto.saas.account.domains.check', ['site' => $site->id, 'domain' => $d->id]),
                        'destroy' => route('basalto.saas.account.domains.destroy', ['site' => $site->id, 'domain' => $d->id]),
                    ],
                ];
            })->values()->all(),
            'urls' => [
                'alias' => route('basalto.saas.account.domains.alias', ['site' => $site->id]),
                'store' => route('basalto.saas.account.domains.store', ['site' => $site->id]),
            ],
        ];
    }
}
