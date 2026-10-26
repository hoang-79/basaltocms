<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Http\Middleware;

use Basalto\Core\FrontendAccounts\CustomerAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Antworten an angemeldete Konten des Frontends sind persönlich: kein Browser- oder CDN-Cache, auch wenn die Seite selbst
 * `Cache-Control: public` trägt. Den Seiten-Cache des Kerns hält `PageCache::personalGuard()` fern.
 */
final class PrivateForCustomers
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->hasSession() && config('auth.guards.'.CustomerAuth::GUARD) !== null && CustomerAuth::guard()->check()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
