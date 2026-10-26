<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Extranet\SupportAccess;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Backend: die gewählte Site aus der Sitzung — nur, wenn der Benutzer Mitglied ist; sonst die erste eigene Site. */
final class SelectBackendSite
{
    public const SESSION_KEY = 'basalto.site_id';

    public function __construct(private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null) {
            // TB 19 (P-16): dazu Sites mit laufendem Support-Zugriff (nur lesend)
            $sites = Site::query()->forUser($user)->get()->merge(SupportAccess::sitesFor($user))->unique('id')->values();
            $chosen = $sites->firstWhere('id', $request->session()->get(self::SESSION_KEY)) ?? $sites->first();
            $this->current->set($chosen);
            if ($chosen !== null) {
                $request->session()->put(self::SESSION_KEY, $chosen->id);
            }
        }

        return $next($request);
    }
}
