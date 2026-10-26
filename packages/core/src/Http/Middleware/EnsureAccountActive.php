<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Support\BackendLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deaktivierte Konten sind sofort ausgesperrt (K-03) — auch mit offener Sitzung oder per Passkey angemeldet;
 * dazu Sprache der Oberfläche aus dem Profil.
 */
final class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user->getAttribute('deactivated_at') !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('basalto.login')->withErrors(['email' => __('Dieses Konto ist deaktiviert. Wenden Sie sich an die Verwaltung der Site.')]);
        }
        $locale = BackendLocale::of($user);
        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
