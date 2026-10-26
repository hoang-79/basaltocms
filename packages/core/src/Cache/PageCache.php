<?php

declare(strict_types=1);

namespace Basalto\Core\Cache;

use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Support\DevServer;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Seiten-Cache (M-16): das fertige HTML einer ausgelieferten Seite je Site, Host und Pfad. Statt Cache-Tags — die der
 * Datenbank-Store nicht kennt — trägt jeder Schlüssel die **Generation** der Site und den Fingerabdruck ihrer
 * Theme-Quelle: Jede Änderung, die eine Seite betreffen kann (Veröffentlichen, Seiteneinstellungen, Navigation,
 * Medien, Site, Einstellungen), zählt die Generation hoch, und alle Einträge der Site sind auf einen Schlag veraltet.
 * Weil Menüs auf jeder Seite stehen, ist «die ganze Site» die ehrliche Grenze. Abgelegt werden nur Arrays
 * (Status, Kopfzeilen, Inhalt), nie Objekte — Datei- und Datenbank-Store entpacken keine Objekte.
 *
 * Gecacht wird nur GET/HEAD ohne Abfrage und ohne angemeldete Person, Status 200, wenn Site und Seite es erlauben;
 * der Schlüssel trägt Schema, Host und Port, weil die Seite absolute Adressen enthält (kanonisch, hreflang, og:url).
 */
final class PageCache
{
    /** Interne Kopfzeile: die Auslieferung nennt die Lebensdauer; sie verlässt den Server nie. */
    public const TTL_HEADER = 'X-Basalto-Cache-Ttl';

    private const KEPT_HEADERS = ['Content-Type', 'Cache-Control', 'X-Robots-Tag', 'Content-Language'];

    public function __construct(private readonly ThemeLoader $themes, private readonly Settings $settings) {}

    public function enabled(Site $site): bool
    {
        return (bool) config('basalto.cache.pages', true) && $this->settings->get('cache.pages', true, $site) === true;
    }

    public function cacheable(Request $request, Site $site): bool
    {
        return ($request->isMethod('GET') || $request->isMethod('HEAD')) && $request->getQueryString() === null
            && $request->user() === null && ! self::signedInElsewhere($request) && ! $request->headers->has('Authorization') && ! self::viteRunning() && $this->enabled($site);
    }

    /**
     * TB 11 Scheibe 1: Ein Modul meldet beim Booten einen Guard an, dessen Anmeldung Antworten persönlich macht (z. B.
     * `customer`) — wer dort angemeldet ist, bekommt und füllt nie den Cache. In der Konfiguration, nicht statisch (Octane).
     */
    public static function personalGuard(string $guard): void
    {
        $guards = (array) config('basalto.cache.personal_guards', []);
        if (! in_array($guard, $guards, true)) {
            config()->set('basalto.cache.personal_guards', [...$guards, $guard]);
        }
    }

    private static function signedInElsewhere(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        foreach ((array) config('basalto.cache.personal_guards', []) as $guard) {
            if (is_string($guard) && config('auth.guards.'.$guard) !== null && auth()->guard($guard)->check()) {
                return true;
            }
        }

        return false;
    }

    /** Läuft der Vite-Dev-Server, tragen Seiten seinen Client (L-13) — solche Seiten gehören nie in den Cache. */
    private static function viteRunning(): bool
    {
        return DevServer::running();
    }

    /** Die gecachte Antwort oder null. */
    public function lookup(Request $request, Site $site): ?\Illuminate\Http\Response
    {
        if (! $this->cacheable($request, $site)) {
            return null;
        }
        $entry = $this->store()->get($this->key($request, $site));
        if (! is_array($entry) || ! is_string($entry['body'] ?? null)) {
            return null;
        }
        $response = response($entry['body'], (int) ($entry['status'] ?? 200));
        foreach (is_array($entry['headers'] ?? null) ? $entry['headers'] : [] as $name => $value) {
            $response->headers->set((string) $name, (string) $value);
        }
        $response->headers->set('X-Basalto-Cache', 'HIT');

        return $response;
    }

    /** Legt eine Antwort ab, wenn die Auslieferung eine Lebensdauer genannt hat; die interne Kopfzeile verschwindet immer. */
    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public function remember(Request $request, Site $site, Response $response): Response
    {
        $ttl = (int) $response->headers->get(self::TTL_HEADER, '0');
        $response->headers->remove(self::TTL_HEADER);
        if ($ttl <= 0 || $response->getStatusCode() !== 200 || ! $this->cacheable($request, $site)) {
            return $response;
        }
        $headers = [];
        foreach (self::KEPT_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }
        $this->store()->put($this->key($request, $site), ['status' => 200, 'headers' => $headers, 'body' => (string) $response->getContent()], $ttl);
        $response->headers->set('X-Basalto-Cache', 'MISS');

        return $response;
    }

    /** Die Lebensdauer einer Seite: höchstens die eingestellte, und nie über das Ende ihres Zeitfensters hinaus. */
    public function ttlFor(Site $site, ?int $secondsUntilHidden = null): int
    {
        $ttl = max(60, (int) $this->settings->get('cache.page_hours', 24, $site) * 3600);

        return $secondsUntilHidden === null ? $ttl : min($ttl, $secondsUntilHidden);
    }

    /** Alle Seiten der Site veralten lassen — die alten Einträge laufen über ihre Lebensdauer aus. */
    public function invalidate(int $siteId): void
    {
        $this->store()->forever(self::generationKey($siteId), bin2hex(random_bytes(6)));
    }

    public function generation(int $siteId): string
    {
        $store = $this->store();
        $generation = $store->get(self::generationKey($siteId));
        if (! is_string($generation)) {
            $generation = bin2hex(random_bytes(6));
            $store->forever(self::generationKey($siteId), $generation);
        }

        return $generation;
    }

    private function key(Request $request, Site $site): string
    {
        try {
            $theme = $this->themes->sourceFor($site)['source']->fingerprint();
        } catch (Throwable) {
            $theme = 'ohne-theme';
        }

        return sprintf('basalto.page.%d.%s.%s', $site->id, $this->generation($site->id), hash('xxh128', $theme.'|'.strtolower($request->getSchemeAndHttpHost()).'|'.$request->getPathInfo()));
    }

    private static function generationKey(int $siteId): string
    {
        return 'basalto.page.'.$siteId.'.generation';
    }

    private function store(): Repository
    {
        $store = config('basalto.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
