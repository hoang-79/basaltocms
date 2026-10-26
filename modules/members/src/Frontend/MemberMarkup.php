<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Frontend;

use Basalto\Core\Demo\DemoAccessMarkup;
use Basalto\Core\Demo\DemoMode;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Core\Models\Site;
use Basalto\Lava\Runtime\Context;
use Basalto\Modules\Members\Listeners\JoinOnSignIn;
use Basalto\Modules\Members\Members;
use Basalto\Modules\Members\MemberStatus;
use Basalto\Modules\Members\Models\Member;
use Basalto\Modules\Members\Registration;
use Illuminate\Http\Request;

/**
 * Das Markup der Mitglieder im Frontend — für die Lava-Tags und für geschützte Seiten:
 *
 * - `{% members.login %}`: Gästen die Anmeldung (Mail-Code, Zweck «members» — beim ersten Mal tritt man nach der Regel
 *   der Site bei), Angemeldeten «Angemeldet als … · Abmelden»;
 * - `{% members.account %}`: das ganze Feld — Anmeldung, Stand der Mitgliedschaft, «Mitglied werden», Abmelden;
 * - `{% members.status login: "/mitglieder" %}`: kurz für den Seitenkopf — «Anmelden» oder «Abmelden».
 *
 * Gäste bekommen überall dasselbe (die Seite darf aus dem Seiten-Cache kommen, ohne CSRF-Token im ersten Schritt);
 * wer angemeldet ist, sieht nie eine Seite aus dem Cache — Formulare mit Token sind dort sicher. Ohne Skript.
 */
final class MemberMarkup
{
    public const ANCHOR = 'konto';

    public function __construct(private readonly Members $members, private readonly LoginForm $login) {}

    /** @param  array<string, mixed>  $named */
    public function loginTag(array $named, Context $ctx): string
    {
        $page = PageContext::from($ctx);
        if ($page === null) {
            return '';
        }
        $customer = CustomerAuth::current();
        if ($customer === null) {
            return $this->login->render($page->request, $page->lang, $page->path, $this->intro($page->site, $page->lang), JoinOnSignIn::PURPOSE,
                (string) __('Anmelden', [], $page->lang));
        }

        return $this->login->signedIn($customer, $page->lang, $page->path);
    }

    /** @param  array<string, mixed>  $named */
    public function accountTag(array $named, Context $ctx): string
    {
        $page = PageContext::from($ctx);

        return $page === null ? '' : $this->panel($page->site, $page->request, $page->lang, $page->path, false);
    }

    /** @param  array<string, mixed>  $named */
    public function statusTag(array $named, Context $ctx): string
    {
        $page = PageContext::from($ctx);
        if ($page === null) {
            return '';
        }
        $customer = CustomerAuth::current();
        // TB 16 Nachtrag: In der Demo öffnet «Anmelden» den Schnellzugang ins Backend — der Code für Mitglieder käme nie an
        if ($customer === null && DemoMode::enabled()) {
            return DemoAccessMarkup::render($page->lang);
        }
        if ($customer === null) {
            $target = PageContext::path($named, 'login') ?? $this->members->loginPath($page->site, $page->lang);

            return sprintf('<a class="members-link" href="%s">%s</a>', Html::e(Html::url($target, [], LoginForm::ANCHOR)), Html::e(__('Anmelden', [], $page->lang)));
        }

        return sprintf('<form class="members-link is-signed-in" method="post" action="/account/logout">%s<button type="submit" title="%s">%s</button></form>',
            Html::hidden($page->path, $page->lang, true), Html::e(__('Angemeldet als :email', ['email' => $customer->email], $page->lang)), Html::e(__('Abmelden', [], $page->lang)));
    }

