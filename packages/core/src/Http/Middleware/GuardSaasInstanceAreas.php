<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Operations\Operators;
use Basalto\Core\Saas\Saas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TB 12 Scheibe 1 (ADR-008, «zwei Konten sehen sich nicht»): Auf der SaaS gehören Verwaltung › Betrieb (Warteschlange,
 * Sicherung, fehlgeschlagene Jobs aller Kunden) und Verwaltung › Module (Composer, Marktplatz) der Instanz — nur
 * Betreiber der Installation ({@see Operators}) sehen sie; Kundenkonten bekommen 404 wie für jede fremde Ressource.
 * Ohne SaaS-Modus ändert sich nichts.
 */
final class GuardSaasInstanceAreas
{
    /** Routen-Präfixe der Bereiche, die auf der SaaS nur der Betrieb sieht. */
    public const ROUTES = ['basalto.operations', 'basalto.modules'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Saas::enabled() && self::isInstanceArea((string) $request->route()?->getName())) {
            abort_unless(Operators::allows($request->user()), 404);
        }

        return $next($request);
    }

    public static function isInstanceArea(string $routeName): bool
    {
        foreach (self::ROUTES as $prefix) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }
}
