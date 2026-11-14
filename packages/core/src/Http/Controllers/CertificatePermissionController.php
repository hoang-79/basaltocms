<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Hosting\Hostnames;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Prüfendpunkt für Zertifikate auf Abruf (Leitstand T-03, `BASALTO_HOSTING_PANEL=caddy`): Caddy fragt vor jedem
 * Zertifikat `/_zertifikat/erlaubt?domain=<host>`; nur 200 erlaubt es. Antwortet nur Anfragen vom Server selbst —
 * massgebend ist die Adresse der Verbindung (REMOTE_ADDR), nicht `X-Forwarded-For`, damit niemand über das Internet die
 * Liste der Hosts abfragt. Ohne Sitzung, ohne Site.
 */
final class CertificatePermissionController
{
    private const LOCAL = ['127.0.0.1', '::1'];

    public function __invoke(Request $request): Response
    {
        if (! Hostnames::onDemand() || ! in_array($request->server('REMOTE_ADDR'), self::LOCAL, true)) {
            abort(404);
        }
        // `domain[]=…` käme als Liste an — dann gibt es nichts frei
        $domain = $request->query('domain');
        $allowed = is_string($domain) && Hostnames::allowsCertificate($domain);

        return response('', $allowed ? 200 : 404, ['Cache-Control' => 'no-store']);
    }
}
