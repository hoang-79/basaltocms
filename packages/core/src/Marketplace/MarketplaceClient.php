<?php

declare(strict_types=1);

namespace Basalto\Core\Marketplace;

use Basalto\Core\Core;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteTheme;
use Basalto\Core\Theme\ThemeEditor;
use Basalto\Core\Theme\ThemeEditorException;
use Basalto\Core\Theme\ThemeLibrary;
use Basalto\Core\Theme\ThemePackage;
use Basalto\Core\Vendor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TB 11 Scheibe 2 (M-20): Der Marktplatz von basaltocms.com aus Sicht jeder Installation — der Katalog über die
 * öffentliche JSON-Schnittstelle (`GET <BASALTO_MARKETPLACE_URL>/marketplace/api/entries`, ohne Token, im Cache)
 * und «Theme übernehmen»: Paket laden, Prüfsumme und Signatur des Marktplatz-Schlüssels prüfen, dann als
 * Theme-Entwürfe einlesen (`ThemeEditor::import` — Vorschau und Freigabe wie jede Änderung). Adressen baut der Kern
 * selbst aus der konfigurierten URL; einer Adresse aus der Antwort folgt er nie (kein SSRF über den Katalog).
 *
 * TB 13 Scheibe 1: Bezahlte Themes lädt er mit E-Mail und Token des Kundenkontos auf basaltocms.com (HTTP-Basic wie
 * Composer) — aus `BASALTO_MARKETPLACE_EMAIL`/`BASALTO_MARKETPLACE_TOKEN` oder, wenn die fehlen, aus der `auth.json`
 * der Installation (`http-basic.<host>`, die Composer für Plugins ohnehin braucht). Die Zugangsdaten gehen nur an
 * den konfigurierten Host. 401 heisst: keine oder falsche Zugangsdaten, 403: kein Kauf oder Update-Jahr vorbei.
 *
 * @phpstan-type Entry array{handle: string, name: string, kind: string, title: string, summary: string|null, author: string, version: string, license: string, free: bool, price: int, currency: string|null, installs: int, requirements: array<string, string>, url: string|null, require: string|null, sha256: string|null, preview: string|null, category: string|null, badges: list<array{label: string, ok: bool|null}>, rating: float|null, rating_count: int}
 */
