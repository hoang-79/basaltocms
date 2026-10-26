<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * TB 53: Prüft, was der Anbieter über das Konto sagt — die E-Mail zählt nur, wenn der Anbieter sie bestätigt.
 * Sonst könnte jemand ein Konto mit fremder Adresse anlegen und damit ein bestehendes Kundenkonto übernehmen.
 *
 * - **Google, LinkedIn:** `email_verified` muss wahr sein; Kennung ist `sub`.
 * - **GitHub:** nur die bestätigte Hauptadresse ({@see GitHubProvider}); Kennung ist die Nummer des Kontos.
 * - **Microsoft:** Private Konten (Verzeichnis der Verbraucher) bestätigt Microsoft selbst. Bei Firmenkonten zählt der
 *   UPN — seine Domain ist eine bestätigte Domain des Verzeichnisses; das Feld `mail` kann die Verwaltung frei setzen
 *   und zählt nicht. Gastkonten (`#EXT#`) tragen eine fremde Adresse und werden abgewiesen. Kennung: Verzeichnis und
 *   Objekt-ID.
 */
final class SocialVerifier
{
    /** Verzeichnis der privaten Microsoft-Konten. */
    public const MICROSOFT_CONSUMERS = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /** @throws SocialRefusal */
    public function verify(SocialProvider $provider, SocialiteUser $user): VerifiedAccount
    {
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];
        $name = $this->string($user->getName()) ?? $this->string($raw['name'] ?? null);

        return match ($provider) {
            SocialProvider::Google, SocialProvider::LinkedIn => $this->openId($provider, $user, $raw, $name),
            SocialProvider::GitHub => $this->github($user, $raw, $name),
            SocialProvider::Microsoft => $this->microsoft($user, $raw, $name),
        };
    }

    /** @param  array<array-key, mixed>  $raw */
    private function openId(SocialProvider $provider, SocialiteUser $user, array $raw, ?string $name): VerifiedAccount
    {
        $email = $this->email($raw['email'] ?? $user->getEmail());
        $subject = $this->string($raw['sub'] ?? null) ?? $this->string($user->getId());
        if ($email === null || $subject === null) {
            throw new SocialRefusal(SocialRefusal::INCOMPLETE, $provider);
        }
        $verified = $raw['email_verified'] ?? null;
        if ($verified !== true && $verified !== 'true') {
            throw new SocialRefusal(SocialRefusal::UNVERIFIED, $provider);
        }

        return new VerifiedAccount($provider, $subject, $email, $name);
    }

    /** @param  array<array-key, mixed>  $raw */
    private function github(SocialiteUser $user, array $raw, ?string $name): VerifiedAccount
    {
        $subject = $this->string($raw['id'] ?? $user->getId());
        $email = $this->email($raw['email'] ?? $user->getEmail());
        if ($subject === null || $email === null) {
            // Ohne bestätigte Hauptadresse liefert GitHub keine
            throw new SocialRefusal(SocialRefusal::UNVERIFIED, SocialProvider::GitHub);
        }
        if (($raw['basalto_email_verified'] ?? false) !== true) {
            throw new SocialRefusal(SocialRefusal::UNVERIFIED, SocialProvider::GitHub);
        }

        return new VerifiedAccount(SocialProvider::GitHub, $subject, $email, $name ?? $this->string($raw['login'] ?? null));
    }

    /** @param  array<array-key, mixed>  $raw */
    private function microsoft(SocialiteUser $user, array $raw, ?string $name): VerifiedAccount
    {
        $tenant = $this->string($raw['claim_tid'] ?? null);
        $object = $this->string($raw['claim_oid'] ?? null) ?? $this->string($raw['id'] ?? $user->getId());
        if ($tenant === null || $object === null) {
            throw new SocialRefusal(SocialRefusal::INCOMPLETE, SocialProvider::Microsoft);
        }
        $upn = $this->string($raw['userPrincipalName'] ?? null);
        if ($upn !== null && str_contains(strtoupper($upn), '#EXT#')) {
            throw new SocialRefusal(SocialRefusal::GUEST, SocialProvider::Microsoft);
        }
        $email = strtolower($tenant) === self::MICROSOFT_CONSUMERS
            ? $this->email($raw['claim_email'] ?? null) ?? $this->email($upn)
            : $this->email($upn);
        if ($email === null) {
            throw new SocialRefusal(SocialRefusal::INCOMPLETE, SocialProvider::Microsoft);
        }

        return new VerifiedAccount(SocialProvider::Microsoft, strtolower($tenant).':'.$object, $email, $name);
    }

    private function email(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 254 ? mb_strtolower($value) : null;
    }

    private function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
