<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cloud\CloudCli;
use Basalto\Core\Cloud\CloudCliException;
use Basalto\Core\Http\Middleware\ResolveSiteFromHost;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountNotice;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Events\ExtraDomainRequested;
use Basalto\Core\Saas\Notifications\CustomDomainLiveNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Domains einer SaaS-Site (TB 12 Scheibe 3, G-11, Feasibility «SaaS-Domains» D-01 … D-06): Alias ändern, eigene
 * Kundendomain hinzufügen, prüfen, zur Hauptadresse machen und entfernen — **nur automatisch** über die Cloud-CLI
 * ({@see CloudCli}, Naht `CommandRunner`).
 *
 * - **Alias ändern:** der neue Alias gilt sofort (Wildcard `*.<alias-domain>`, kein Cloud-Aufruf) und wird zugleich der
 *   Handle der Site; der alte bleibt als Zeile der Art `redirect` mit `redirect_until` (30 Tage) und leitet per 301 auf
 *   die Hauptadresse. Danach gilt er nicht mehr, und `basalto:domains-verify` räumt ihn weg — der Name ist frei.
 *   Begründung `site_domains` statt `redirects`: Die Tabelle `redirects` leitet Pfade innerhalb einer Site um, der alte
 *   Alias ist ein Host. In `site_domains` bleibt er belegt ({@see AliasCheck::isFree()}), ohne zweite Prüfung.
 * - **Eigene Domain:** eine Eingabe; bei einer Domain zweiter Stufe (`mueller.ch`) entstehen Apex und `www` (D-03, zählt
 *   als eine Domain). Den Cloud-Eintrag trägt die Zeile ohne `pair_id` (`domain:create --name=<apex>`); `www` hängt
 *   über `pair_id` daran. Erst die Cloud, dann die Datenbank: scheitert die CLI, bleibt nichts zurück (kein halber
 *   Zustand); scheitert danach die Datenbank, wird der Cloud-Eintrag wieder gelöscht.
 * - **Prüfung:** `domain:verify` (Scheduler alle zehn Minuten oder Knopf «Jetzt prüfen»). Ist sie verbunden und die
 *   Hauptadresse noch der Alias, wird die Kundendomain Hauptadresse (`www` vor Apex) — der Alias leitet ab dann per 301
 *   dorthin um (Pfad und Abfrage bleiben, {@see ResolveSiteFromHost}); Besitzer und
 *   Verwaltung bekommen eine Mail, einmal je Domain.
 * - **Entfernen:** erst `domain:delete` in der Cloud, dann die Zeilen; war sie Hauptadresse, übernimmt eine andere
 *   verbundene Kundendomain oder wieder der Alias.
 *
 * @phpstan-type DnsRecord array{type: string, name: string, value: string, found: bool|null}
 */
final class Domains
{
    /** So lange leitet ein alter Alias um (D-02). */
    public const REDIRECT_DAYS = 30;

    public const WAITING = 'waiting';

    public const CERTIFICATE = 'certificate';

    public const ACTIVE = 'active';

    public const ERROR = 'error';

    /** Art der Mail «Domain ist live» — einmal je Host ({@see AccountNotice}). */
    public const NOTICE_LIVE = 'domain.live';

    public function __construct(private readonly CloudCli $cloud, private readonly Recorder $recorder) {}

    /** Die Hauptadresse einer Site, sofern verbunden (der Alias ist es ab dem Anlegen). */
    public static function primary(Site $site): ?SiteDomain
    {
        return $site->domains()->where('is_primary', true)->whereNotNull('verified_at')->first();
    }

