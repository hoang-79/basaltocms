<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Geänderte Adressen an Suchmaschinen melden (CR-019, TB 107, E-4) über **IndexNow** — das offene Protokoll von Bing,
 * Yandex, Seznam, Naver und Yep: Eine Meldung an `api.indexnow.org` erreicht alle. Google nimmt IndexNow nicht; Google
 * liest die Sitemap über `robots.txt`.
 *
 * Veröffentlichen, Offline nehmen und Archivieren legen die Adresse der Seite in jeder betroffenen Sprache als
 * «wartend» ab (`indexnow_urls`); der Zeitplan schickt sie alle zehn Minuten gesammelt je Site. Den Schlüssel erzeugt
 * Basalto selbst und liefert ihn unter `/<schlüssel>.txt` aus. Gemeldet wird nur in Produktion, nur für öffentliche
 * Sites (Robots erlaubt, live, keine Testumgebung, kein Test-Host) und nur, wenn die Site es nicht abgeschaltet hat.
 */
final class IndexNow
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Meldungen danach aus dem Protokoll. */
    public const KEEP_DAYS = 90;

    private const MAX_ATTEMPTS = 5;

    /** Adressen je Meldung (die Schnittstelle nimmt bis 10'000). */
    private const BATCH = 1000;

    public function __construct(private readonly Settings $settings, private readonly PublishSettings $publish, private readonly Pages $pages) {}

    /** Ob diese Site Änderungen meldet. */
    public function enabled(Site $site): bool
    {
        if (! (bool) $this->settings->get('seo.indexnow', true, $site)) {
            return false;
        }
        if (! app()->isProduction() && ! (bool) config('basalto.indexnow.always', false)) {
            return false;
        }

        return $site->isLive() && $this->publish->robotsAllowed($site) && self::publicHost($site);
    }

    /** Der Schlüssel der Site (32 Zeichen); beim ersten Gebrauch erzeugt und gespeichert. */
    public function key(Site $site): string
    {
        $key = $site->setting('seo.indexnow_key');
        if (is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key) === 1) {
            return $key;
        }
        $key = bin2hex(random_bytes(16));
        $settings = is_array($site->settings) ? $site->settings : [];
        data_set($settings, 'seo.indexnow_key', $key);
        $site->settings = $settings;
        $site->saveQuietly();

        return $key;
    }

    /** Antwort auf `/<schlüssel>.txt` — nur der eigene Schlüssel einer meldenden Site. */
    public function keyFile(Site $site, string $requested): ?string
    {
        $key = $site->setting('seo.indexnow_key');

        return is_string($key) && hash_equals($key, $requested) && $this->enabled($site) ? $key : null;
    }

    /**
     * Adressen zum Melden vormerken (doppelte wartende zählen einmal). Auch für Module: ein neues Thema im Forum.
     *
     * @param  list<string>  $urls  volle Adressen auf einem Host der Site
     */
    public function queue(Site $site, array $urls): int
    {
        if ($urls === [] || ! $this->enabled($site)) {
            return 0;
        }
        $added = 0;
        foreach (array_unique($urls) as $url) {
            if (mb_strlen($url) > 512) {
                continue;
            }
            $exists = DB::table('indexnow_urls')->where('site_id', $site->id)->where('url', $url)->where('status', self::PENDING)->exists();
            if (! $exists) {
                DB::table('indexnow_urls')->insert(['site_id' => $site->id, 'url' => $url, 'status' => self::PENDING, 'created_at' => now()]);
                $added++;
            }
        }

        return $added;
    }

    /** Eine Fassung wurde veröffentlicht, offline genommen oder archiviert: ihre Adresse vormerken. */
    public function versionChanged(EntryVersion $version): void
    {
        $published = EntryStatus::Published->value;
        $status = $version->status->value;
        $original = $version->getOriginal('status');
        $before = $original instanceof EntryStatus ? $original->value : (string) $original;
        if ($status !== $published && $before !== $published) {
            return;
        }
        $entry = Entry::query()->withoutGlobalScopes()->with('site')->find($version->entry_id);
        $site = $entry?->site;
        if ($entry === null || $site === null || ! $this->enabled($site)) {
            return;
        }
        $this->pages->forget();
        // Veröffentlicht: die neue Adresse; offline: die Adresse nach der neuesten Fassung (sie antwortet jetzt 404)
        $path = $this->pages->ownPathOf($entry, $version->locale) ?? $this->pages->pathOf($entry, $version->locale, false);
        if ($path !== null) {
            $this->queue($site, [rtrim($site->publicUrl(), '/').$this->pages->urlPath($site, $version->locale, $path)]);
        }
    }

    /** Wartende Adressen aller Sites senden; Rückgabe: gemeldete Adressen. */
    public function send(): int
    {
        DB::table('indexnow_urls')->where('status', '<>', self::PENDING)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        $sent = 0;
        foreach (DB::table('indexnow_urls')->where('status', self::PENDING)->distinct()->pluck('site_id') as $siteId) {
            $site = Site::query()->find((int) $siteId);
            if ($site === null) {
                continue;
            }
            if (! $this->enabled($site)) {
                DB::table('indexnow_urls')->where('site_id', $site->id)->where('status', self::PENDING)->delete();

                continue;
            }
            $sent += $this->sendFor($site);
        }

        return $sent;
    }

    public static function anyPending(): bool
    {
        try {
            return DB::table('indexnow_urls')->where('status', self::PENDING)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function sendFor(Site $site): int
    {
        $host = (string) parse_url($site->publicUrl(), PHP_URL_HOST);
        $key = $this->key($site);
        $sent = 0;
        $rows = DB::table('indexnow_urls')->where('site_id', $site->id)->where('status', self::PENDING)->orderBy('id')->limit(self::BATCH)->get(['id', 'url', 'attempts']);
        // Nur Adressen auf dem Host der Site — die Schnittstelle lehnt sonst die ganze Meldung ab
        $own = $rows->filter(static fn (object $row): bool => parse_url((string) $row->url, PHP_URL_HOST) === $host);
        $foreign = $rows->diffKeys($own)->pluck('id')->all();
        if ($foreign !== []) {
            DB::table('indexnow_urls')->whereIn('id', $foreign)->update(['status' => self::FAILED, 'sent_at' => now()]);
        }
        if ($own->isEmpty()) {
            return 0;
        }
        try {
            $response = Http::timeout(15)->acceptJson()->post((string) config('basalto.indexnow.endpoint'), [
                'host' => $host, 'key' => $key, 'keyLocation' => rtrim($site->publicUrl(), '/').'/'.$key.'.txt', 'urlList' => $own->pluck('url')->values()->all(),
            ]);
            $code = $response->status();
        } catch (Throwable) {
            $code = 0;
        }
        $ids = $own->pluck('id')->all();
        if ($code === 200 || $code === 202) {
            DB::table('indexnow_urls')->whereIn('id', $ids)->update(['status' => self::SENT, 'response' => $code, 'sent_at' => now()]);
            $sent = count($ids);
        } elseif ($code === 0 || $code === 429 || $code >= 500) {
            // Vorübergehend: nächster Lauf, höchstens fünf Versuche
            DB::table('indexnow_urls')->whereIn('id', $ids)->increment('attempts', 1, ['response' => $code ?: null]);
            DB::table('indexnow_urls')->whereIn('id', $ids)->where('attempts', '>=', self::MAX_ATTEMPTS)->update(['status' => self::FAILED, 'sent_at' => now()]);
        } else {
            // 400, 403 (Schlüssel nicht gefunden), 422 (Adresse passt nicht zum Host): gescheitert
            DB::table('indexnow_urls')->whereIn('id', $ids)->update(['status' => self::FAILED, 'response' => $code, 'sent_at' => now()]);
        }

        return $sent;
    }

    private static function publicHost(Site $site): bool
    {
        $host = parse_url($site->publicUrl(), PHP_URL_HOST);
        if (! is_string($host) || $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return preg_match('/(^localhost$|\.(test|localhost|local|example|invalid)$)/i', $host) !== 1;
    }
}
