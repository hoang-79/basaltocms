<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vor dem Installer im Browser: Solange nichts eingerichtet ist, gibt es womöglich noch keine Tabellen — Sitzungen
 * laufen dann über Dateien statt über die Datenbank. Ist die Installation eingerichtet, gibt es den Installer nicht
 * mehr (404). Mit `BASALTO_INSTALL_TOKEN` braucht der Aufruf `?token=…` (empfohlen, wenn der Server schon öffentlich
 * erreichbar ist, bevor jemand installiert).
 */
final class PrepareInstallation
{
    public function __construct(private readonly Installer $installer) {}

    /** @param  string|null  $stage  «fertig»: nur die Datei-Sitzung (die Installation ist dann schon eingerichtet) */
    public function handle(Request $request, Closure $next, ?string $stage = null): Response
    {
        config()->set('session.driver', 'file');
        if ($stage === 'fertig') {
            return $next($request);
        }
        abort_if($this->installer->installed(), 404);
        $token = config('basalto.install_token');
        if (is_string($token) && $token !== '') {
            // läuft vor dem Sitzungsstart — das Token kommt aus der Adresse oder dem Formular, nie aus der Sitzung
            $given = $request->query('token') ?? $request->input('token');
            abort_unless(is_string($given) && hash_equals($token, $given), 404);
        }

        return $next($request);
    }
}
