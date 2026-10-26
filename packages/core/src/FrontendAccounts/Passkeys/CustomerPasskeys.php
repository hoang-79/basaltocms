<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Passkeys;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * TB 53: Passkeys für Kundenkonten — die WebAuthn-Zeremonien von `laravel/passkeys`, aber mit eigener Tabelle je Site
 * und ohne die Ereignisse des Pakets (deren Hörer melden Backend-Benutzern Sicherheitsänderungen). Die Relying Party
 * ist der Host der Anfrage ({@see self::scope()}): Eine Site kann eigene Domains haben, ein Passkey gilt auf der Domain,
 * auf der er entstand.
 */
final class CustomerPasskeys
{
    /** Sitzungsschlüssel zwischen Optionen und Antwort des Browsers. */
    public const LOGIN_OPTIONS = 'account.passkey.login';

    public const REGISTRATION_OPTIONS = 'account.passkey.registration';

    /** Relying Party und erlaubte Herkunft für diese Anfrage (die Konfiguration gilt nur für sie — Octane klont sie). */
    public static function scope(Request $request): void
    {
        config(['passkeys.relying_party_id' => $request->getHost(), 'passkeys.allowed_origins' => [$request->getSchemeAndHttpHost()]]);
    }

    /** Optionen zum Anlegen — wie im Paket (Passkey auffindbar, Bestätigung durch Fingerabdruck oder PIN), aber mit den Passkeys des Kontos. */
    public function registrationOptions(Customer $customer): PublicKeyCredentialCreationOptions
    {
        $generate = app(GenerateRegistrationOptions::class);
        $exclude = CustomerPasskey::query()->where('customer_id', $customer->id)->pluck('credential_id')
            ->map(static fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, Base64UrlSafe::decodeNoPadding($id)))->all();

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: Passkeys::relyingPartyName(), id: Passkeys::relyingPartyId()),
            user: PublicKeyCredentialUserEntity::create(name: $customer->email, id: self::userHandle($customer), displayName: $customer->displayName()),
            challenge: random_bytes(32),
            pubKeyCredParams: $generate->supportedAlgorithms(),
            authenticatorSelection: $generate->authenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: Passkeys::timeout(),
        );
    }

    /** Feste Kennung des Kontos für den Authenticator — ohne Personendaten, verschieden von den Backend-Benutzern. */
    public static function userHandle(Customer $customer): string
    {
        return hash_hmac('sha256', 'customers|'.$customer->id, (string) config('passkeys.user_handle_secret', config('app.key')), binary: true);
    }

    public function loginOptions(): PublicKeyCredentialRequestOptions
    {
        return app(GenerateVerificationOptions::class)();
    }

    /** @throws InvalidPasskeyException */
    public function store(Site $site, Customer $customer, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): CustomerPasskey
    {
        if (! $credential->response instanceof AuthenticatorAttestationResponse) {
            throw InvalidPasskeyException::make(__('Der Passkey liess sich nicht anlegen. Bitte versuchen Sie es noch einmal.'));
        }
        $source = WebAuthn::attestationValidator()->check(authenticatorAttestationResponse: $credential->response, publicKeyCredentialCreationOptions: $options, host: Passkeys::relyingPartyId());
        $id = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);
        if (CustomerPasskey::query()->where('credential_id', $id)->exists()) {
            throw InvalidPasskeyException::make(__('Dieser Passkey ist schon angelegt.'));
        }

        return CustomerPasskey::query()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'name' => $name, 'credential_id' => $id,
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR)]);
    }

    /**
     * Prüft die Antwort des Browsers und liefert den Passkey — nur einen dieser Site.
     *
     * @throws InvalidPasskeyException
     */
    public function verify(Site $site, PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options): CustomerPasskey
    {
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw InvalidPasskeyException::make(__('Der Passkey liess sich nicht prüfen. Bitte versuchen Sie es noch einmal.'));
        }

        return DB::transaction(static function () use ($site, $credential, $options, $response): CustomerPasskey {
            $passkey = CustomerPasskey::query()->where('site_id', $site->id)->where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId))->lockForUpdate()->first()
                ?? throw InvalidPasskeyException::make(__('Diesen Passkey kennt die Site nicht — vielleicht wurde er im Konto entfernt.'));
            $record = WebAuthn::fromJson(json_encode($passkey->credential, JSON_THROW_ON_ERROR), CredentialRecord::class);
            $source = WebAuthn::assertionValidator()->check(credentialRecord: $record, authenticatorAssertionResponse: $response, publicKeyCredentialRequestOptions: $options,
                host: Passkeys::relyingPartyId(), userHandle: $record->userHandle);
            // Der Zähler der Signaturen muss mit — so fallen geklonte Authenticatoren auf
            $passkey->forceFill(['credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR), 'last_used_at' => now()])->save();

            return $passkey;
        });
    }
}
