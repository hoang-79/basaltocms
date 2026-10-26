<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anfragen im Extranet (CR-004, P-09): ohne Extranet 404; die aktuelle Site ist die des Herstellers (Lizenzverkauf,
 * Marktplatz). Die zuletzt besuchte Seite merkt sich die Sitzung — die Übersicht bietet die Rückkehr an.
 */
final class ResolveExtranet
{
    public const LAST_URL = 'extranet.last_url';

    public function __construct(private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Extranet::enabled(), 404);
        $this->current->set(Extranet::vendorSite());
        $response = $next($request);
        if ($request->isMethod('GET') && ! $request->header('X-Inertia-Partial-Data') && $request->route()?->getName() !== 'extranet.home'
            && $response->getStatusCode() === 200 && ! str_contains((string) $response->headers->get('Content-Type'), 'pdf')) {
            $request->session()->put(self::LAST_URL, $request->fullUrl());
        }

        return $response;
    }
}
