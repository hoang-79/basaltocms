<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\FrontendAccounts\Social\SocialButtons;
use Basalto\Core\Security\ProofOfWork;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;

/**
 * Das Markup der Anmeldung im Frontend-Konto — für jeden Tag, der ein Konto braucht (Lizenzkauf, Marktplatz,
 * Mitglieder): Schritt 1 die E-Mail (ohne CSRF-Token, die Seite darf aus dem Seiten-Cache kommen; Schutz durch Limit
 * und Honigtopf), Schritt 2 der Code aus der Mail (nur über `?anmeldung=code`, also nie aus dem Cache, mit CSRF-Token),
 * angemeldet ein Hinweis mit «Abmelden». Ohne Skript, Fehler am Feld. Ein Zweck (`_zweck`, z. B. «members») sagt dem
 * Modul nach der Anmeldung, wofür sie war — die Mitglieder treten so beim ersten Mal bei. Seit TB 53 stehen über dem
 * Feld die Knöpfe der eingeschalteten Anbieter ({@see SocialButtons}).
 */
final class LoginForm
{
    public const BAG = 'account';

    public const QUERY = 'anmeldung';

    public const CODE = 'code';

    public const FAILED = 'fehler';

    public const ANCHOR = 'konto-anmeldung';

    public const PURPOSE = '_zweck';

    /** Steht die Eingabe des Codes an? */
    public static function wantsCode(Request $request): bool
    {
        return $request->query(self::QUERY) === self::CODE && $request->hasSession() && is_string($request->session()->get(CustomerLogin::SESSION_EMAIL));
    }

    /** Für Gäste: Code-Eingabe, wenn ein Code unterwegs ist, sonst die E-Mail. */
    public function render(Request $request, string $lang, string $pagePath, ?string $intro = null, ?string $purpose = null, ?string $title = null): string
    {
        $intro ??= (string) __('Kein Passwort: Wir senden Ihnen einen Code per Mail. Beim ersten Mal entsteht so Ihr Konto.', [], $lang);
        $body = self::wantsCode($request) ? $this->codeForm($request, $lang, $pagePath) : $this->emailForm($request, $lang, $pagePath, $intro, $purpose);

        return sprintf('<section class="account-login" id="%s" aria-labelledby="%s-titel"><h2 id="%s-titel">%s</h2>%s</section>', self::ANCHOR, self::ANCHOR, self::ANCHOR,
            Html::e($title ?? __('Anmelden', [], $lang)), $body);
    }

    public function emailForm(Request $request, string $lang, string $pagePath, string $intro, ?string $purpose = null): string
    {
        $failed = $request->query(self::QUERY) === self::FAILED;
        $errors = $failed ? Html::errors($request, self::BAG) : new MessageBag;
        $old = $failed ? Html::old($request) : [];
        // TB 53: Fehler der Anmeldung über einen Anbieter stehen über den Knöpfen, nicht in der Liste der Felder
        $social = $errors->first('social');
        $errors->forget('social');
        $html = sprintf('<p class="account-intro">%s</p>', Html::e($intro));
        $site = app(CurrentSite::class)->get();
        if ($social !== '') {
            $html .= SocialButtons::styles().sprintf('<p class="account-social-fehler" role="alert">%s</p>', Html::e($social));
        }
        $html .= $site === null ? '' : SocialButtons::render($site, $lang, $pagePath, $purpose);
        $html .= '<form class="form account-form" method="post" action="/account/login">';
        $html .= Html::errorSummary($errors, $lang).Html::hidden($pagePath, $lang, false).Html::honeypot();
        if ($purpose !== null) {
            $html .= sprintf('<input type="hidden" name="%s" value="%s">', self::PURPOSE, Html::e($purpose));
        }
        $html .= Html::field('konto-email', 'email', Html::e(__('E-Mail', [], $lang)).Html::required(),
            sprintf('<input type="email" id="konto-email" name="email" value="%s" required maxlength="254" autocomplete="email"{aria}>', Html::e($old['email'] ?? '')), null, $errors);
        // TB 50: Spamschutz im Browser — schützt vor massenhaft verschickten Codes
        $pow = app(ProofOfWork::class);
        if ($pow->enabled($site, 'konto')) {
            $html .= $pow->field('konto', $lang);
        }

        return $html.sprintf('<button type="submit">%s</button></form>', Html::e(__('Code senden', [], $lang)));
    }

    public function codeForm(Request $request, string $lang, string $pagePath): string
    {
        $email = (string) $request->session()->get(CustomerLogin::SESSION_EMAIL);
        $errors = Html::errors($request, self::BAG);
        $status = $request->session()->get('account.status');
        $html = is_string($status) ? Html::status($status, 'account-status') : '';
        $html .= '<form class="form account-form account-code" method="post" action="/account/code">';
        $html .= Html::errorSummary($errors, $lang).Html::hidden($pagePath, $lang, true);
        $html .= Html::field('konto-code', 'code', Html::e(__('Code aus der Mail an :email', ['email' => $email], $lang)).Html::required(),
            '<input type="text" id="konto-code" name="code" value="" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"{aria}>',
            (string) __('Sechs Ziffern. Nichts erhalten? Prüfen Sie den Spam-Ordner.', [], $lang), $errors);
        $html .= sprintf('<button type="submit">%s</button></form>', Html::e(__('Anmelden', [], $lang)));

        return $html.sprintf('<p class="account-alt"><a href="%s">%s</a></p>', Html::e(Html::url($pagePath, [], self::ANCHOR)), Html::e(__('Andere Adresse oder neuen Code', [], $lang)));
    }

    /** Angemeldet: wer, und «Abmelden». */
    public function signedIn(Customer $customer, string $lang, string $pagePath): string
    {
        return sprintf('<form class="account-signed-in" method="post" action="/account/logout"><p>%s</p>%s<button type="submit" class="account-logout">%s</button></form>',
            Html::e(__('Angemeldet als :email', ['email' => $customer->email], $lang)), Html::hidden($pagePath, $lang, true), Html::e(__('Abmelden', [], $lang)));
    }
}
