<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Events\UserLoggedIn;
use Basalto\Core\Forms\SpamGuard;
use Basalto\Core\Help\LegalTexts;
use Basalto\Core\Models\SaasRegistration;
use Basalto\Core\Saas\AliasCheck;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Registrar;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\SiteProvisioner;
use Basalto\Core\Security\CodeResend;
use Basalto\Core\Security\ProofOfWork;
use Basalto\Core\Theme\ThemeCatalog;
use Closure;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Selbstregistrierung (TB 12 Scheibe 1, M-21, G-11, Mockup 22): Konto → Code → Site → Theme → Fertig, unter
 * `<backend>/registrieren` — nur im SaaS-Modus auf der Instanz der Testphase, sonst 404. Schutz ohne Rätsel wie bei
 * Formularen ({@see SpamGuard}: Honigtopf, Zeit-Token, Limit je IP), dazu ein Limit je Stunde und IP (Route) und je
 * Adresse. Der Stand liegt in der Sitzung (ID der Registrierung); der Einmal-Link setzt ihn auch in einem anderen Browser.
 */
final class RegistrationController
{
    public const SESSION = 'basalto.saas.registration';

    /** TB 72: Zeitpunkt des letzten Codes — «Neuen Code senden» erst nach der Wartezeit. */
    public const SESSION_SENT = 'basalto.saas.registration_sent_at';

    public const SPAM_HANDLE = 'saas-registration';

    /** Sprachen einer neuen Site (Mockup 22: Deutsch, Français, Italiano, English). */
    public const LOCALES = ['de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'en' => 'English'];

    public function __construct(private readonly Registrar $registrar, private readonly SiteProvisioner $sites, private readonly SpamGuard $guard) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $registration = $this->current($request);
        if ($registration === null && $request->user() !== null) {
            return redirect()->route('basalto.saas.account.sites');
        }
        $step = match (true) {
            $registration === null => 'account',
            $registration->isCompleted() => 'done',
            $registration->isVerified() => 'site',
            default => 'code',
        };
        $locale = app()->getLocale();
        $suggestion = $registration === null ? null : AliasCheck::suggest($registration->company ?: $registration->name);

        return Inertia::render('core::Saas/Register', [
            'step' => $step,
            'account' => ['name' => $registration->name ?? '', 'company' => $registration->company ?? '', 'email' => $registration->email ?? ''],
            'spam' => ['honeypot' => SpamGuard::HONEYPOT, 'field' => SpamGuard::TOKEN, 'token' => $this->guard->token(self::SPAM_HANDLE),
                // TB 50: Aufgabe im Browser, wenn sie für die Anmeldung an ist
                'pow' => app(ProofOfWork::class)->enabled(null, 'registrierung') ? ['url' => route('basalto.pow', absolute: false), 'area' => 'registrierung', 'field' => ProofOfWork::FIELD] : null],
            'legal' => ['agb' => LegalTexts::publicUrl('agb', $locale), 'datenschutz' => LegalTexts::publicUrl('datenschutz', $locale)],
            'site' => ['name' => $registration === null ? '' : ($registration->company ?: $registration->name), 'alias' => $suggestion ?? '',
                'locale' => array_key_exists($locale, self::LOCALES) ? $locale : 'de', 'timezone' => 'Europe/Zurich'],
            'locales' => self::LOCALES,
            'timezones' => DateTimeZone::listIdentifiers(),
            'themes' => $step === 'site' ? array_map(static fn (array $theme): array => $theme + [
                'preview_url' => $theme['has_preview'] ? route('basalto.saas.register.theme', $theme['name']) : null,
            ], $this->sites->themes()) : [],
            'default_theme' => $step === 'site' ? $this->sites->defaultTheme() : null,
            'alias_domain' => Saas::aliasDomain(),
            'trial_days' => Saas::trialDays(),
            'start_price' => Plan::find('start')->price ?? 29,
            'done' => $step === 'done' ? $this->done($registration) : null,
            'urls' => [
                'start' => route('basalto.saas.register.start'), 'verify' => route('basalto.saas.register.verify'), 'resend' => route('basalto.saas.register.resend'),
                'reset' => route('basalto.saas.register.reset'), 'alias' => route('basalto.saas.register.alias'), 'complete' => route('basalto.saas.register.complete'),
                'login' => route('basalto.login'),
            ],
            'status' => session('status'),
            'resend' => $step === 'code' ? ['wait' => app(CodeResend::class)->wait($request, self::SESSION_SENT), 'seconds' => app(CodeResend::class)->seconds()] : null,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:200'],
            'terms' => ['accepted'],
        ], ['terms.accepted' => __('Bitte stimmen Sie den AGB und der Datenschutzerklärung zu.')]);
        if ($this->guard->reason($request, self::SPAM_HANDLE, 0, 'registrierung') !== null) {
            throw ValidationException::withMessages(['email' => __('Die Registrierung liess sich nicht senden. Bitte laden Sie die Seite neu und versuchen Sie es in einer Minute wieder.')]);
        }
        $email = mb_strtolower(trim((string) $data['email']));
        if (! RateLimiter::attempt('basalto-saas-register-mail:'.hash('sha256', $email), 5, static fn (): bool => true, 3600)) {
            throw ValidationException::withMessages(['email' => __('Zu viele Codes für diese Adresse — bitte versuchen Sie es in einer Stunde wieder.')]);
        }
        $registration = $this->registrar->start((string) $data['name'], is_string($data['company'] ?? null) ? trim($data['company']) : null, $email, app()->getLocale(), $request->ip());
        $request->session()->put(self::SESSION, $registration->id);
        app(CodeResend::class)->sent($request, self::SESSION_SENT);

        return redirect()->route('basalto.saas.register')->with('status', __('Ein Code ist unterwegs an :email — oder öffnen Sie den Link in der Mail.', ['email' => $email]));
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $registration = $this->current($request);
        if ($registration === null) {
            return redirect()->route('basalto.saas.register');
        }
        if (! $registration->verifyCode((string) $data['code'])) {
            return back()->withErrors(['code' => __('Der Code ist falsch oder abgelaufen.')]);
        }

        return redirect()->route('basalto.saas.register');
    }

