<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Http\Middleware;

use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `basalto.customer`: verlangt ein Frontend-Konto dieser Site. Ohne Anmeldung zurück auf die Seite des Formulars, dort
 * zur Anmeldung — kein Sprung ins Backend (die Backend-Anmeldung ist für Benutzer, nicht für Konten des Frontends).
 */
final class AuthenticateCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (CustomerAuth::current() === null) {
            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect(Html::url(Html::pagePath($request->input('_seite', '/')), [], LoginForm::ANCHOR));
        }

        return $next($request);
    }
}
