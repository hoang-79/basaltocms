<?php

declare(strict_types=1);

namespace Basalto\Core\License;

/** Stellt Schlüssel aus — nur auf basaltocms.com mit dem privaten Ed25519-Schlüssel; die Installationen kennen nur den öffentlichen. */
final class LicenseIssuer
{
    /** @param  non-empty-string  $secretKey  rohe 64 Bytes */
    public function __construct(private readonly string $secretKey) {}

    /** @return array{public: string, secret: string} base64 */
    public static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return ['public' => base64_encode(sodium_crypto_sign_publickey($pair)), 'secret' => base64_encode(sodium_crypto_sign_secretkey($pair))];
    }

    public static function fromBase64(string $secretKey): self
    {
        $raw = base64_decode($secretKey, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Privater Lizenzschlüssel ungültig (erwartet base64 mit 64 Bytes)');
        }

        return new self($raw);
    }

    public function issue(LicenseKey $license): string
    {
        $payload = json_encode($license->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($payload, $this->secretKey);

        return LicenseKey::PREFIX.'.'.self::encode($payload).'.'.self::encode($signature);
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
