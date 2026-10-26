<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Saas\Saas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TB 12 Scheibe 1 (M-21): Routen der SaaS gibt es nur im SaaS-Modus — sonst 404, als gäbe es sie nicht. Mit dem Parameter
 * `registration` zusätzlich nur auf der Instanz der Testphase («start»).
 */
final class EnsureSaasMode
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        abort_unless($scope === 'registration' ? Saas::registrationOpen() : Saas::enabled(), 404);

        return $next($request);
    }
}
