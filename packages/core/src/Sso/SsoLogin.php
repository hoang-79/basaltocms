<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Models\SsoConnection;
use Illuminate\Http\Request;

/**
 * TB 13 Scheibe 4 (M-25): Für welche Site gilt die Anmeldeseite, und welche SSO-Knöpfe zeigt sie?
 *
 * Die Site kommt aus der Auswahl (`?site=<handle>`, so verlinkt aus der Einrichtung), sonst aus dem Host der Anfrage
 * (`site_domains`), sonst aus `basalto.default_site`, sonst ist es die einzige Site der Installation. Eine Liste aller
 * Sites mit SSO zeigt die Anmeldeseite nicht — sie verriete auf der SaaS die Kunden der Instanz.
 */
final class SsoLogin
{
    public static function site(Request $request): ?Site
    {
        $handle = $request->query('site');
        if (is_string($handle) && $handle !== '') {
            return Site::query()->where('handle', $handle)->first();
        }
        $domain = SiteDomain::query()->where('host', strtolower($request->getHost()))->where('kind', '!=', SiteDomain::KIND_REDIRECT)->first();
        if ($domain !== null && $domain->site !== null) {
            return $domain->site;
        }
        $default = config('basalto.default_site');
        if (is_string($default) && $default !== '' && ($site = Site::query()->where('handle', $default)->first()) !== null) {
            return $site;
        }

        return Site::query()->count() === 1 ? Site::query()->first() : null;
    }

    /** @return list<array{provider: string, label: string, url: string}> */
    public static function buttons(Request $request): array
    {
        $site = self::site($request);
        if ($site === null) {
            return [];
        }
        $enabled = SsoConnection::query()->where('site_id', $site->id)->where('enabled', true)->pluck('provider')->all();
        $buttons = [];
        foreach (SsoProvider::cases() as $provider) {
            if (in_array($provider->value, $enabled, true)) {
                $buttons[] = ['provider' => $provider->value, 'label' => $provider->buttonLabel(), 'url' => route('basalto.login.sso', ['provider' => $provider->value, 'site' => $site->handle])];
            }
        }

        return $buttons;
    }
}
