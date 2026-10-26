<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechte je Bereich an den Routen (CR-005, R-06): Jede Backend-Route gehört zu einem Bereich; die Rolle der Person
 * auf der aktuellen Site braucht dort die Stufe der Anfrage (lesen → ansehen, löschen → löschen, sonst bearbeiten).
 * Was nicht reicht, ist 403 — auch wenn jemand die Adresse kennt.
 */
final class GuardAreaAccess
{
    public function __construct(private readonly Areas $areas, private readonly Access $access, private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $site = $this->current->get();
        $name = $request->route()?->getName();
        if ($user === null || $site === null || ! is_string($name)) {
            return $next($request);
        }
        $requirement = $this->areas->requirement($name, $request->method());
        if ($requirement === null) {
            return $next($request);
        }
        $allowed = $requirement['group'] !== null
            ? $this->access->seesGroup($user, $site, $requirement['group'])
            : $this->access->allows($user, $site, (string) $requirement['area'], $requirement['level'] ?? Level::View);
        abort_unless($allowed, 403, __('Ihre Rolle hat keinen Zugang zu diesem Bereich.'));

        return $next($request);
    }
}
