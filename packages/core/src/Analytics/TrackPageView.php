<?php

declare(strict_types=1);

namespace Basalto\Core\Analytics;

use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * TB 54: Zählt Seitenaufrufe des Frontends (wie Fristo) — als Middleware, die erst nach dem Senden der Antwort
 * schreibt, damit die Seite nicht langsamer wird. Gezählt werden GET-Aufrufe mit HTML und Status 200, auch aus dem
 * Seiten-Cache; nicht gezählt: Vorabladen des Browsers, angemeldete Backend-Benutzer (auch im Frontend-Editor) und Sites mit
 * ausgeschalteter Statistik. Es setzt keine Cookies (die Seite darf aus dem Seiten-Cache kommen) und speichert keine
 * IP-Adresse, keine Abfrage der Adresse und vom Verweis nur den Host.
 */
final class TrackPageView
{
    public const ALIAS = 'basalto.statistik';

    public function __construct(private readonly CurrentSite $current, private readonly Statistics $statistics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $site = $this->current->get();
        if ($site !== null) {
            $request->attributes->set('basalto.statistik.site', $site->id);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $siteId = $request->attributes->get('basalto.statistik.site');
            $site = is_int($siteId) ? $this->current->get() : null;
            if ($site === null || $site->id !== $siteId || ! $this->shouldTrack($request, $response) || ! $this->statistics->enabled($site)) {
                return;
            }
            $agent = UserAgentParser::parse($request->userAgent());
            $identify = Statistics::mayIdentify($request);
            DB::table('analytics_page_views')->insert([
                'site_id' => $site->id,
                'visitor_id' => $identify ? Statistics::identifier($request, Statistics::VISITOR_COOKIE) : '',
                'session_id' => $identify ? Statistics::identifier($request, Statistics::SESSION_COOKIE) : '',
                'customer_id' => $identify ? CustomerAuth::current()?->id : null,
                'path' => Str::limit('/'.ltrim($request->path(), '/'), 500, ''),
                'status' => $response->getStatusCode(),
                'response_time_ms' => defined('LARAVEL_START') ? max(0, (int) round((microtime(true) - LARAVEL_START) * 1000)) : null,
                'referrer_host' => $this->referrerHost($request),
                'utm_source' => $this->clean($request->query('utm_source')),
                'utm_medium' => $this->clean($request->query('utm_medium')),
                'utm_campaign' => $this->clean($request->query('utm_campaign')),
                'browser' => $agent['browser'],
                'os' => $agent['os'],
                'device_type' => $agent['device_type'],
                'is_bot' => $agent['is_bot'],
                'country' => self::country($request),
                'locale' => Str::limit(App::getLocale(), 12, ''),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Die Statistik darf eine Seite nie brechen
            report($e);
        }
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200 || $request->headers->has('X-Inertia')) {
            return false;
        }
        // Vorabladen des Browsers ist kein Besuch
        $purpose = strtolower((string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? ''));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return false;
        }
        if (! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return false;
        }

        // Wer im Backend angemeldet ist, pflegt die Site (auch im Frontend-Editor) — kein Besuch
        return ! ($request->hasSession() && Auth::guard('web')->check());
    }

    private function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');
        $host = is_string($referrer) ? parse_url($referrer, PHP_URL_HOST) : null;
        if (! is_string($host) || $host === '' || strcasecmp($host, $request->getHost()) === 0) {
            return null;
        }

        return Str::limit(strtolower($host), 255, '');
    }

    /** Land nur, wenn ein Proxy davor es mitgibt (Cloudflare, CloudFront) — keine Datenbank, keine Abfrage nach aussen. */
    public static function country(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Country-Code'] as $header) {
            $value = strtoupper((string) $request->headers->get($header));
            if (preg_match('/^[A-Z]{2}$/', $value) === 1 && ! in_array($value, ['XX', 'T1'], true)) {
                return $value;
            }
        }

        return null;
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 120, '') : null;
    }
}
