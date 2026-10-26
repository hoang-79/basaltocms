<?php

declare(strict_types=1);

namespace Basalto\Core\Release;

use RuntimeException;

/**
 * Signatur eines Releases (CR-004, P-05): Ed25519 über Version, Datei, Grösse und SHA-256 — dieselbe Nachricht prüft der
 * Web-Installer mit dem öffentlichen Schlüssel, der in ihm steckt. Schlüssel wie beim Marktplatz: privat nur beim Hersteller
 * (`BASALTO_RELEASE_SECRET_KEY`, sonst der Marktplatz-Schlüssel; Base64 oder Pfad zu einer Datei), öffentlich im Kern.
 *
 * @phpstan-type Manifest array{product: string, version: string, file: string, size: int, sha256: string, php: string, created_at: string, signature?: string, notes?: string|null, history?: list<array{version: string, created_at: string|null, notes: string|null}>}
 */
final class ReleaseSignature
{
    public const PREFIX = 'basalto-release:1';

    /** @param  array{version: string, file: string, size: int, sha256: string}  $manifest */
    public static function message(array $manifest): string
    {
        return implode("\n", [self::PREFIX, $manifest['version'], $manifest['file'], (string) $manifest['size'], $manifest['sha256']]);
    }

    /**
     * Der private Schlüssel aus Base64 oder einer Datei — prüft, dass er zum öffentlichen passt, sonst entstünden Releases,
     * die kein Installer annimmt.
     *
     * @return non-empty-string
     */
    public static function secretKey(?string $configured, string $publicKey): string
    {
        $value = trim((string) $configured);
        if ($value === '') {
            throw new RuntimeException('Kein privater Release-Schlüssel (BASALTO_RELEASE_SECRET_KEY oder BASALTO_MARKETPLACE_SECRET_KEY).');
        }
        $text = is_file($value) ? trim((string) file_get_contents($value)) : $value;
        $raw = base64_decode($text, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Der private Release-Schlüssel ist kein Ed25519-Schlüssel (64 Bytes als Base64).');
        }
        if (! hash_equals(trim($publicKey), base64_encode(sodium_crypto_sign_publickey_from_secretkey($raw)))) {
            throw new RuntimeException('Der private Release-Schlüssel passt nicht zum öffentlichen Schlüssel des Kerns.');
        }

        return $raw;
    }

    /**
     * @param  array{version: string, file: string, size: int, sha256: string}  $manifest
     * @param  non-empty-string  $secretRaw
     */
    public static function sign(array $manifest, string $secretRaw): string
    {
        return base64_encode(sodium_crypto_sign_detached(self::message($manifest), $secretRaw));
    }

    /** @param  array{version: string, file: string, size: int, sha256: string, signature?: string}  $manifest */
    public static function verify(array $manifest, string $publicKey): bool
    {
        $signature = base64_decode((string) ($manifest['signature'] ?? ''), true);
        $public = base64_decode(trim($publicKey), true);
        if ($signature === false || $public === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, self::message($manifest), $public);
    }
}
