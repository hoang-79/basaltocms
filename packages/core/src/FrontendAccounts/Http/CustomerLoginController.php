<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Http;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\CustomerLogin;
use Basalto\Core\FrontendAccounts\Events\CustomerSignedIn;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Core\Models\Site;
use Basalto\Core\Security\ProofOfWork;
use Basalto\Core\Security\StopForumSpam;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Anmelden im Frontend-Konto: Code anfordern (ohne CSRF-Token — die Seite kommt aus dem Seiten-Cache; geschützt
 * durch Limit je IP und Adresse und einen Honigtopf), Code prüfen (mit Token), abmelden. Zurück geht es immer auf die
 * Seite, von der das Formular kam (`_seite`, nur ein Pfad dieser Site). Nach der Anmeldung meldet das Ereignis
 * `CustomerSignedIn` den Zweck des Formulars an die Module (TB 13 Scheibe 2: Mitglieder).
 */
final class CustomerLoginController
{
    /** Zwecke: Buchstaben und Bindestrich, kurz — nur ein Hinweis an die Module, kein Recht. */
    private const PURPOSE_PATTERN = '/^[a-z][a-z-]{0,30}$/';

    public function __construct(private readonly CurrentSite $current, private readonly CustomerLogin $login) {}

    public function request(Request $request): RedirectResponse
    {
        $site = $this->site();
        $page = Html::pagePath($request->input('_seite'));
        $lang = self::lang($request, $site);
        if ($request->filled('website')) {
            // Honigtopf: still, dieselbe Antwort wie für Menschen
            return redirect(Html::url($page, [LoginForm::QUERY => LoginForm::CODE], LoginForm::ANCHOR));
        }
        $validator = Validator::make($request->all(), ['email' => ['required', 'string', 'email', 'max:254']], [], ['email' => (string) __('E-Mail', [], $lang)]);
        if ($validator->fails()) {
            return redirect(Html::url($page, [LoginForm::QUERY => LoginForm::FAILED], LoginForm::ANCHOR))->withErrors($validator, LoginForm::BAG)->withInput($request->only('email'));
        }
        $email = CustomerLogin::normalize((string) $request->input('email'));
        // TB 50: Aufgabe im Browser (sichtbarer Fehler — Menschen können es noch einmal versuchen)
        $pow = app(ProofOfWork::class);
        if ($pow->enabled($site, 'konto') && $pow->reason($request, 'konto') !== null) {
            return redirect(Html::url($page, [LoginForm::QUERY => LoginForm::FAILED], LoginForm::ANCHOR))
                ->withErrors(['email' => __('Der Spamschutz konnte Sie nicht prüfen. Bitte warten Sie einen Moment und senden Sie noch einmal.', [], $lang)], LoginForm::BAG)->withInput($request->only('email'));
        }
        // TB 50: neue Konten gegen Stop Forum Spam (nur, wenn eingeschaltet)
        $isNew = ! Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', $email)->exists();
        if ($isNew && app(StopForumSpam::class)->listed($site, $email, $request->ip()) !== null) {
            return redirect(Html::url($page, [LoginForm::QUERY => LoginForm::FAILED], LoginForm::ANCHOR))
                ->withErrors(['email' => __('Diese Adresse oder Verbindung steht auf einer Liste bekannter Spam-Absender (Stop Forum Spam). Bitte wenden Sie sich an das Team der Site.', [], $lang)], LoginForm::BAG)->withInput($request->only('email'));
        }
        $minutes = $this->login->request($site, $email, $lang, $request->ip());
        $request->session()->put(CustomerLogin::SESSION_EMAIL, $email);
        $purpose = $request->input(LoginForm::PURPOSE);
        is_string($purpose) && preg_match(self::PURPOSE_PATTERN, $purpose) === 1
            ? $request->session()->put(CustomerLogin::SESSION_PURPOSE, $purpose)
            : $request->session()->forget(CustomerLogin::SESSION_PURPOSE);

        return redirect(Html::url($page, [LoginForm::QUERY => LoginForm::CODE], LoginForm::ANCHOR))
            ->with('account.status', __('Wenn die Adresse stimmt, ist ein Code unterwegs (:minutes Minuten gültig).', ['minutes' => $minutes], $lang));
    }

    public function verify(Request $request): RedirectResponse
    {
        $site = $this->site();
        $page = Html::pagePath($request->input('_seite'));
        $lang = self::lang($request, $site);
        $email = $request->session()->get(CustomerLogin::SESSION_EMAIL);
        if (! is_string($email)) {
            return redirect(Html::url($page, [], LoginForm::ANCHOR));
        }
        $code = preg_replace('/\s+/', '', (string) $request->input('code'));
        $customer = preg_match('/^\d{6}$/', (string) $code) === 1 ? $this->login->verify($site, $email, (string) $code, $lang) : null;
        if ($customer === null) {
            return redirect(Html::url($page, [LoginForm::QUERY => LoginForm::CODE], LoginForm::ANCHOR))
                ->withErrors(['code' => __('Der Code ist falsch oder abgelaufen.', [], $lang)], LoginForm::BAG);
        }
        $purpose = $request->session()->get(CustomerLogin::SESSION_PURPOSE);
        CustomerAuth::login($request, $customer);
        $request->session()->forget([CustomerLogin::SESSION_EMAIL, CustomerLogin::SESSION_PURPOSE]);
        event(new CustomerSignedIn($customer, $site, is_string($purpose) ? $purpose : null));

        return redirect(Html::url($page, [], 'konto'))->with('account.status', __('Angemeldet als :email.', ['email' => $customer->email], $lang));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->site();
        CustomerAuth::logout($request);

        return redirect(Html::pagePath($request->input('_seite')));
    }

    private function site(): Site
    {
        $site = $this->current->get();
        abort_if($site === null || ! CustomerAuth::available($site), 404);

        return $site;
    }

    private static function lang(Request $request, Site $site): string
    {
        return in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
    }
}
