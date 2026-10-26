<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Saas\AccountContext;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TB 12 Scheibe 1: Konto-Bereich — das Konto des Benutzers in die Anfrage legen ({@see AccountContext::from()}); ohne
 * Konto-Rolle an einem Kundenkonto gibt es den Bereich nicht (404, nicht 403).
 */
final class ResolveSaasAccount
{
    public function __construct(private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $account = $user === null ? null : AccountContext::resolve($user, $this->current->get());
        abort_if($account === null, 404);
        $request->attributes->set(AccountContext::ATTRIBUTE, $account);

        return $next($request);
    }
}