    /** Adresse eines Hosts mit dem Schema der Anwendung (lokal http, sonst https) — wie {@see Saas::aliasUrl()}. */
    public static function url(string $host): string
    {
        $scheme = (self::isAliasZone($host) ? LocalAliasFolders::scheme() : null) ?? parse_url((string) config('app.url'), PHP_URL_SCHEME);

        return (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.$host;
    }

    /**
     * Eingabe → Hosts: `https://www.Mueller.ch/kontakt` ergibt Apex `mueller.ch` (Cloud-Eintrag) und `www.mueller.ch`;
     * eine Subdomain (`shop.mueller.ch`) bleibt ein einzelner Host. Null bei ungültiger Eingabe.
     *
     * @return array{main: string, pair: string|null}|null
     */
    public static function parse(string $input): ?array
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = (string) preg_replace('#[/?\#].*$#', '', $host);
        $host = rtrim((string) preg_replace('/:\d+$/', '', $host), '.');
        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) ? $ascii : $host;
        }
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host) !== 1) {
            return null;
        }
        $base = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if (substr_count($base, '.') === 1) {
            return ['main' => $base, 'pair' => 'www.'.$base];
        }

        return ['main' => $host, 'pair' => null];
    }

    /** Ein Host unter der Alias-Zone (`basaltocms.com` und `*.basaltocms.com`) ist nie eine Kundendomain. */
    public static function isAliasZone(string $host): bool
    {
        $zone = strtolower(Saas::aliasDomain());

        return $host === $zone || str_ends_with($host, '.'.$zone);
    }

    // ───────────────────────────── Alias ─────────────────────────────

    /** Neuer Alias sofort aktiv, der alte leitet 30 Tage um. Zurück auf einen eigenen alten Alias geht ohne Prüfung. */
    public function changeAlias(Site $site, string $alias, ?Authenticatable $user = null): SiteDomain
    {
        $alias = strtolower(trim($alias));
        $host = $alias.'.'.Saas::aliasDomain();
        $current = $site->domains()->where('kind', SiteDomain::KIND_ALIAS)->first();
        if ($current !== null && $current->host === $host) {
            throw ValidationException::withMessages(['alias' => __(':host ist schon der Alias dieser Site.', ['host' => $host])]);
        }
        $own = $site->domains()->where('host', $host)->where('kind', SiteDomain::KIND_REDIRECT)->first();
        if ($own === null || $own->isExpired()) {
            $check = AliasCheck::check($alias, $site->name);
            if ($check['state'] !== AliasCheck::FREE) {
                throw ValidationException::withMessages(['alias' => $check['message']]);
            }
        }
        $before = $current?->host;
        try {
            $domain = DB::transaction(function () use ($site, $current, $own, $host, $alias): SiteDomain {
                $wasPrimary = $current === null || $current->is_primary;
                $current?->forceFill(['kind' => SiteDomain::KIND_REDIRECT, 'is_primary' => false, 'redirect_until' => now()->addDays(self::REDIRECT_DAYS)])->save();
                $attributes = ['kind' => SiteDomain::KIND_ALIAS, 'is_primary' => $wasPrimary, 'verified_at' => now(), 'redirect_until' => null, 'last_error' => null];
                if ($own !== null) {
                    $own->forceFill($attributes)->save();
                }
                $domain = $own ?? $site->domains()->create(['host' => $host] + $attributes);
                $site->forceFill(['handle' => $alias])->save();

                return $domain;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['alias' => __(':alias ist schon vergeben.', ['alias' => $alias])]);
        }
        LocalAliasFolders::link($alias);
        $this->recorder->record('site.alias_changed', $domain, ['alias' => $before], ['alias' => $host, 'umleitung_bis' => now()->addDays(self::REDIRECT_DAYS)->toDateString()], $site, $user);

        return $domain;
    }

    /** Abgelaufene Umleitungen alter Aliasse löschen — danach ist der Name frei. */
    public static function releaseExpiredAliases(): int
    {
        $released = SiteDomain::query()->where('kind', SiteDomain::KIND_REDIRECT)->whereNotNull('redirect_until')->where('redirect_until', '<', now())->delete();
        LocalAliasFolders::prune();

        return $released;
    }

    // ───────────────────────────── Eigene Domain ─────────────────────────────

    /**
     * Eigene Domain anlegen: Cloud zuerst (`domain:create`, bei Bedarf `domain:get` für die DNS-Einträge), dann die
     * Zeilen. Gibt die Zeile mit dem Cloud-Eintrag zurück.
     *
     * @throws ValidationException ungültig, belegt oder die Cloud hat abgelehnt (dann ist nichts gespeichert)
     */
    public function add(Site $site, string $input, ?Authenticatable $user = null): SiteDomain
    {
        $hosts = self::parse($input);
        if ($hosts === null) {
            throw ValidationException::withMessages(['host' => __('Bitte eine Domain wie «mueller-garten.ch» eingeben — ohne https:// und ohne Pfad.')]);
        }
        $all = array_values(array_filter([$hosts['main'], $hosts['pair']]));
        foreach ($all as $host) {
            if (self::isAliasZone($host)) {
                throw ValidationException::withMessages(['host' => __('Adressen unter :zone sind Aliasse — den Alias ändern Sie oben.', ['zone' => Saas::aliasDomain()])]);
            }
            $taken = SiteDomain::query()->where('host', $host)->first();
            if ($taken !== null) {
                throw ValidationException::withMessages(['host' => $taken->site_id === $site->id
                    ? __(':host ist bei dieser Site schon eingetragen.', ['host' => $host])
                    : __(':host ist schon bei einer anderen Site eingetragen. Gehört die Domain Ihnen, schreiben Sie uns.', ['host' => $host])]);
            }
        }

        try {
            $response = $this->cloud->domainCreate($hosts['main']);
        } catch (CloudCliException $e) {
            report($e);
            throw ValidationException::withMessages(['host' => __('Die Domain konnte nicht angelegt werden — die Cloud meldet: :message. Es wurde nichts gespeichert; versuchen Sie es später noch einmal.', ['message' => self::short($e->getMessage())])]);
        }
        $cloudId = isset($response['id']) && is_scalar($response['id']) ? (string) $response['id'] : null;
        if ($cloudId !== null && self::records($response) === []) {
            try {
                $response = array_replace($response, $this->cloud->domainGet($cloudId));
            } catch (CloudCliException $e) {
                report($e);
            }
        }

        try {
            $main = DB::transaction(static function () use ($site, $hosts, $response, $cloudId): SiteDomain {
                $main = $site->domains()->create(['host' => $hosts['main'], 'kind' => SiteDomain::KIND_CUSTOM, 'is_primary' => false, 'cloud_domain_id' => $cloudId,
                    'cloud_status' => isset($response['status']) && is_scalar($response['status']) ? strtolower((string) $response['status']) : 'pending', 'cloud_response' => $response]);
                if ($hosts['pair'] !== null) {
                    $site->domains()->create(['host' => $hosts['pair'], 'kind' => SiteDomain::KIND_CUSTOM, 'is_primary' => false, 'pair_id' => $main->id]);
                }

                return $main;
            });
        } catch (Throwable $e) {
            // Die Datenbank hat abgelehnt (z. B. gleichzeitig angelegt) — den Cloud-Eintrag wieder löschen
            if ($cloudId !== null) {
                try {
                    $this->cloud->domainDelete($cloudId);
                } catch (CloudCliException $cleanup) {
                    report($cleanup);
                }
            }
            if ($e instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['host' => __(':host ist schon eingetragen.', ['host' => $hosts['main']])]);
            }
            throw $e;
        }
        $this->recorder->record('site.domain_added', $main, null, ['hosts' => $all, 'cloud' => $cloudId], $site, $user);

        return $main;
    }

    /**
     * Über die Cloud prüfen (`domain:verify`). Verbunden → `verified_at` (mit `www`/Apex), Hauptadresse, Mail.
     * Ein Fehler der CLI bleibt an der Domain (`last_error`, im Reiter sichtbar) und wird weitergeworfen.
     *
     * @throws CloudCliException
     */
    public function verify(SiteDomain $domain): SiteDomain
    {
        $domain = self::main($domain);
        if ($domain->cloud_domain_id === null || $domain->isVerified()) {
            return $domain;
        }
        try {
            $result = $this->cloud->domainVerify($domain->cloud_domain_id);
        } catch (CloudCliException $e) {
            $domain->forceFill(['last_error' => $e->getMessage(), 'checked_at' => now()])->save();

            throw $e;
        }
        $status = isset($result['status']) && is_scalar($result['status']) ? strtolower((string) $result['status']) : 'verifying';
        $connected = in_array($status, ['connected', 'verified', 'active'], true);
        // Die DNS-Einträge vom Anlegen behalten, wenn die Prüfung keine mitliefert
        $previous = is_array($domain->cloud_response) ? $domain->cloud_response : [];
        $response = self::records($result) === [] && self::records($previous) !== [] ? $result + ['dnsRecords' => self::records($previous)] : $result;
        $domain->forceFill(['cloud_status' => $status, 'cloud_response' => $response, 'checked_at' => now(), 'last_error' => null,
            'verified_at' => $connected ? now() : null])->save();
        if ($connected) {
            $this->connected($domain);
        }

        return $domain;
    }

    /** Eine verbundene Kundendomain (oder der Alias) wird Hauptadresse; die übrigen Hosts leiten dorthin um. */
    public function makePrimary(SiteDomain $domain, ?Authenticatable $user = null): void
    {
        if (! $domain->isVerified() || ! in_array($domain->kind, [SiteDomain::KIND_ALIAS, SiteDomain::KIND_CUSTOM], true)) {
            throw ValidationException::withMessages(['domain' => __(':host ist noch nicht verbunden.', ['host' => $domain->host])]);
        }
        $site = $domain->site;
        $before = self::primary($site)?->host;
        self::setPrimary($site, $domain);
        $this->recorder->record('site.domain_primary', $domain, ['primaer' => $before], ['primaer' => $domain->host], $site, $user);
    }

    /**
     * Entfernen: Cloud zuerst (`domain:delete`), dann die Zeilen. Scheitert die CLI, bleibt alles, wie es war.
     *
     * @throws ValidationException
     */
    public function remove(SiteDomain $domain, ?Authenticatable $user = null): void
    {
        $domain = self::main($domain);
        if ($domain->kind !== SiteDomain::KIND_CUSTOM) {
            throw ValidationException::withMessages(['domain' => __('Nur eigene Domains lassen sich entfernen.')]);
        }
        if ($domain->cloud_domain_id !== null) {
            try {
                $this->cloud->domainDelete($domain->cloud_domain_id);
            } catch (CloudCliException $e) {
                report($e);
                $domain->forceFill(['last_error' => $e->getMessage()])->save();
                throw ValidationException::withMessages(['domain' => __(':host konnte nicht entfernt werden — die Cloud meldet: :message. Die Domain bleibt, wie sie war.', ['host' => $domain->host, 'message' => self::short($e->getMessage())])]);
            }
        }
        $site = $domain->site;
        $hosts = [$domain->host, ...SiteDomain::query()->where('pair_id', $domain->id)->pluck('host')->all()];
        DB::transaction(static function () use ($site, $domain): void {
            $group = self::group($domain);
            $wasPrimary = (clone $group)->where('is_primary', true)->exists();
            $group->delete();
            if ($wasPrimary) {
                $next = $site->domains()->where('kind', SiteDomain::KIND_CUSTOM)->whereNotNull('verified_at')->orderByRaw("case when host like 'www.%' then 0 else 1 end")->orderBy('id')->first()
                    ?? $site->domains()->where('kind', SiteDomain::KIND_ALIAS)->first();
                if ($next !== null) {
                    self::setPrimary($site, $next);
                }
            }
        });
        $this->recorder->record('site.domain_removed', $site, ['hosts' => $hosts], null, $site, $user);
    }

    // ───────────────────────────── Kontingent ─────────────────────────────

    /**
     * Eigene Domains des Kontos gegen das Kontingent (D-04: Start 0 · Standard 1 · Pro 5): im Plan, als Zusatz gebucht
     * (`billing.extra_domains`, bucht Scheibe 2) und bestellt, aber noch nicht gebucht (`billing.extra_domain_orders`).
     *
     * @return array{included: int, extra: int, ordered: int, limit: int, used: int}
     */
    public static function quota(Account $account): array
    {
        $plan = Plan::find($account->plan) ?? Plan::find(Account::PLAN_TRIAL);
        $extra = max(0, (int) ($account->billing['extra_domains'] ?? 0));
        $ordered = count(self::orders($account));
        $row = collect(Usage::of($account))->firstWhere('key', Usage::DOMAINS);
        $included = $plan->domains ?? 0;

        return ['included' => $included, 'extra' => $extra, 'ordered' => $ordered, 'limit' => $included + $extra + $ordered, 'used' => (int) ($row['used'] ?? 0)];
    }

    /** Eine weitere Domain übersteigt das Kontingent — dann gehört eine Bestellung «Zusatzdomain» dazu (statt Sperre). */
    public static function needsExtra(Account $account): bool
    {
        $quota = self::quota($account);

        return $quota['used'] >= $quota['limit'];
    }

    /**
     * Offene Bestellungen einer Zusatzdomain — Schnittstelle zu Scheibe 2 (Abo): Sie bucht den Zusatz (Menge im Abo),
     * zählt `billing.extra_domains` hoch und nimmt die Bestellung aus dieser Liste.
     *
     * @return list<array{host: string, site_id: int, user_id: int|null, requested_at: string}>
     */
    public static function orders(Account $account): array
    {
        $orders = $account->billing['extra_domain_orders'] ?? [];
        $list = [];
        foreach (is_array($orders) ? $orders : [] as $order) {
            if (is_array($order) && is_string($order['host'] ?? null)) {
                $list[] = ['host' => $order['host'], 'site_id' => (int) ($order['site_id'] ?? 0), 'user_id' => isset($order['user_id']) ? (int) $order['user_id'] : null,
                    'requested_at' => (string) ($order['requested_at'] ?? '')];
            }
        }

        return $list;
    }

    /** Zusatzdomain bestellen: in `billing.extra_domain_orders` festhalten und {@see ExtraDomainRequested} auslösen. */
    public function orderExtraDomain(Account $account, Site $site, string $host, ?Authenticatable $user = null): void
    {
        $order = ['host' => $host, 'site_id' => $site->id, 'user_id' => $user !== null ? (int) $user->getAuthIdentifier() : null, 'requested_at' => now()->toIso8601String()];
        DB::transaction(static function () use ($account, $order): void {
            $fresh = Account::query()->lockForUpdate()->findOrFail($account->id);
            $billing = $fresh->billing ?? [];
            $billing['extra_domain_orders'] = [...self::orders($fresh), $order];
            $fresh->forceFill(['billing' => $billing])->save();
            $account->setRawAttributes($fresh->getAttributes(), true);
        });
        $this->recorder->record('account.extra_domain_ordered', $account, null, ['host' => $host, 'preis' => (int) config('basalto.saas.extra_domain_price', 5)], $site, $user);
        event(new ExtraDomainRequested($account, $site, $host, $user !== null ? (int) $user->getAuthIdentifier() : null));
    }

    // ───────────────────────────── Anzeige ─────────────────────────────

    /** Stand für den Reiter: wartet auf DNS, Zertifikat, aktiv oder Fehler (mit `last_error`). */
    public static function state(SiteDomain $domain): string
    {
        $domain = self::main($domain);
        if ($domain->last_error !== null && $domain->last_error !== '') {
            return self::ERROR;
        }
        if ($domain->isVerified()) {
            return self::ACTIVE;
        }
        $status = strtolower((string) $domain->cloud_status);
        if (preg_match('/fail|error|invalid|reject/', $status) === 1) {
            return self::ERROR;
        }
        if (preg_match('/ssl|cert|tls|issu|provision/', $status) === 1) {
            return self::CERTIFICATE;
        }

        return self::WAITING;
    }

    /**
     * Die DNS-Einträge aus der Antwort der Cloud-CLI — wörtlich übernommen (Mockup 23); die Feldnamen werden beim
     * Live-Nachweis (Prüfpunkt 2) festgezurrt, deshalb mehrere Schreibweisen.
     *
     * @param  array<array-key, mixed>|null  $response
     * @return list<DnsRecord>
     */
    public static function records(?array $response): array
    {
        if ($response === null) {
            return [];
        }
        $list = null;
        foreach ([$response, is_array($response['data'] ?? null) ? $response['data'] : []] as $source) {
            foreach (['dnsRecords', 'dns_records', 'records', 'dns'] as $key) {
                if (is_array($source[$key] ?? null) && array_is_list($source[$key])) {
                    $list = $source[$key];
                    break 2;
                }
            }
        }
        $records = [];
        foreach ($list ?? [] as $record) {
            if (! is_array($record)) {
                continue;
            }
            $type = self::first($record, ['type', 'recordType']);
            $name = self::first($record, ['name', 'host', 'hostname']);
            $value = self::first($record, ['value', 'content', 'target', 'data']);
            if ($type === null || $name === null || $value === null) {
                continue;
            }
            $found = null;
            foreach (['found', 'verified', 'valid'] as $key) {
                if (is_bool($record[$key] ?? null)) {
                    $found = $record[$key];
                }
            }
            if ($found === null && is_string($record['status'] ?? null)) {
                $found = in_array(strtolower($record['status']), ['verified', 'valid', 'found', 'active', 'connected', 'ok'], true);
            }
            $records[] = ['type' => strtoupper($type), 'name' => $name, 'value' => $value, 'found' => $found];
        }

        return $records;
    }

    /** Die Zeile mit dem Cloud-Eintrag (bei `www` bzw. Apex eines Paars die andere). */
    public static function main(SiteDomain $domain): SiteDomain
    {
        if ($domain->pair_id === null) {
            return $domain;
        }

        return SiteDomain::query()->find($domain->pair_id) ?? $domain;
    }

    // ───────────────────────────── intern ─────────────────────────────

    /** Verbunden: das Paar mit, Hauptadresse (solange es der Alias ist), Protokoll, Mail an Besitzer und Verwaltung. */
    private function connected(SiteDomain $domain): void
    {
        $site = $domain->site;
        $group = self::group($domain)->get();
        SiteDomain::query()->where('pair_id', $domain->id)->whereNull('verified_at')->get()->each(static fn (SiteDomain $d) => $d->forceFill(['verified_at' => now()])->save());
        $primary = self::primary($site);
        if ($primary === null || $primary->kind !== SiteDomain::KIND_CUSTOM) {
            $preferred = $group->first(static fn (SiteDomain $d): bool => str_starts_with($d->host, 'www.')) ?? $domain;
            self::setPrimary($site, $preferred->refresh());
        }
        $this->recorder->record('site.domain_verified', $domain, null, ['host' => $domain->host, 'primaer' => self::primary($site)?->host], $site);
        $account = $site->account;
        if ($account instanceof Account) {
            $host = self::primary($site)->host ?? $domain->host;
            AccountNotice::once($account, self::NOTICE_LIVE, $domain->host, function () use ($account, $site, $host): void {
                $recipients = $account->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])->get();
                foreach ($recipients as $user) {
                    if ($user->getAttribute('deactivated_at') === null && method_exists($user, 'notify')) {
                        $user->notify(new CustomDomainLiveNotification($site->name, $host, self::url($host), route('basalto.saas.account.domains')));
                    }
                }
            });
        }
    }

    private static function setPrimary(Site $site, SiteDomain $domain): void
    {
        DB::transaction(static function () use ($site, $domain): void {
            $site->domains()->where('id', '!=', $domain->id)->where('is_primary', true)->get()->each(static fn (SiteDomain $d) => $d->forceFill(['is_primary' => false])->save());
            $domain->forceFill(['is_primary' => true])->save();
        });
    }

    /** @return Builder<SiteDomain> Die Zeile mit dem Cloud-Eintrag und ihr `www` bzw. Apex. */
    private static function group(SiteDomain $domain): Builder
    {
        return SiteDomain::query()->where(static fn (Builder $q) => $q->where('id', $domain->id)->orWhere('pair_id', $domain->id));
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @param  list<string>  $keys
     */
    private static function first(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_scalar($record[$key] ?? null) && (string) $record[$key] !== '') {
                return (string) $record[$key];
            }
        }

        return null;
    }

    /** Die Meldung der CLI gekürzt für die Oberfläche — die ganze steht im Fehlerprotokoll. */
    private static function short(string $message): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 180);
    }
}