final class MarketplaceClient
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly ThemeEditor $editor, private readonly ThemeLibrary $library) {}

    public function baseUrl(): string
    {
        return rtrim((string) config('basalto.marketplace.url') ?: Vendor::url(), '/');
    }

    /** Host für `composer config http-basic.<host>` — mit Port, wenn einer dasteht (so vergleicht Composer). */
    public function host(): string
    {
        $port = parse_url($this->baseUrl(), PHP_URL_PORT);

        return (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: Vendor::host()).(is_int($port) ? ':'.$port : '');
    }

    /**
     * Der Katalog — aus dem Cache (Vorgabe zehn Minuten); ein Fehler bleibt eine Minute stehen, damit ein nicht
     * erreichbares basaltocms.com das Backend nicht bei jedem Aufruf aufhält.
     *
     * @return array{ok: bool, entries: list<Entry>, error: string|null, fetched_at: string|null}
     */
    public function catalog(bool $fresh = false): array
    {
        $key = 'basalto.marketplace.catalog.'.md5($this->baseUrl());
        $cached = $fresh ? null : Cache::get($key);
        if (is_array($cached) && isset($cached['ok'], $cached['entries'])) {
            /** @var array{ok: bool, entries: list<Entry>, error: string|null, fetched_at: string|null} $cached */
            return $cached;
        }
        $result = $this->fetch();
        Cache::put($key, $result, $result['ok'] ? max(1, (int) config('basalto.marketplace.cache_minutes', 10)) * 60 : 60);

        return $result;
    }

    /**
     * Ein Theme aus dem Marktplatz in die Bibliothek der Site (TB 22): neu als eigenes Theme — oder, wenn es schon aus
     * dem Marktplatz da ist, als Entwürfe zum Prüfen und Veröffentlichen (Update).
     *
     * @return array{drafts: int, name: string, signed: bool, theme: string, created: bool}
     *
     * @throws ThemeEditorException
     */
    public function installTheme(Site $site, string $name, string $version, Authenticatable $user): array
    {
        $entry = null;
        foreach ($this->catalog()['entries'] as $candidate) {
            if ($candidate['kind'] === 'theme' && $candidate['name'] === $name && $candidate['version'] === $version) {
                $entry = $candidate;
            }
        }
        if ($entry === null) {
            throw new ThemeEditorException(__('Dieses Theme gibt es im Marktplatz nicht (mehr) — Katalog neu laden.'));
        }
        $url = $this->baseUrl().'/marketplace/themes/'.rawurlencode($name).'/'.rawurlencode($version).'.zip';
        $path = (string) tempnam(sys_get_temp_dir(), 'basalto-theme-');
        // TB 25: E-Mail und Token gehen auch bei kostenlosen Themes mit (falls vorhanden) — der Marktplatz belegt so die Nutzung für Bewertungen
        $credentials = $this->credentials();
        if (! $entry['free'] && $credentials === null) {
            throw new ThemeEditorException(__('Bezahltes Theme: Basalto braucht E-Mail und Token Ihres Kundenkontos auf :host (BASALTO_MARKETPLACE_EMAIL und BASALTO_MARKETPLACE_TOKEN oder auth.json).', ['host' => $this->host()]));
        }
        try {
            try {
                $request = Http::timeout(30)->connectTimeout(5)->withUserAgent('Basalto/'.Core::VERSION)->sink($path);
                $response = ($credentials !== null ? $request->withBasicAuth($credentials[0], $credentials[1]) : $request)->get($url);
            } catch (ConnectionException) {
                throw new ThemeEditorException(__(':host ist nicht erreichbar.', ['host' => $this->host()]));
            }
            if ($response->status() === 401) {
                throw new ThemeEditorException(__('Der Marktplatz kennt E-Mail und Token nicht — Token im Autorenkonto auf :host prüfen.', ['host' => $this->host()]));
            }
            if ($response->status() === 403) {
                throw new ThemeEditorException(__('Kein Kauf dieses Themes mit Ihrem Kundenkonto — oder das Update-Jahr ist vorbei. Kaufen bzw. verlängern auf :host.', ['host' => $this->host()]));
            }
            if (! $response->successful()) {
                throw new ThemeEditorException(__('Der Marktplatz antwortet mit :status.', ['status' => $response->status()]));
            }
            if (! is_file($path) || filesize($path) === 0 || filesize($path) > self::MAX_BYTES) {
                throw new ThemeEditorException(__('Das Paket ist leer oder zu gross.'));
            }
            if ($entry['sha256'] !== null && ! hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
                throw new ThemeEditorException(__('Die Prüfsumme des Pakets passt nicht zum Katalog.'));
            }
            $verified = (new ThemePackage)->verify($path, (string) config('basalto.marketplace.public_key'));
            if (! $verified['ok'] || ! $verified['signed']) {
                throw new ThemeEditorException(__('Die Signatur des Marktplatzes fehlt oder passt nicht: :errors', ['errors' => implode(' · ', array_slice($verified['errors'], 0, 3))]));
            }

            $existing = SiteTheme::query()->where('site_id', $site->id)->where('origin', 'marktplatz')->where('origin_name', $name)->first();
            if ($existing !== null) {
                $result = $this->editor->import($existing, $path, $user);
                $existing->forceFill(['version' => $version])->save();

                return $result + ['theme' => $existing->name, 'created' => false];
            }
            $theme = $this->library->importPackage($site, $path, $user, 'marktplatz');

            return ['drafts' => 0, 'name' => $name, 'signed' => $theme->signed, 'theme' => $theme->name, 'created' => true];
        } finally {
            @unlink($path);
        }
    }

    /**
     * E-Mail und Token für den Marktplatz: aus der Konfiguration, sonst aus `auth.json` (`http-basic.<host>`).
     *
     * @return array{0: string, 1: string}|null
     */
    public function credentials(): ?array
    {
        $email = config('basalto.marketplace.email');
        $token = config('basalto.marketplace.token');
        if (is_string($email) && trim($email) !== '' && is_string($token) && trim($token) !== '') {
            return [trim($email), trim($token)];
        }
        $file = base_path('auth.json');
        $auth = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $basic = is_array($auth) && is_array($auth['http-basic'][$this->host()] ?? null) ? $auth['http-basic'][$this->host()] : null;
        if (is_array($basic) && is_string($basic['username'] ?? null) && is_string($basic['password'] ?? null) && $basic['username'] !== '' && $basic['password'] !== '') {
            return [$basic['username'], $basic['password']];
        }

        return null;
    }

    /** @return array{ok: bool, entries: list<Entry>, error: string|null, fetched_at: string|null} */
    private function fetch(): array
    {
        $fail = static fn (string $error): array => ['ok' => false, 'entries' => [], 'error' => $error, 'fetched_at' => now()->toIso8601String()];
        try {
            $response = Http::timeout(6)->connectTimeout(3)->acceptJson()->withUserAgent('Basalto/'.Core::VERSION)->get($this->baseUrl().'/marketplace/api/entries');
        } catch (ConnectionException) {
            return $fail((string) __(':host ist nicht erreichbar.', ['host' => $this->host()]));
        }
        if (! $response->successful()) {
            return $fail((string) __('Der Marktplatz antwortet mit :status.', ['status' => $response->status()]));
        }
        try {
            $data = $response->json('data');
        } catch (Throwable) {
            $data = null;
        }
        if (! is_array($data)) {
            return $fail((string) __('Die Antwort des Marktplatzes ist kein Katalog.'));
        }
        $entries = [];
        foreach ($data as $raw) {
            $entry = is_array($raw) ? self::entry($raw) : null;
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return ['ok' => true, 'entries' => $entries, 'error' => null, 'fetched_at' => now()->toIso8601String()];
    }

    /**
     * Nur bekannte Felder mit festen Typen — was basaltocms.com schickt, ist für die Installation fremd.
     *
     * @param  array<mixed>  $raw
     * @return Entry|null
     */
    private static function entry(array $raw): ?array
    {
        $string = static fn (mixed $v, int $max = 255): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $kind = $raw['kind'] ?? null;
        $name = $string($raw['name'] ?? null, 214);
        $version = $string($raw['version'] ?? null, 64);
        if (! in_array($kind, ['theme', 'plugin'], true) || $name === null || $version === null || preg_match('/^[0-9A-Za-z.+-]+$/', $version) !== 1) {
            return null;
        }
        $install = is_array($raw['install'] ?? null) ? $raw['install'] : [];
        $requirements = [];
        foreach (is_array($raw['requirements'] ?? null) ? $raw['requirements'] : [] as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $requirements[mb_substr($package, 0, 120)] = mb_substr($constraint, 0, 60);
            }
        }
        $url = $string($raw['url'] ?? null, 500);
        $sha = $string($install['sha256'] ?? null, 64);
        $require = $kind === 'plugin' ? 'composer require '.$name.':'.self::constraint($version) : null;

        return [
            'handle' => $string($raw['handle'] ?? null, 230) ?? $name, 'name' => $name, 'kind' => $kind, 'title' => $string($raw['title'] ?? null, 120) ?? $name,
            'summary' => $string($raw['summary'] ?? null), 'author' => $string(is_array($raw['author'] ?? null) ? ($raw['author']['name'] ?? null) : null, 120) ?? '—',
            'version' => $version, 'license' => $string($raw['license'] ?? null, 60) ?? '—', 'free' => ($raw['free'] ?? true) === true,
            'price' => max(0, (int) ($raw['price'] ?? 0)), 'currency' => in_array($raw['currency'] ?? null, ['CHF', 'EUR', 'USD'], true) ? $raw['currency'] : null,
            'installs' => max(0, (int) ($raw['installs'] ?? 0)),
            'requirements' => $requirements, 'url' => $url !== null && preg_match('#^https?://#i', $url) === 1 ? $url : null, 'require' => $require,
            'sha256' => $sha !== null && preg_match('/^[a-f0-9]{64}$/', $sha) === 1 ? $sha : null,
            // TB 24: Bild, Kategorie und Prüfstand aus dem Katalog
            'preview' => ($preview = $string($raw['preview'] ?? null, 500)) !== null && preg_match('#^https?://#i', $preview) === 1 ? $preview : null,
            'category' => $string($raw['category_label'] ?? null, 80),
            // TB 25: Bewertungen durch Käufer
            'rating' => is_numeric($raw['rating'] ?? null) ? max(0.0, min(5.0, (float) $raw['rating'])) : null, 'rating_count' => max(0, (int) ($raw['rating_count'] ?? 0)),
            'badges' => array_values(array_map(static fn (array $b): array => ['label' => mb_substr((string) $b['label'], 0, 80), 'ok' => is_bool($b['ok'] ?? null) ? $b['ok'] : null],
                array_filter(is_array($raw['badges'] ?? null) ? array_slice($raw['badges'], 0, 10) : [], static fn (mixed $b): bool => is_array($b) && is_string($b['label'] ?? null)))),
        ];
    }

    /** ^1.2 bzw. ^0.9 — wie der Marktplatz es vorschlägt. */
    public static function constraint(string $version): string
    {
        $parts = explode('.', explode('-', $version)[0]);

        return '^'.$parts[0].'.'.($parts[1] ?? '0');
    }
}
