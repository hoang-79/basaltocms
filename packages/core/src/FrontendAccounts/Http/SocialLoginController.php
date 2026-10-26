<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Http;

use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Events\CustomerSignedIn;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Core\FrontendAccounts\Social\SocialConnection;
use Basalto\Core\FrontendAccounts\Social\SocialIdentity;
use Basalto\Core\FrontendAccounts\Social\SocialProbe;
use Basalto\Core\FrontendAccounts\Social\SocialProvider;
use Basalto\Core\FrontendAccounts\Social\SocialProviders;
use Basalto\Core\FrontendAccounts\Social\SocialRefusal;
use Basalto\Core\FrontendAccounts\Social\SocialSignIn;
use Basalto\Core\FrontendAccounts\Social\SocialVerifier;
use Basalto\Core\FrontendAccounts\Social\VerifiedAccount;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * TB 53 (CR-009 Nr. 2): Anmelden im Frontend-Konto über Google, Microsoft, GitHub oder LinkedIn. Weiterleiten mit
 * `state` (und PKCE, wo der Anbieter es kann — Socialite hält beides in der Sitzung), Rückkehr prüfen
 * ({@see SocialVerifier}), Konto finden, verknüpfen oder anlegen ({@see SocialSignIn}). Angemeldet verknüpft derselbe
 * Weg ein weiteres Konto («Verknüpfen» im Konto), «Trennen» löst es wieder. Die Probe aus der Einrichtung
 * ({@see SocialProbe}) geht denselben Weg, meldet aber niemanden an.
 *
 * Fehler gehen freundlich an die Seite zurück, von der die Anmeldung kam; Einzelheiten nur ins Protokoll.
 */
final class SocialLoginController
{
    /** Sitzungsschlüssel zwischen Weiterleiten und Rückkehr. */
    public const SESSION_KEY = 'account.social';

    /** Sprungmarke des Bereichs «Anmeldung» im Konto. */
    public const ANCHOR = 'konto-zugang';

    public function __construct(private readonly CurrentSite $current, private readonly SocialProviders $providers) {}

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        $site = $this->site();
        $social = SocialProvider::from($provider);
        $page = Html::pagePath($request->query('_seite'));
        $lang = $this->lang($request->query('_sprache'), $site);
        $probe = SocialProbe::start($request->query('probe'), $site, $social);
        $connection = $probe !== null
            ? SocialConnection::query()->where('site_id', $site->id)->where('provider', $social->value)->first()
            : SocialConnection::active($site, $social);
        $link = $probe === null && $request->query('_verknuepfen') === '1' && CustomerAuth::current() !== null;
        if ($connection === null) {
            return $this->fail(['page' => $page, 'lang' => $lang, 'link' => $link, 'probe' => $probe], $social,
                __('Die Anmeldung über :provider ist auf dieser Site nicht eingerichtet.', ['provider' => $social->label()], $lang));
        }
        $purpose = $request->query(LoginForm::PURPOSE);
        $request->session()->put(self::SESSION_KEY, ['provider' => $social->value, 'site_id' => $site->id, 'page' => $page, 'lang' => $lang, 'link' => $link,
            'probe' => $probe, 'purpose' => is_string($purpose) && preg_match('/^[a-z][a-z-]{0,30}$/', $purpose) === 1 ? $purpose : null]);
        $driver = $this->providers->for($connection);
        // Mit der Socialite-Attrappe in Tests ist der Treiber kein AbstractProvider — dann ohne Parameter
        if ($driver instanceof AbstractProvider) {
            if (in_array($social, [SocialProvider::Google, SocialProvider::Microsoft], true)) {
                $driver->with(['prompt' => 'select_account'])->enablePKCE();
            }
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $site = $this->site();
        $social = SocialProvider::from($provider);
        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = is_array($pending) && ($pending['provider'] ?? null) === $social->value && ($pending['site_id'] ?? null) === $site->id
            ? ['page' => Html::pagePath($pending['page'] ?? '/'), 'lang' => $this->lang($pending['lang'] ?? null, $site), 'link' => ($pending['link'] ?? false) === true,
                'probe' => is_string($pending['probe'] ?? null) ? $pending['probe'] : null, 'purpose' => is_string($pending['purpose'] ?? null) ? $pending['purpose'] : null]
            : null;
        if ($state === null) {
            return $this->fail(['page' => '/', 'lang' => $site->default_locale, 'link' => false, 'probe' => null], $social,
                __('Die Anmeldung ist abgelaufen. Bitte versuchen Sie es noch einmal.', [], $site->default_locale));
        }
        $lang = $state['lang'];
        $connection = $state['probe'] !== null
            ? SocialConnection::query()->where('site_id', $site->id)->where('provider', $social->value)->first()
            : SocialConnection::active($site, $social);
        if ($connection === null) {
            return $this->fail($state, $social, __('Die Anmeldung über :provider ist auf dieser Site nicht eingerichtet.', ['provider' => $social->label()], $lang));
        }
        if ($request->query('error') !== null) {
            // Abgebrochen oder abgelehnt beim Anbieter (access_denied, fehlende Zustimmung)
            return $this->fail($state, $social, __('Die Anmeldung bei :provider wurde abgebrochen.', ['provider' => $social->label()], $lang));
        }
        try {
            $account = app(SocialVerifier::class)->verify($social, $this->providers->for($connection)->user());
        } catch (InvalidStateException) {
            return $this->fail($state, $social, __('Die Anmeldung ist abgelaufen. Bitte versuchen Sie es noch einmal.', [], $lang));
        } catch (SocialRefusal $refusal) {
            return $this->fail($state, $social, $refusal->text($lang), $refusal->reason);
        } catch (Throwable $e) {
            // Nur Klasse und Anbieter ins Log — die Antwort des Anbieters kann Kennungen der App tragen
            Log::warning('Kundenkonto: Rückkehr von '.$social->value.' fehlgeschlagen', ['site_id' => $site->id, 'exception' => $e::class]);

            return $this->fail($state, $social, __('Die Anmeldung bei :provider hat nicht geklappt. Versuchen Sie es noch einmal oder melden Sie sich mit einem Code per Mail an.', ['provider' => $social->label()], $lang));
        }
        if ($state['probe'] !== null) {
            return $this->probeResult($state['probe'], $account);
        }
        $current = $state['link'] ? CustomerAuth::current() : null;
        if ($state['link'] && $current === null) {
            return $this->fail($state, $social, __('Die Anmeldung ist abgelaufen. Bitte versuchen Sie es noch einmal.', [], $lang));
        }
        try {
            $result = app(SocialSignIn::class)->resolve($site, $account, $current, $lang, $request->ip(), url(Html::url($state['page'], [], self::ANCHOR)));
        } catch (SocialRefusal $refusal) {
            return $this->fail($state, $social, $refusal->text($lang), $refusal->reason);
        }
        if ($current !== null) {
            return redirect(Html::url($state['page'], [], self::ANCHOR))->with('account.status', __(':provider ist jetzt mit Ihrem Konto verknüpft.', ['provider' => $social->label()], $lang));
        }
        CustomerAuth::login($request, $result['customer']);
        event(new CustomerSignedIn($result['customer'], $site, $state['purpose']));

        return redirect(Html::url($state['page'], [], 'konto'))->with('account.status', __('Angemeldet als :email.', ['email' => $result['customer']->email], $lang));
    }

