<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Basalto\Core\Vendor;
use Illuminate\Http\Request;

/**
 * Das Hersteller-Extranet (CR-004, P-09): Backoffice für Inhaber und Team des Herstellers auf einem eigenen Host —
 * lokal `extranet-basalto.test`, in Produktion `extranet.basaltocms.com`. Kunden verwalten ihr Konto im eigenen
 * `/admin`; das Extranet ist nur für das Team. Es führt die Daten einer Site (Lizenzverkauf, Marktplatz) und der
 * SaaS-Konten dieser Installation.
 */
final class Extranet
{
    public static function enabled(): bool
    {
        $configured = config('basalto.extranet.enabled');
        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        return class_exists('Basalto\\Modules\\Licensing\\LicensingServiceProvider');
    }

    public static function host(): string
    {
        $host = config('basalto.extranet.host');
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        return app()->environment('local') ? 'extranet-'.basename(base_path()).'.test' : 'extranet.'.Vendor::host();
    }

    /** Absolute Adresse im Extranet, mit dem Schema der Anwendung. */
    public static function url(string $path = '/'): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        return (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.self::host().'/'.ltrim($path, '/');
    }

    public static function isRequest(?Request $request = null): bool
    {
        $request ??= request();

        return self::enabled() && strtolower($request->getHost()) === self::host();
    }

    /** Die Site des Herstellers (Lizenzverkauf, Marktplatz) — nach Handle, sonst die erste des Installationskontos. */
    public static function vendorSite(): ?Site
    {
        $handle = (string) config('basalto.extranet.site', 'basaltocms');

        return Site::query()->where('handle', $handle)->first()
            ?? Site::query()->where('account_id', Account::installation()->id)->orderBy('id')->first();
    }
}
