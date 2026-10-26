<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

use Closure;
use RuntimeException;

/**
 * Darf der Server diese Adresse selbst ansprechen? (gegen SSRF; Bilder holen in TB 02/06, Webhooks in TB 07):
 * nur `http`/`https` auf Port 80/443, ohne Zugangsdaten in der Adresse, und jede aufgelöste IP muss öffentlich sein.
 * Zurück kommt die geprüfte IP — die Verbindung muss genau an sie gehen (`CURLOPT_RESOLVE`), sonst könnte ein zweites
 * Auflösen nach innen zeigen.
 */
final class PublicAddress
{
    /** @var Closure(string): list<string> */
    private Closure $resolve;

    /** @param  (Closure(string): list<string>)|null  $resolve  Hostname → IP-Adressen (im Test ersetzbar) */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? self::dns(...);
    }

    /**
     * @param  string  $subject  für die Meldung, z. B. «Bilder» oder «Webhooks»
     * @return array{0: string, 1: int, 2: string} Host, Port und die geprüfte IP
     *
     * @throws RuntimeException mit einer Meldung für die Redaktion
     */
    public function check(string $url, string $subject): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(__(':subject nur über http- oder https-Adressen.', ['subject' => $subject]));
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            throw new RuntimeException(__(':subject nur über die üblichen Ports (80, 443).', ['subject' => $subject]));
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            throw new RuntimeException(__(':subject: Die Adresse lässt sich nicht auflösen.', ['subject' => $subject]));
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new RuntimeException(__(':subject: keine Adressen aus internen Netzen.', ['subject' => $subject]));
            }
        }

        return [$host, $port, $ips[0]];
    }

    /**
     * Die cURL-Option, die die Verbindung an die geprüfte IP bindet.
     *
     * @return array<int, list<string>>
     */
    public static function pin(string $host, int $port, string $ip): array
    {
        return [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? "[$ip]" : $ip)]];
    }

    /** @return list<string> */
    private static function dns(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
