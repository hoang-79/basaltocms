<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Community\CommunityTerms;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CR-011 TB 60: Schreiben in der Community (Beiträge, Reaktionen, Nachrichten, Meldungen …) nur mit gültiger Zustimmung
 * zu den Bedingungen — sonst 403 mit Hinweis (JSON für die Inseln), damit niemand am Fenster vorbei schreibt.
 */
final class RequireCommunityTerms
{
    public const ALIAS = 'basalto.community.terms';

    public function handle(Request $request, Closure $next): Response
    {
        $site = app(CurrentSite::class)->get();
        if ($site !== null && ! app(CommunityTerms::class)->accepted($site, CustomerAuth::current())) {
            $message = __('Bitte stimmen Sie zuerst den Bedingungen der Community zu.', [], (string) $request->input('_sprache', $site->default_locale));
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }
            abort(403, $message);
        }

        return $next($request);
    }
}
