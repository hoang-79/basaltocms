<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Hosting\Hostnames;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Domains;
use Basalto\Core\Saas\Offboarding\LockedSite;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frontend: Hostname → Site über `site_domains`; sonst die Standard-Site der Installation — ausser für Unterdomains, die
 * Basalto beim Hoster einrichtet (CR-017, {@see Hostnames::manages()}); sonst keine (der Controller antwortet) (K-01, G-11).
 *
 * TB 12 Scheibe 3 (G-11, nur im SaaS-Modus): Jeder Host einer Site, der nicht ihre Hauptadresse ist — der Alias neben
 * einer verbundenen Kundendomain, ein alter Alias in seinen 30 Tagen, `www` bzw. Apex des Paars, weitere Kundendomains —
 * leitet GET/HEAD per 301 auf die Hauptadresse um, Pfad und Abfrage bleiben. Ein abgelaufener alter Alias führt zu keiner
 * Site mehr. Alias-Hosts tragen immer `X-Robots-Tag: noindex` (Feasibility «SaaS-Domains» § 2: der Alias ist nur
 * Übergang, Suchmaschinen sollen die Kundendomain indexieren; Missbrauch der Alias-Zone trifft so keine Suchergebnisse).
 * Download-Installationen bleiben, wie sie sind: dort gilt `site.domain` aus Lava. Überall gilt: Ein Aufruf mit oder ohne
 * «www.», der keine eigene Domain der Site ist, leitet auf die Hauptdomain um (CR-019).
 */
final class ResolveSiteFromHost
{
    public function __construct(private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $domain = SiteDomain::query()->where('host', $host)->first();
        if ($domain !== null && $domain->isExpired()) {
            $domain = null;
        }
        $site = $domain?->site;
        // CR-017: Eine Unterdomain, die Basalto beim Hoster einrichtet, zeigt nie die Standard-Site — ohne eigene Site 404
        if ($site === null && ($default = config('basalto.default_site')) !== null && ! Hostnames::manages($host)) {
            $site = Site::query()->where('handle', $default)->first();
        }
        $this->current->set($site);
        if ($site !== null) {
            app()->setLocale($site->default_locale);
        }
        // TB 12 Scheibe 4 (M-21): Site eines gesperrten Kontos — neutrale Seite mit 410, keine Inhalte, keine Umleitung
        if (($gone = LockedSite::response($site)) !== null) {
            return $gone;
        }
        // CR-019 (Nachtrag): «www.» und ohne sind dieselbe Site — die Variante, die keine eigene Domain ist, leitet auf
        // die Hauptdomain um, sonst sieht eine Suchmaschine zwei Websites mit gleichem Inhalt
        if ($domain === null && $site !== null && ($target = $this->wwwTarget($request, $host, $site)) !== null) {
            return redirect()->away($target, 301);
        }
        if ($domain === null || $site === null || ! Saas::enabled()) {
            return $next($request);
        }
        $alias = in_array($domain->kind, [SiteDomain::KIND_ALIAS, SiteDomain::KIND_REDIRECT], true);
        $target = $this->redirectTarget($request, $domain, $site);
        $response = $target !== null ? redirect()->away($target, 301) : $next($request);
        if ($alias) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    /**
     * Ziel der Umleitung von `www.beispiel.ch` auf die Hauptadresse `beispiel.ch` (oder umgekehrt), wenn der aufgerufene
     * Host keine eigene Domain der Site ist — nur für GET/HEAD und nie unter dem Backend-Pfad. Ohne eigene Domain zeigt
     * der Aufruf die Standard-Site; deren Adresse steht in `APP_URL` (ohne Abfrage) oder als Hauptdomain der Site (eine
     * Abfrage, nur für Hosts mit «www.»).
     */
    private function wwwTarget(Request $request, string $host, Site $site): ?string
    {
        if (! ($request->isMethod('GET') || $request->isMethod('HEAD')) || $this->underBackend($request)) {
            return null;
        }
        // Nur Namen mit Punkt können ein www-Paar sein — localhost und IP-Adressen nie
        if (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $counterpart = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost === $counterpart) {
            return Domains::url($appHost).$request->getRequestUri();
        }
        if (! str_starts_with($host, 'www.')) {
            return null;
        }
        $primary = $site->domains()->where('is_primary', true)->value('host');

        return is_string($primary) && strtolower($primary) === $counterpart ? Domains::url($counterpart).$request->getRequestUri() : null;
    }

    private function underBackend(Request $request): bool
    {
        $backend = app(Backend::class)->path();

        return $request->is($backend) || $request->is($backend.'/*');
    }

    /** Ziel der Umleitung auf die Hauptadresse — nur für GET/HEAD und nie unter dem Backend-Pfad (Frontend-Editor). */
    private function redirectTarget(Request $request, SiteDomain $domain, Site $site): ?string
    {
        if (($domain->is_primary && $domain->isVerified()) || ! ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            return null;
        }
        if ($this->underBackend($request)) {
            return null;
        }
        $primary = Domains::primary($site);
        if ($primary === null || $primary->id === $domain->id) {
            return null;
        }

        return Domains::url($primary->host).$request->getRequestUri();
    }
}