    /** Einmal-Link aus der Mail — setzt die Registrierung auch in einem anderen Browser fort. */
    public function link(Request $request, string $token): RedirectResponse
    {
        $registration = SaasRegistration::consumeLink($token);
        if ($registration === null) {
            return redirect()->route('basalto.saas.register')->withErrors(['code' => __('Dieser Link ist ungültig, abgelaufen oder schon verwendet. Fordern Sie einen neuen Code an.')]);
        }
        $request->session()->put(self::SESSION, $registration->id);

        return redirect()->route('basalto.saas.register');
    }

    public function resend(Request $request, CodeResend $resend): RedirectResponse
    {
        $registration = $this->current($request);
        if ($registration === null || $registration->isVerified()) {
            return redirect()->route('basalto.saas.register');
        }
        $wait = $resend->wait($request, self::SESSION_SENT);
        if ($wait > 0) {
            return back()->withErrors(['resend' => trans_choice('Bitte warten Sie noch :count Sekunde.|Bitte warten Sie noch :count Sekunden.', $wait)]);
        }
        if (! RateLimiter::attempt('basalto-saas-register-mail:'.hash('sha256', $registration->email), 5, static fn (): bool => true, 3600)) {
            return back()->withErrors(['code' => __('Zu viele Codes für diese Adresse — bitte versuchen Sie es in einer Stunde wieder.')]);
        }
        $this->registrar->send($registration);
        $resend->sent($request, self::SESSION_SENT);

        return back()->with('status', __('Ein neuer Code ist unterwegs an :email.', ['email' => $registration->email]));
    }

    /** Von vorn — andere Adresse. */
    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return redirect()->route('basalto.saas.register');
    }

    /** Live-Prüfung des Alias beim Tippen (ohne JavaScript prüft der Server beim Absenden dasselbe). */
    public function alias(Request $request): JsonResponse
    {
        return response()->json(AliasCheck::check((string) $request->query('name', ''), $request->filled('site') ? (string) $request->query('site') : null));
    }

    public function complete(Request $request): RedirectResponse
    {
        $registration = $this->current($request);
        if ($registration === null || ! $registration->isVerified() || $registration->isCompleted()) {
            return redirect()->route('basalto.saas.register');
        }
        $data = $request->validate(self::siteRules($this->sites));
        /** @var list<string> $locales */
        $locales = array_values(array_unique(array_map('strval', (array) $data['locales'])));
        $result = $this->registrar->complete($registration, (string) $data['name'], strtolower((string) $data['alias']), $locales, (string) $data['timezone'], (string) $data['theme']);
        $user = $result['user'];
        if ($user instanceof Authenticatable) {
            Auth::guard('web')->login($user, remember: true);
            $request->session()->regenerate();
            event(new UserLoggedIn($user, 'registration'));
        }

        return redirect()->route('basalto.saas.register');
    }

    /** Vorschaubild eines Themes zur Wahl — öffentlich, aber nur für Themes, die zur Wahl stehen. */
    public function theme(string $name): BinaryFileResponse
    {
        abort_unless(in_array($name, array_column($this->sites->themes(), 'name'), true), 404);
        foreach (ThemeCatalog::PREVIEWS as $path) {
            $file = ThemeCatalog::path().'/'.$name.'/'.$path;
            if (is_file($file)) {
                return response()->file($file, ['Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
            }
        }
        abort(404);
    }

    /**
     * Regeln für eine neue SaaS-Site (Registrierung und «Weitere Site»): Name, freier Alias, Sprachen, Zeitzone, Theme.
     *
     * @return array<string, mixed>
     */
    public static function siteRules(SiteProvisioner $sites): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'alias' => ['required', 'string', 'max:40', static function (string $attribute, mixed $value, Closure $fail): void {
                $check = AliasCheck::check((string) $value, (string) request()->input('name', ''));
                if ($check['state'] !== AliasCheck::FREE) {
                    $fail($check['message']);
                }
            }],
            'locales' => ['required', 'array', 'min:1', 'max:4'],
            'locales.*' => ['required', 'string', Rule::in(array_keys(self::LOCALES))],
            'timezone' => ['required', 'timezone'],
            'theme' => ['required', 'string', Rule::in(array_column($sites->themes(), 'name'))],
        ];
    }

    private function current(Request $request): ?SaasRegistration
    {
        $id = $request->session()->get(self::SESSION);

        return is_int($id) || is_string($id) ? SaasRegistration::query()->find((int) $id) : null;
    }

    /** @return array<string, mixed> */
    private function done(SaasRegistration $registration): array
    {
        $account = $registration->account;
        $site = $account?->sites()->orderBy('id')->first();

        return [
            'site_name' => $site?->name,
            'site_url' => $site === null ? null : Saas::aliasUrl($site->handle),
            'live' => $site?->isLive() ?? false,
            'backend_url' => route('basalto.dashboard'),
            'publish_url' => route('basalto.setup.publish'),
            'trial_ends' => $account?->trial_ends_at?->timezone($site !== null ? $site->timezone : 'Europe/Zurich')->format('d.m.Y'),
            'seconds' => $registration->completed_at === null ? null : max(1, (int) $registration->created_at->diffInSeconds($registration->completed_at)),
            'domains_url' => route('basalto.saas.account.domains'),
        ];
    }
}
