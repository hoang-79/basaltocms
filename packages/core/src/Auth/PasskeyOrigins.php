<?php

declare(strict_types=1);

namespace Basalto\Core\Auth;

use Basalto\Core\Extranet\Extranet;

/**
 * Passkeys des Backends auch im Extranet (Hoang, 27.10.2026: «wenn ich auf passkey registrieren klicke, passiert
 * nichts»): Liegt das Extranet unter der Domain der Anwendung (extranet.basaltocms.com unter basaltocms.com), erlaubt der
 * Browser diese Domain als Relying Party — derselbe Passkey gilt auf beiden Hosts. Der Server muss die Herkunft des
 * Extranets nur kennen; sonst weist er die Zeremonie ab. Liegt es woanders (lokal extranet-basalto.test), bleibt alles,
 * wie es ist: dort gehen Passkeys des Backends nicht.
 */
final class PasskeyOrigins
{
    /** Die Herkunft des Extranets, wenn es unter der Relying Party liegt — sonst null. */
    public static function extranet(): ?string
    {
        if (! Extranet::enabled()) {
            return null;
        }
        $party = (string) config('passkeys.relying_party_id', parse_url((string) config('app.url'), PHP_URL_HOST));
        $host = Extranet::host();

        return $party !== '' && str_ends_with($host, '.'.$party) ? rtrim(Extranet::url('/'), '/') : null;
    }

    public static function apply(): void
    {
        $origin = self::extranet();
        if ($origin !== null) {
            config(['passkeys.allowed_origins' => array_values(array_unique([...(array) config('passkeys.allowed_origins', [config('app.url')]), $origin]))]);
        }
    }
}
