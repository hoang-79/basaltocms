<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Http;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Events\CustomerSignedIn;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\Notifications\PasskeyAddedNotification;
use Basalto\Core\FrontendAccounts\Passkeys\CustomerPasskey;
use Basalto\Core\FrontendAccounts\Passkeys\CustomerPasskeys;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * TB 53: Passkeys im Kundenkonto. Anmelden: Optionen holen (GET, legt die Aufgabe in die Sitzung), Antwort des
 * Browsers prüfen und anmelden (POST, JSON mit der Adresse zum Weitergehen). Im Konto: Passkey hinzufügen (Optionen,
 * Antwort, Name) und entfernen. Seite, Sprache und Zweck reisen als Abfrage mit — die Seite mit dem Knopf darf aus dem
 * Seiten-Cache kommen, das CSRF-Token liefert die Antwort auf die Optionen als Cookie (`XSRF-TOKEN`).
 */
final class CustomerPasskeyController
{
    public function __construct(private readonly CurrentSite $current, private readonly CustomerPasskeys $passkeys) {}

    public function loginOptions(Request $request): JsonResponse
    {
        $this->site($request);
        $options = $this->passkeys->loginOptions();
        $request->session()->put(CustomerPasskeys::LOGIN_OPTIONS, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function login(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $serialized = $request->session()->pull(CustomerPasskeys::LOGIN_OPTIONS);
        if (! is_string($serialized)) {
            throw ValidationException::withMessages(['credential' => __('Die Anmeldung ist abgelaufen. Bitte versuchen Sie es noch einmal.')]);
        }
        $credential = $this->credential($request);
        $passkey = $this->safely(fn (): CustomerPasskey => $this->passkeys->verify($site, $credential, $this->decode($serialized, PublicKeyCredentialRequestOptions::class)),
            __('Der Passkey liess sich nicht prüfen. Bitte versuchen Sie es noch einmal oder melden Sie sich mit einem Code per Mail an.'));
        $customer = $passkey->customer;
        if (! $customer instanceof Customer || $customer->site_id !== $site->id) {
            throw ValidationException::withMessages(['credential' => __('Diesen Passkey kennt die Site nicht — vielleicht wurde er im Konto entfernt.')]);
        }
        CustomerAuth::login($request, $customer);
        $purpose = $request->query('_zweck');
        event(new CustomerSignedIn($customer, $site, is_string($purpose) && preg_match('/^[a-z][a-z-]{0,30}$/', $purpose) === 1 ? $purpose : null));
        $request->session()->flash('account.status', __('Angemeldet als :email.', ['email' => $customer->email]));

        return response()->json(['redirect' => Html::url(Html::pagePath($request->query('_seite')), [], 'konto')]);
    }

    public function registrationOptions(Request $request): JsonResponse
    {
        $this->site($request);
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $options = $this->passkeys->registrationOptions($customer);
        $request->session()->put(CustomerPasskeys::REGISTRATION_OPTIONS, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $request->validate(['name' => ['required', 'string', 'max:60']], [], ['name' => __('Name des Passkeys')]);
        $serialized = $request->session()->pull(CustomerPasskeys::REGISTRATION_OPTIONS);
        if (! is_string($serialized)) {
            throw ValidationException::withMessages(['credential' => __('Das Anlegen ist abgelaufen. Bitte versuchen Sie es noch einmal.')]);
        }
        $name = trim((string) $request->input('name'));
        $credential = $this->credential($request);
        $this->safely(fn (): CustomerPasskey => $this->passkeys->store($site, $customer, $name, $credential, $this->decode($serialized, PublicKeyCredentialCreationOptions::class)),
            __('Der Passkey liess sich nicht anlegen. Bitte versuchen Sie es noch einmal.'));
        $page = Html::pagePath($request->query('_seite'));
        $customer->notify((new PasskeyAddedNotification($name, $site->name, url(Html::url($page, [], SocialLoginController::ANCHOR))))->locale($customer->preferredLocale()));
        $request->session()->flash('account.status', __('Passkey «:name» angelegt. Sie melden sich damit ab jetzt ohne Code an.', ['name' => $name]));

        return response()->json(['redirect' => Html::url($page, [], SocialLoginController::ANCHOR)]);
    }

    public function destroy(Request $request, int $key): RedirectResponse
    {
        $site = $this->site($request);
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $passkey = CustomerPasskey::query()->where('site_id', $site->id)->where('customer_id', $customer->id)->findOrFail($key);
        $passkey->delete();

        return redirect(Html::url(Html::pagePath($request->input('_seite')), [], SocialLoginController::ANCHOR))
            ->with('account.status', __('Passkey «:name» entfernt. Auf dem Gerät können Sie ihn jetzt auch löschen.', ['name' => $passkey->name]));
    }

    /** Site, Sprache und Relying Party dieser Anfrage. */
    private function site(Request $request): Site
    {
        $site = $this->current->get();
        abort_if($site === null || ! CustomerAuth::available($site), 404);
        $lang = $request->query('_sprache', $request->input('_sprache'));
        app()->setLocale(is_string($lang) && in_array($lang, $site->localeCodes(), true) ? $lang : $site->default_locale);
        CustomerPasskeys::scope($request);

        return $site;
    }

    /**
     * Zeremonie ausführen; was die WebAuthn-Prüfung beanstandet (Signatur, Herkunft, Zähler), wird zur Meldung am
     * Knopf — Einzelheiten nur ins Protokoll.
     *
     * @param  callable(): CustomerPasskey  $ceremony
     */
    private function safely(callable $ceremony, string $message): CustomerPasskey
    {
        try {
            return $ceremony();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('Kundenkonto: Passkey abgewiesen', ['exception' => $e::class, 'message' => $e->getMessage()]);

            throw ValidationException::withMessages(['credential' => $message]);
        }
    }

    /** Die Antwort des Browsers als Objekt — freundlicher Fehler, wenn sie nicht passt. */
    private function credential(Request $request): PublicKeyCredential
    {
        $request->validate(['credential' => ['required', 'array'], 'credential.id' => ['required', 'string'], 'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'in:public-key'], 'credential.response' => ['required', 'array']]);
        try {
            return WebAuthn::fromJson(json_encode($request->input('credential'), JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            throw ValidationException::withMessages(['credential' => __('Der Browser hat eine unbekannte Antwort geschickt. Bitte versuchen Sie es noch einmal.')]);
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function decode(string $serialized, string $class): object
    {
        try {
            return WebAuthn::fromJson($serialized, $class);
        } catch (Throwable $e) {
            Log::warning('Kundenkonto: Passkey-Optionen in der Sitzung unlesbar', ['exception' => $e::class]);

            throw ValidationException::withMessages(['credential' => __('Die Anmeldung ist abgelaufen. Bitte versuchen Sie es noch einmal.')]);
        }
    }
}
