<?php

declare(strict_types=1);

namespace Basalto\Core;

/**
 * Der Hersteller (CR-004, P-01): Adresse und Kontakt von Basalto an einer Stelle — Lizenzkauf, Marktplatz, Hinweise im
 * Backend und Texte verweisen hierher statt auf eine fest geschriebene Domain. In Produktion basaltocms.com, lokal
 * `BASALTO_VENDOR_URL=https://basalto.test`.
 */
final class Vendor
{
    /** Basisadresse ohne Schrägstrich am Ende, z. B. `https://basaltocms.com`. */
    public static function url(string $path = ''): string
    {
        $base = rtrim((string) config('basalto.vendor.url', 'https://basaltocms.com'), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    /** Hostname für Texte, z. B. `basaltocms.com`. */
    public static function host(): string
    {
        return (string) (parse_url(self::url(), PHP_URL_HOST) ?: 'basaltocms.com');
    }

    /** Kontakt für Fragen zu Lizenzen. */
    public static function email(): string
    {
        return (string) config('basalto.vendor.email', 'email@'.self::host());
    }
}
