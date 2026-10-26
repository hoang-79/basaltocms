<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\Backend\Backend;
use Basalto\Core\FrontendAccounts\Social\SocialButtons;
use Basalto\Core\Security\CodeResend;
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
        $resendError = $errors->first('resend');
        $errors->forget('resend');
        $status = $request->session()->get('account.status');
        $t = static fn (string $text): string => Html::e(__($text, [], $lang));
        $html = is_string($status) ? Html::status($status, 'account-status') : '';
        // TB 72: Mit Skript sechs Ziffernfelder, die von selbst senden (account/codefelder.ts); ohne Skript ein Feld mit Knopf
        $html .= sprintf('<form class="form account-form account-code" method="post" action="/account/code" data-basalto-code data-ziffer-text="%s">', $t('Ziffer :n von :total'));
        $html .= Html::errorSummary($errors, $lang).Html::hidden($pagePath, $lang, true);
        $html .= Html::field('konto-code', 'code', Html::e(__('Code aus der Mail an :email', ['email' => $email], $lang)).Html::required(),
            '<input type="text" id="konto-code" name="code" value="" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"{aria}>',
            (string) __('Sechs Ziffern. Nichts erhalten? Prüfen Sie den Spam-Ordner.', [], $lang), $errors);
        $html .= sprintf('<button type="submit" data-code-senden>%s</button></form>', $t('Anmelden'));
        // «Code erneut senden»: zählt die Wartezeit herunter; der Server prüft sie in jedem Fall
        $wait = app(CodeResend::class)->wait($request, CustomerLogin::SESSION_SENT);
        $html .= sprintf('<form class="account-erneut" method="post" action="/account/code/erneut" data-basalto-erneut data-warten="%d" data-text-eins="%s" data-text-mehr="%s">%s<button type="submit" class="account-erneut__knopf">%s</button>%s</form>',
            // Vorlagen mit «:count» für das Skript — die Zahl setzt erst der Countdown ein
            $wait, Html::e(trans_choice('Neuer Code in :count Sekunde|Neuer Code in :count Sekunden', 1, ['count' => ':count'], $lang)),
            Html::e(trans_choice('Neuer Code in :count Sekunde|Neuer Code in :count Sekunden', 2, ['count' => ':count'], $lang)),
            Html::hidden($pagePath, $lang, true), $t('Code erneut senden'), $resendError === '' ? '' : sprintf('<span class="account-erneut__fehler" role="alert">%s</span>', Html::e($resendError)));

        return $html.sprintf('<p class="account-alt"><a href="%s">%s</a></p>', Html::e(Html::url($pagePath, [], self::ANCHOR)), $t('Andere Adresse')).self::codeAssets();
    }

    /** Stil der Ziffernfelder und das kleine Skript — einmal je Seite. */
    private static function codeAssets(): string
    {
        $request = request();
        if ($request->attributes->get('basalto.code.assets') === true) {
            return '';
        }
        $request->attributes->set('basalto.code.assets', true);

        return '<style>.basalto-code{display:flex;gap:.5rem;margin:.25rem 0}.form .basalto-code input,.basalto-code input{width:2.75rem;min-width:0;height:3.25rem;'
            .'padding:0;text-align:center;font-size:1.4rem;font-weight:600;font-variant-numeric:tabular-nums}@media (max-width:380px){.form .basalto-code input,'
            .'.basalto-code input{width:2.4rem;height:3rem;font-size:1.25rem}}.account-erneut{margin:.75rem 0 0}.account-erneut .account-erneut__knopf{all:unset;'
            .'cursor:pointer;text-decoration:underline;font-size:.9em}.account-erneut .account-erneut__knopf:disabled{cursor:default;text-decoration:none;opacity:.7}'
            .'.account-erneut .account-erneut__knopf:focus-visible{outline:2px solid currentColor;outline-offset:2px}.account-erneut__fehler{display:block;font-size:.9em;margin-top:.25rem}'
            .'.form .basalto-code input[aria-invalid=true]{border-color:var(--error,#b42318)}</style>'
            .app(Backend::class)->vite()->withEntryPoints(['resources/js/codefelder.ts'])->toHtml();
    }

    /** Angemeldet: wer, und «Abmelden». */
    public function signedIn(Customer $customer, string $lang, string $pagePath): string
    {
        return sprintf('<form class="account-signed-in" method="post" action="/account/logout"><p>%s</p>%s<button type="submit" class="account-logout">%s</button></form>',
            Html::e(__('Angemeldet als :email', ['email' => $customer->email], $lang)), Html::hidden($pagePath, $lang, true), Html::e(__('Abmelden', [], $lang)));
    }
}
