<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Move;

use Basalto\Core\Saas\Saas;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Die signierte HTTP-Schnittstelle zwischen den Instanzen «start» und «pro» (TB 12 Scheibe 4, ADR-008 Konsequenz 4).
 * Jede Anfrage trägt die sendende Instanz, einen Zeitstempel und eine HMAC-SHA256 über Instanz, Methode, Pfad,
 * Zeitstempel und die SHA-256 des Körpers — mit dem gemeinsamen Geheimnis `BASALTO_INSTANCE_SECRET`. Die Gegenseite
 * prüft Signatur (zeitkonstant), Zeitfenster (±5 Minuten) und Wiederholung (jede Signatur genau einmal).
 *
 * Adressen kommen nur aus der Konfiguration (`basalto.saas.instances`), nie aus einer Anfrage — keine Abrufe an Adressen,
 * die ein Aufrufer nennt.
 */
final class InstanceLink
{
    public const PATH = '_basalto/instanz';

    public const HEADER_INSTANCE = 'X-Basalto-Instance';

    public const HEADER_TIMESTAMP = 'X-Basalto-Timestamp';

    public const HEADER_SIGNATURE = 'X-Basalto-Signature';

    /** Sekunden, die ein Zeitstempel von der eigenen Uhr abweichen darf. */
    public const WINDOW = 300;

    public static function secret(): string
    {
        return (string) config('basalto.saas.instance_secret', '');
    }

    /** Ohne Geheimnis (mindestens 32 Zeichen) gibt es die Schnittstelle nicht. */
    public static function configured(): bool
    {
        return strlen(self::secret()) >= 32;
    }

    /** @return list<string> die Instanzen mit Adresse */
    public static function instances(): array
    {
        $instances = [];
        foreach ((array) config('basalto.saas.instances', []) as $name => $url) {
            if (is_string($url) && $url !== '') {
                $instances[] = (string) $name;
            }
        }

        return $instances;
    }

    public static function baseUrl(string $instance): string
    {
        $url = config('basalto.saas.instances.'.$instance);
        if (! is_string($url) || $url === '') {
            throw new RuntimeException(sprintf('Adresse der Instanz «%s» fehlt (BASALTO_INSTANCE_%s_URL).', $instance, strtoupper($instance)));
        }

        return rtrim($url, '/');
    }

    public static function signature(string $instance, string $method, string $path, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$instance, strtoupper($method), '/'.ltrim($path, '/'), $timestamp, hash('sha256', $body)]), self::secret());
    }

    /** @return array<string, string> */
    public static function headers(string $method, string $path, string $body, ?int $timestamp = null): array
    {
        $time = (string) ($timestamp ?? time());

        return [self::HEADER_INSTANCE => Saas::instance(), self::HEADER_TIMESTAMP => $time, self::HEADER_SIGNATURE => self::signature(Saas::instance(), $method, $path, $time, $body)];
    }

    /** Pfad der Schnittstelle relativ zur Wurzel, z. B. `_basalto/instanz/umzug/<uuid>/fertig`. */
    public static function path(string $suffix): string
    {
        return self::PATH.'/'.ltrim($suffix, '/');
    }

    /** @param  array<string, mixed>  $payload */
    public function post(string $instance, string $suffix, array $payload): Response
    {
        $path = self::path($suffix);
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return Http::timeout(60)->acceptJson()->withHeaders(self::headers('POST', $path, $body))
            ->withBody($body, 'application/json')->post(self::baseUrl($instance).'/'.$path);
    }

    /** Signierter Abruf ohne Körper (TB 19, P-15: Zustand der anderen Instanz). */
    public function get(string $instance, string $suffix): Response
    {
        $path = self::path($suffix);

        return Http::timeout(8)->acceptJson()->withHeaders(self::headers('GET', $path, ''))->get(self::baseUrl($instance).'/'.$path);
    }

    /**
     * Eine Datei der anderen Instanz holen und nach `$target` schreiben; zurück kommt ihre SHA-256.
     */
    public function download(string $instance, string $suffix, string $target): string
    {
        $path = self::path($suffix);
        $response = Http::timeout(3600)->withHeaders(self::headers('GET', $path, ''))->get(self::baseUrl($instance).'/'.$path);
        $response->throw();
        $stream = $response->toPsrResponse()->getBody();
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new RuntimeException('Zieldatei für das Archiv lässt sich nicht schreiben.');
        }
        $hash = hash_init('sha256');
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (! $stream->eof()) {
                $chunk = $stream->read(1048576);
                hash_update($hash, $chunk);
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }

        return hash_final($hash);
    }

    /**
     * Eingehende Anfrage prüfen — zurück kommt die sendende Instanz, sonst null. Jede Signatur gilt genau einmal.
     */
    public static function verify(Request $request): ?string
    {
        $instance = (string) $request->headers->get(self::HEADER_INSTANCE, '');
        $timestamp = (string) $request->headers->get(self::HEADER_TIMESTAMP, '');
        $signature = (string) $request->headers->get(self::HEADER_SIGNATURE, '');
        if (! self::configured() || $instance === Saas::instance() || ! in_array($instance, self::instances(), true)
            || preg_match('/^\d{9,11}$/', $timestamp) !== 1 || abs(time() - (int) $timestamp) > self::WINDOW || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return null;
        }
        $expected = self::signature($instance, $request->getMethod(), $request->path(), $timestamp, (string) $request->getContent());
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        return Cache::add('basalto:instance-signature:'.$signature, true, self::WINDOW * 2) ? $instance : null;
    }
}
