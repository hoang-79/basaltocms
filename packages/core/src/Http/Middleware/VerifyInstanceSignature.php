<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Saas\Move\InstanceLink;
use Basalto\Core\Saas\Saas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TB 12 Scheibe 4: Schnittstelle zwischen den Instanzen nur mit gültiger Signatur ({@see InstanceLink::verify()}) —
 * ohne SaaS-Modus oder ohne Geheimnis gibt es sie nicht (404), eine falsche, alte oder wiederholte Signatur ist 403.
 * Die sendende Instanz liegt danach im Attribut `basalto.instance`.
 */
final class VerifyInstanceSignature
{
    public const ATTRIBUTE = 'basalto.instance';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Saas::enabled() && InstanceLink::configured(), 404);
        $instance = InstanceLink::verify($request);
        abort_if($instance === null, 403, 'Signatur ungültig');
        $request->attributes->set(self::ATTRIBUTE, $instance);

        return $next($request);
    }
}
