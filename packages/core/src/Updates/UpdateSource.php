<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Vendor;

/**
 * Woher die Updates kommen (CR-007, U-04): der Hersteller der Umgebung — `BASALTO_UPDATES_URL`, sonst die Adresse des
 * Herstellers (`BASALTO_VENDOR_URL`). Lokal `https://basalto.test`, im Test `https://testing.basaltocms.com`, für Demo
 * und Produktion `https://basaltocms.com`.
 */
final class UpdateSource
{
    public static function url(string $path = ''): string
    {
        $base = rtrim((string) (config('basalto.updates.url') ?: Vendor::url()), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    public static function host(): string
    {
        $port = parse_url(self::url(), PHP_URL_PORT);

        return (string) (parse_url(self::url(), PHP_URL_HOST) ?: Vendor::host()).(is_int($port) ? ':'.$port : '');
    }

    /** Lesbare Bezeichnung der Umgebung nach dem Host — für die Anzeige. */
    public static function stage(): string
    {
        $host = strtolower(self::host());

        return match (true) {
            str_ends_with($host, '.test') || str_starts_with($host, 'localhost') || str_starts_with($host, '127.') => 'lokal',
            str_starts_with($host, 'testing.') || str_starts_with($host, 'staging.') => 'Test',
            default => 'Produktion',
        };
    }
}
