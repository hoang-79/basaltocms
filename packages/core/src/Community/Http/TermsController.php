<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\CommunityTerms;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Bedingungen der Community (CR-011 TB 60): zustimmen aus dem Fenster im Forum (Konto: in der Datenbank mit Zeitpunkt;
 * Gast ohne Skript: Cookie), widerrufen im Kundenkonto. Ohne Haken keine Zustimmung.
 */
final class TermsController
{
    public function __construct(private readonly CurrentSite $current, private readonly CommunityTerms $terms) {}

    public function accept(Request $request): RedirectResponse
    {
        $customer = CustomerAuth::current();
        $site = $this->current->get();
        abort_if($customer === null || $site === null, 403);
        $back = Html::pagePath($request->input('_seite'));
        if (! $request->boolean('zustimmung')) {
            return redirect($back);
        }
        $this->terms->accept($site, $customer);

        return redirect($back)->with('community.status', __('Danke — Sie haben den Bedingungen der Community zugestimmt.', [], (string) $request->input('_sprache', $site->default_locale)));
    }

    /** Gäste ohne Skript: dieselbe Zustimmung als Cookie (wie das Skript im Fenster) — ohne Sitzung und CSRF. */
    public function guest(Request $request): RedirectResponse
    {
        $site = $this->current->get();
        abort_if($site === null, 404);
        $back = redirect(Html::pagePath($request->input('_seite')));

        return ! $request->boolean('zustimmung') ? $back
            : $back->withCookie(cookie(CommunityTerms::COOKIE, $this->terms->cookieValue($site), 525600, '/', null, $request->isSecure(), false, false, 'Lax'));
    }

    public function revoke(Request $request): RedirectResponse
    {
        $customer = CustomerAuth::current();
        $site = $this->current->get();
        abort_if($customer === null || $site === null, 403);
        $this->terms->revoke($site, $customer);

        return redirect(Html::pagePath($request->input('_seite')).'#bedingungen')
            ->with('account.status', __('Zustimmung widerrufen — die Community lässt sich erst nach einer neuen Zustimmung wieder nutzen.', [], (string) $request->input('_sprache', $site->default_locale)));
    }
}
