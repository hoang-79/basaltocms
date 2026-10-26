<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Extranet\Extranet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwei Schichten (CR-004, nach Fristo): Auf dem Host des Extranets gibt es kein CMS-Backend — ausser Abmelden, Profil,
 * Passkeys und (TB 29) der Webhilfe. Wer dort `/admin` aufruft (etwa nach der Anmeldung), landet in der Übersicht des Extranets.
 */
final class KeepCmsOffExtranet
{
    private const ALLOWED = ['basalto.logout', 'basalto.profile', 'basalto.passkeys', 'basalto.help'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Extranet::isRequest($request)) {
            $name = (string) $request->route()?->getName();
            foreach (self::ALLOWED as $allowed) {
                if ($name === $allowed || str_starts_with($name, $allowed.'.')) {
                    return $next($request);
                }
            }

            return redirect()->to(Extranet::url('/'));
        }

        return $next($request);
    }
}
