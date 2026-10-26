<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Http\Controllers;

use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Members\Frontend\MemberMarkup;
use Basalto\Modules\Members\Members;
use Basalto\Modules\Members\MemberStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Mitglied werden» für ein schon angemeldetes Frontend-Konto (etwa aus dem Lizenzverkauf): offen sofort, mit Freigabe
 * wartend, auf Einladung nicht (404 — der Knopf erscheint dort gar nicht). Mit CSRF-Token, zurück auf die Seite.
 */
final class JoinController
{
    public function __invoke(Request $request, CurrentSite $current, Members $members): RedirectResponse
    {
        $site = $current->get();
        abort_if($site === null || ! $members->enabled($site), 404);
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $lang = in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
        $member = $members->join($customer, $site);
        abort_if($member === null, 404);
        $status = $member->status === MemberStatus::Active ? __('Willkommen — Sie sind jetzt Mitglied.', [], $lang) : __('Danke — die Redaktion prüft Ihre Anfrage.', [], $lang);

        return redirect(Html::url(Html::pagePath($request->input('_seite', '/')), [], MemberMarkup::ANCHOR))->with('account.status', $status);
    }
}