    /** «Trennen» im Konto: die Anmeldung per Code bleibt immer. */
    public function unlink(Request $request, int $identity): RedirectResponse
    {
        $site = $this->site();
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $link = SocialIdentity::query()->where('site_id', $site->id)->where('customer_id', $customer->id)->findOrFail($identity);
        $link->delete();
        $lang = $this->lang($request->input('_sprache'), $site);

        return redirect(Html::url(Html::pagePath($request->input('_seite')), [], self::ANCHOR))
            ->with('account.status', __(':provider ist getrennt. Sie melden sich weiter mit einem Code per Mail an.', ['provider' => $link->providerEnum()?->label() ?? $link->provider], $lang));
    }

    /** Probe aus der Einrichtung: niemand wird angemeldet, das Ergebnis geht zurück in die Einrichtung. */
    private function probeResult(string $token, VerifiedAccount $account): RedirectResponse
    {
        $back = SocialProbe::finish($token, true, __(':provider hat das Konto :email bestätigt (Kennung :subject). Die Anmeldung für Kundenkonten funktioniert.', [
            'provider' => $account->provider->label(), 'email' => $account->email, 'subject' => $account->subject,
        ]));

        return $back === null ? redirect('/') : redirect()->away($back);
    }

    /**
     * @param  array{page: string, lang: string, link: bool, probe: ?string}  $state
     */
    private function fail(array $state, SocialProvider $provider, string $message, ?string $reason = null): RedirectResponse
    {
        if ($state['probe'] !== null) {
            $back = SocialProbe::finish($state['probe'], false, $message);

            return $back === null ? redirect('/') : redirect()->away($back);
        }
        if ($reason !== null) {
            Log::info('Kundenkonto: Anmeldung über '.$provider->value.' abgewiesen', ['reason' => $reason]);
        }
        if ($state['link']) {
            return redirect(Html::url($state['page'], [], self::ANCHOR))->with('account.status', $message);
        }

        return redirect(Html::url($state['page'], [LoginForm::QUERY => LoginForm::FAILED], LoginForm::ANCHOR))->withErrors(['social' => $message], LoginForm::BAG);
    }

    private function site(): Site
    {
        $site = $this->current->get();
        abort_if($site === null || ! CustomerAuth::available($site), 404);

        return $site;
    }

    private function lang(mixed $value, Site $site): string
    {
        return is_string($value) && in_array($value, $site->localeCodes(), true) ? $value : $site->default_locale;
    }
}
