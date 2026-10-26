<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Seo\PublishSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Testumgebung (TB 64, CR-012): Mit `BASALTO_NOINDEX=true` trägt jede Antwort `X-Robots-Tag: noindex, nofollow` —
 * testing.basaltocms.com soll in keiner Suchmaschine neben der Produktion stehen. robots.txt sperrt dann alles
 * ({@see PublishSettings::robotsAllowed()}).
 */
final class KeepOutOfSearchEngines
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ((bool) config('basalto.noindex', false)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
