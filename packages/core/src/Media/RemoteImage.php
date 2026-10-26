<?php

declare(strict_types=1);

namespace Basalto\Core\Media;

use Basalto\Core\Support\PublicAddress;
use Closure;
use finfo;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Holt ein Bild von einer fremden Adresse — für Text mit Bildern aus Webseiten oder Google Docs, die im Fliesstext
 * eingefügt werden. Weil der Server dabei selbst eine Anfrage stellt, gilt (gegen SSRF):
 * nur `http`/`https` auf Port 80/443, jede aufgelöste IP muss öffentlich sein, die Verbindung geht genau an die
 * geprüfte IP (kein zweites Auflösen), Weiterleitungen werden je Schritt neu geprüft, höchstens 10 MB, kurze
 * Zeitgrenzen und nur echte Bilder (PNG, JPEG, GIF, WebP nach Inhalt, nicht nach Kopfzeile).
 */
final class RemoteImage
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /** @var (Closure(string): list<string>)|null */
    private ?Closure $resolve;

    /** @param  (Closure(string): list<string>)|null  $resolve  Hostname → IP-Adressen (im Test ersetzbar) */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve;
    }

    /**
     * @return array{binary: string, mime: string, name: string}
     *
     * @throws RuntimeException mit einer Meldung für die Redaktion
     */
    public function fetch(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->check($url);
            try {
                $response = Http::timeout(10)->connectTimeout(5)->withHeaders(['Accept' => 'image/*'])->withOptions([
                    'allow_redirects' => false,
                    'curl' => PublicAddress::pin($host, $port, $ip),
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new RuntimeException('zu gross');
                        }
                    },
                ])->get($url);
            } catch (Throwable) {
                throw new RuntimeException(__('Das Bild unter dieser Adresse ist nicht erreichbar oder grösser als 10 MB.'));
            }
            if ($response->redirect()) {
                $url = self::absolute((string) $response->header('Location'), $url);

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException(__('Das Bild unter dieser Adresse ist nicht erreichbar (HTTP :status).', ['status' => $response->status()]));
            }
            $binary = $response->body();
            if (strlen($binary) > self::MAX_BYTES) {
                throw new RuntimeException(__('Das Bild ist grösser als 10 MB.'));
            }
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
            if (! isset(self::TYPES[$mime])) {
                throw new RuntimeException(__('Unter dieser Adresse liegt kein Bild (PNG, JPEG, GIF oder WebP).'));
            }

            return ['binary' => $binary, 'mime' => $mime, 'name' => self::nameOf($url, self::TYPES[$mime])];
        }

        throw new RuntimeException(__('Zu viele Weiterleitungen — das Bild bitte herunterladen und hochladen.'));
    }

    /** @return array{0: string, 1: int, 2: string} Host, Port und die geprüfte IP */
    private function check(string $url): array
    {
        return (new PublicAddress($this->resolve))->check($url, __('Bilder'));
    }

    private static function absolute(string $location, string $base): string
    {
        if ($location === '' || preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname((string) ($parts['path'] ?? '/')), '/').'/'.$location;
    }

    private static function nameOf(string $url, string $extension): string
    {
        $base = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return ($base !== '' ? mb_substr($base, 0, 80) : 'eingefuegt-'.now()->format('Ymd-His')).'.'.$extension;
    }
}
