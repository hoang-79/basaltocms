<?php

declare(strict_types=1);

namespace Basalto\Core\License;

/** Prüft einen Schlüssel offline gegen den öffentlichen Ed25519-Schlüssel des Produkts (K-18: kein Aufruf nach Hause). */
final class LicenseVerifier
{
    /** @param  non-empty-string|null  $publicKey  rohe 32 Bytes */
    public function __construct(private readonly ?string $publicKey) {}

    public static function fromBase64(?string $publicKey): self
    {
        if ($publicKey === null || $publicKey === '') {
            return new self(null);
        }
        $raw = base64_decode($publicKey, true);

        return new self($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null);
    }

    public function configured(): bool
    {
        return $this->publicKey !== null;
    }

    /** Gültiger Schlüssel → Inhalt; sonst null (falsches Format, falsche Signatur, fremder Aussteller). */
    public function verify(?string $key): ?LicenseKey
    {
        if ($this->publicKey === null || $key === null) {
            return null;
        }
        $parts = explode('.', trim($key));
        if (count($parts) !== 3 || $parts[0] !== LicenseKey::PREFIX) {
            return null;
        }
        $payload = self::decode($parts[1]);
        $signature = self::decode($parts[2]);
        if ($payload === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        if (! sodium_crypto_sign_verify_detached($signature, $payload, $this->publicKey)) {
            return null;
        }
        $data = json_decode($payload, true);

        return is_array($data) ? LicenseKey::fromPayload($data) : null;
    }

    private static function decode(string $text): ?string
    {
        $raw = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $raw === false ? null : $raw;
    }
}
