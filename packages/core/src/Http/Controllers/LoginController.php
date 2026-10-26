<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Demo\DemoMode;
use Basalto\Core\Events\UserLoggedIn;
use Basalto\Core\Models\LoginChallenge;
use Basalto\Core\Notifications\LoginCodeNotification;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sso\SsoLogin;
use Basalto\Core\Support\Preferences;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Passwortlose Anmeldung im Backend (K-04): Mail-Code, Einmal-Link, Passkey (über Fortify), Abmelden; SSO siehe {@see SsoLoginController}. */
final class LoginController
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('basalto.dashboard');
        }

        return Inertia::render('core::Login', [
            'codeUrl' => route('basalto.login.code'),
            'passkey' => ['options' => route('passkey.login-options'), 'login' => route('passkey.login')],
            'status' => session('status'),
            // TB 11 Scheibe 4 (G-10): Schnellzugang in drei Rollen — nur im Demo-Modus, sonst null
            'demo' => DemoMode::quickAccess(),
            // TB 13 Scheibe 4 (M-25): «Mit Microsoft/Google anmelden», wenn für die Site (Auswahl oder Host) eingerichtet
            'sso' => SsoLogin::buttons($request),
        ]);
    }

    /** Code anfordern — die Antwort ist immer dieselbe, ob das Konto existiert oder nicht. */
    public function requestCode(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:200']]);
        $email = strtolower($validated['email']);
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = $userModel::query()->where('email', $email)->first();
        $minutes = (int) app(Settings::class)->get('auth.code_minutes', (int) config('basalto.auth.code_minutes', 10));
        if ($user !== null && method_exists($user, 'notify')) {
            [, $code] = LoginChallenge::createCode((int) $user->getKey(), $minutes, $request->ip());
            $user->notify(new LoginCodeNotification($code, $minutes));
        }
        $request->session()->put('basalto.login.email', $email);

        return redirect()->route('basalto.login.verify')->with('status', __('Wenn ein Konto zu :email gehört, ist ein Code unterwegs (:minutes Minuten gültig).', ['email' => $email, 'minutes' => $minutes]));
    }

    public function showCode(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get('basalto.login.email');
        if (! is_string($email)) {
            return redirect()->route('basalto.login');
        }

        return Inertia::render('core::LoginCode', ['email' => $email, 'verifyUrl' => route('basalto.login.verify'), 'backUrl' => route('basalto.login'), 'status' => session('status')]);
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $email = $request->session()->get('basalto.login.email');
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = is_string($email) ? $userModel::query()->where('email', $email)->first() : null;
        $challenge = $user === null ? null : LoginChallenge::consumeCode((int) $user->getKey(), $validated['code']);
        if ($user === null || $challenge === null) {
            return back()->withErrors(['code' => __('Der Code ist falsch oder abgelaufen.')]);
        }

        return $this->signIn($request, $user, 'basalto.login.email');
    }

    /** Einmal-Link aus Installer oder Konsole: gültig → angemeldet; sonst zurück zur Anmeldung. */
    public function link(Request $request, string $token): RedirectResponse
    {
        $challenge = LoginChallenge::consumeLink($token);
        if ($challenge === null) {
            return redirect()->route('basalto.login')->withErrors(['email' => __('Dieser Link ist ungültig, abgelaufen oder schon verwendet. Fordern Sie einen Code an.')]);
        }

        return $this->signIn($request, $challenge->user, null);
    }

    public function logout(Request $request): SymfonyResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return self::afterLogout();
    }

    /**
     * Wohin es nach dem Abmelden geht — auch, wenn die Sitzung schon verfallen war (CSRF-Token veraltet, siehe
     * `bootstrap/app.php`). Demo: zurück auf die Startseite mit dem Schnellzugang — die Website ist keine Inertia-Seite,
     * darum `location`.
     */
    public static function afterLogout(): SymfonyResponse
    {
        if (DemoMode::enabled()) {
            return Inertia::location(url('/'));
        }

        return redirect()->route('basalto.login');
    }

    private function signIn(Request $request, ?Model $user, ?string $forgetKey): RedirectResponse
    {
        if (! $user instanceof Authenticatable) {
            abort(500, 'Benutzermodell ist nicht anmeldbar');
        }
        if ($user->getAttribute('deactivated_at') !== null) {
            return redirect()->route('basalto.login')->withErrors(['email' => __('Dieses Konto ist deaktiviert. Wenden Sie sich an die Verwaltung der Site.')]);
        }
        Auth::guard('web')->login($user, remember: true);
        event(new UserLoggedIn($user, $forgetKey === null ? 'link' : 'code'));
        $request->session()->regenerate();
        if ($forgetKey !== null) {
            $request->session()->forget($forgetKey);
        }

        return redirect()->intended(Preferences::startUrl($user));
    }
}
