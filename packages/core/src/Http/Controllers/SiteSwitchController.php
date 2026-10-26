<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Extranet\SupportAccess;
use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Wechsel der Site im Backend — nur zu Sites, deren Mitglied man ist (K-02). */
final class SiteSwitchController
{
    public function __invoke(Request $request, Site $site): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($site->roleKeyOf($user) !== null || SupportAccess::active($user, $site) !== null), 403, __('Kein Zugriff auf diese Site'));
        $request->session()->put(SelectBackendSite::SESSION_KEY, $site->id);

        return redirect()->route('basalto.dashboard');
    }
}