    /**
     * Gast: Anmeldung; angemeldet: Stand der Mitgliedschaft mit dem nächsten Schritt. `$denied` heisst: statt einer
     * geschützten Seite — dann sagt der Titel, warum hier nichts steht.
     */
    public function panel(Site $site, Request $request, string $lang, string $path, bool $denied): string
    {
        if (! $this->members->enabled($site)) {
            return sprintf('<p class="members-note">%s</p>', Html::e(__('Diese Seite ist nur für Mitglieder.', [], $lang)));
        }
        $customer = CustomerAuth::current();
        if ($customer === null) {
            $title = $denied ? __('Nur für Mitglieder', [], $lang) : __('Anmelden', [], $lang);

            return $this->login->render($request, $lang, $path, $this->intro($site, $lang), JoinOnSignIn::PURPOSE, (string) $title);
        }
        $member = $this->members->of($customer);
        $status = $request->hasSession() ? $request->session()->get('account.status') : null;
        // «Angemeldet als …» steht ohnehin unten beim Abmelden — die Meldung nach dem Code nicht doppelt
        $html = is_string($status) && $status !== __('Angemeldet als :email.', ['email' => $customer->email], $lang) ? Html::status($status, 'account-status') : '';
        $html .= $this->state($site, $customer, $member, $lang, $path);

        return sprintf('<section class="members-panel" id="%s" aria-labelledby="mitglieder-titel"><h2 id="mitglieder-titel">%s</h2>%s%s</section>', self::ANCHOR,
            Html::e(__('Mitgliedschaft', [], $lang)), $html, $this->login->signedIn($customer, $lang, $path));
    }

    /** Was als Nächstes geht: beitreten, warten, gesperrt, keine Einladung — oder aktiv. */
    private function state(Site $site, Customer $customer, ?Member $member, string $lang, string $path): string
    {
        $note = static fn (string $text, string $class): string => sprintf('<p class="members-state %s">%s</p>', $class, Html::e($text));
        if ($member === null) {
            $registration = $this->members->registration($site);
            if ($registration === Registration::Invite) {
                return $note((string) __('Für :email gibt es keine Mitgliedschaft. Mitglied wird man hier nur auf Einladung.', ['email' => $customer->email], $lang), 'is-none');
            }
            $label = $registration === Registration::Open ? __('Mitglied werden', [], $lang) : __('Mitgliedschaft beantragen', [], $lang);

            return $note((string) __('Sie sind angemeldet, aber noch kein Mitglied.', [], $lang), 'is-none')
                .sprintf('<form class="members-join" method="post" action="/members/join">%s<button type="submit">%s</button></form>', Html::hidden($path, $lang, true), Html::e($label));
        }

        return match ($member->status) {
            MemberStatus::Pending => $note((string) __('Ihre Mitgliedschaft wartet auf die Freigabe durch die Redaktion. Sie bekommen eine Mail, sobald sie freigegeben ist.', [], $lang), 'is-pending'),
            MemberStatus::Blocked => $note((string) __('Ihr Zugang ist gesperrt. Bei Fragen wenden Sie sich an die Redaktion.', [], $lang), 'is-blocked'),
            MemberStatus::Active => $note((string) __('Sie sind Mitglied seit :date.', ['date' => ($member->approved_at ?? $member->created_at ?? now())->settings(['locale' => $lang])->isoFormat('LL')], $lang), 'is-active'),
        };
    }

    /** Der Satz über der Anmeldung — je nach Regel der Site. */
    private function intro(Site $site, string $lang): string
    {
        return (string) match ($this->members->registration($site)) {
            Registration::Open => __('Kein Passwort: Wir senden Ihnen einen Code per Mail. Beim ersten Mal werden Sie so Mitglied.', [], $lang),
            Registration::Approval => __('Kein Passwort: Wir senden Ihnen einen Code per Mail. Neue Mitglieder schaltet die Redaktion frei.', [], $lang),
            Registration::Invite => __('Nur auf Einladung: Melden Sie sich mit der Adresse an, an die Ihre Einladung ging. Wir senden Ihnen einen Code per Mail.', [], $lang),
        };
    }
}
