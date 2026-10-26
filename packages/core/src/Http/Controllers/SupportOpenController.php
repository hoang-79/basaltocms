<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\SupportSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Support-Zugriff öffnen (CR-004, P-16): im CMS-Backend die Kunden-Site wählen, solange der eigene Zugriff läuft —
 * danach gilt die Rolle «Support» (überall nur ansehen).
 */
final class SupportOpenController
{
    public function __invoke(Request $request, int $session): RedirectResponse
    {
        $item = SupportSession::query()->findOrFail($session);
        $user = $request->user();
        abort_unless($user !== null && $item->user_id === (int) $user->getAuthIdentifier() && $item->isActive(), 403, __('Dieser Support-Zugriff ist abgelaufen oder gehört jemand anderem.'));
        $request->session()->put(SelectBackendSite::SESSION_KEY, $item->site_id);

        return redirect()->route('basalto.dashboard');
    }
}
