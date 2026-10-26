<?php

declare(strict_types=1);

namespace Basalto\Core\Live;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Push der Live-Aktualisierung (CR-008 LV-11) über das Pusher-Protokoll — ohne Bibliothek: ein Signal ist ein
 * unterschriebener HTTP-Aufruf an den WebSocket-Server, die Anmeldung an einem Kanal eine HMAC-Unterschrift. Passt zum
 * verwalteten Reverb in Laravel Cloud (setzt `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`,
 * `REVERB_PORT`, `REVERB_SCHEME`), zu Reverb als eigener Anwendung und zu Soketi. Gesendet wird nach der Antwort; fällt
 * der Server aus, übernimmt die Abfrage — ein Fehler bricht nie einen Beitrag ab.
 */
class LivePush
{
    /** Name des Ereignisses im Kanal (im Browser `.signal`). */
    public const EVENT = 'signal';

    /** Eingerichtet: Schlüssel, Geheimnis, App und Host gesetzt und nicht ausgeschaltet (`BASALTO_LIVE_PUSH=false`). */
    public function enabled(): bool
    {
        $config = $this->config();
        if (filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false) {
            return false;
        }

        return filled($config['app_id'] ?? null) && filled($config['key'] ?? null) && filled($config['secret'] ?? null) && filled($config['host'] ?? null);
    }

    /** Kanal eines Schlüssels — privat, die Anmeldung prüft die Unterschrift des Schlüssels. */
    public function channel(int $siteId, string $key): string
    {
        return 'private-basalto.'.$siteId.'.'.$key;
    }

    /** Das Signal nach der Antwort senden (im Browser holt es die Abfrage sofort). */
    public function send(int $siteId, string $key, int $version): void
    {
        if (! $this->enabled()) {
            return;
        }
        defer(fn (): bool => $this->trigger([$this->channel($siteId, $key)], ['v' => $version]), 'basalto-live:'.$siteId.':'.$key);
    }

    /**
     * Ereignis an Kanäle senden (`POST /apps/{id}/events`, unterschrieben nach dem Pusher-Protokoll).
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $data
     */
    public function trigger(array $channels, array $data): bool
    {
        $config = $this->config();
        $body = (string) json_encode(['name' => self::EVENT, 'channels' => $channels, 'data' => (string) json_encode($data)]);
        try {
            return Http::timeout(3)->withBody($body, 'application/json')->post($this->signedUrl('POST', '/apps/'.$config['app_id'].'/events', $body))->successful();
        } catch (Throwable $e) {
            Log::warning('Live-Push nicht erreichbar: '.$e->getMessage());

            return false;
        }
    }

    /** Anmeldung eines Browsers an einem privaten Kanal: «Schlüssel:Unterschrift». */
    public function auth(string $socketId, string $channel): string
    {
        $config = $this->config();

        return $config['key'].':'.hash_hmac('sha256', $socketId.':'.$channel, (string) $config['secret']);
    }

    /**
     * Was der Browser zum Verbinden braucht (kein Geheimnis) — null, wenn Push nicht eingerichtet ist.
     *
     * @return array{key: string, host: string, port: int, scheme: string}|null
     */
    public function clientConfig(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $config = $this->config();

        return ['key' => (string) $config['key'], 'host' => (string) $config['host'], 'port' => (int) ($config['port'] ?? 443), 'scheme' => (string) ($config['scheme'] ?? 'https')];
    }

    /** Für die Seite «Betrieb»: antwortet der Server (eine Minute zwischengespeichert)? */
    public function reachable(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return (bool) Cache::remember('basalto-live:push-erreichbar', 60, function (): bool {
            try {
                return Http::timeout(3)->get($this->signedUrl('GET', '/apps/'.$this->config()['app_id'].'/channels'))->successful();
            } catch (Throwable) {
                return false;
            }
        });
    }

    /** Adresse mit Unterschrift nach dem Pusher-Protokoll (Version 1.0). */
    private function signedUrl(string $method, string $path, string $body = ''): string
    {
        $config = $this->config();
        $params = ['auth_key' => (string) $config['key'], 'auth_timestamp' => (string) time(), 'auth_version' => '1.0'];
        if ($body !== '') {
            $params['body_md5'] = md5($body);
        }
        ksort($params);
        $query = http_build_query($params);
        $params['auth_signature'] = hash_hmac('sha256', $method."\n".$path."\n".$query, (string) $config['secret']);
        $host = (string) ($config['server_host'] ?: $config['host']);
        $scheme = (string) ($config['server_scheme'] ?: ($config['scheme'] ?? 'https'));
        $port = (int) ($config['server_port'] ?: ($config['port'] ?? 443));

        return $scheme.'://'.$host.':'.$port.$path.'?'.http_build_query($params);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('basalto.live.push', []);

        return is_array($config) ? $config : [];
    }
}
