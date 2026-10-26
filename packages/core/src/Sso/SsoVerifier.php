<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use Basalto\Core\Models\SsoConnection;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * TB 13 Scheibe 4 (M-25): Prüft, was der Anbieter über das Konto sagt — die E-Mail zählt nur, wenn der Anbieter sie
 * bestätigt.
 *
 * - **Entra:** Der Tenant aus dem geprüften ID-Token muss der eingerichtete sein. Massgeblich ist der UPN — seine Domain
 *   ist immer eine bestätigte Domain des Tenants; das Feld `mail` kann die Verwaltung des Tenants frei setzen und zählt
 *   deshalb nur mit derselben Domain wie der UPN. Gastkonten (`#EXT#`) tragen eine fremde Adresse und werden abgewiesen.
 * - **Google:** `email_verified` muss wahr sein; mit eingerichteter Workspace-Domain müssen `hd` und die Domain der
 *   E-Mail passen (der Parameter `hd` beim Weiterleiten ist nur eine Vorauswahl, keine Prüfung).
 */
final class SsoVerifier
{
    /** @throws SsoRefusal */
    public function verify(SsoConnection $connection, SocialiteUser $user): VerifiedIdentity
    {
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];

        return match ($connection->providerEnum()) {
            SsoProvider::Entra => $this->entra($connection, $user, $raw),
            SsoProvider::Google => $this->google($connection, $user, $raw),
        };
    }

    /** @param  array<array-key, mixed>  $raw */
    private function entra(SsoConnection $connection, SocialiteUser $user, array $raw): VerifiedIdentity
    {
        $tenant = $raw['tid'] ?? null;
        if (! is_string($tenant) || $connection->tenant_id === null || strcasecmp($tenant, $connection->tenant_id) !== 0) {
            throw new SsoRefusal(SsoRefusal::TENANT);
        }
        $upn = $this->string($raw['userPrincipalName'] ?? $user->getEmail());
        if ($upn !== null && str_contains(strtoupper($upn), '#EXT#')) {
            throw new SsoRefusal(SsoRefusal::GUEST);
        }
        $subject = $this->string($raw['oid'] ?? null) ?? $this->string($user->getId());
        if ($upn === null || ! $this->isEmail($upn) || $subject === null) {
            throw new SsoRefusal(SsoRefusal::INCOMPLETE);
        }
        $emails = [strtolower($upn)];
        $mail = $this->string($raw['mail'] ?? null);
        if ($mail !== null && $this->isEmail($mail) && $this->domain($mail) === $this->domain($upn) && strtolower($mail) !== $emails[0]) {
            $emails[] = strtolower($mail);
        }

        return new VerifiedIdentity(SsoProvider::Entra, strtolower($tenant), $subject, $emails, $this->string($user->getName()) ?? $emails[0]);
    }

    /** @param  array<array-key, mixed>  $raw */
    private function google(SsoConnection $connection, SocialiteUser $user, array $raw): VerifiedIdentity
    {
        $email = $this->string($raw['email'] ?? $user->getEmail());
        $subject = $this->string($raw['sub'] ?? null) ?? $this->string($user->getId());
        if ($email === null || ! $this->isEmail($email) || $subject === null) {
            throw new SsoRefusal(SsoRefusal::INCOMPLETE);
        }
        $verified = $raw['email_verified'] ?? null;
        if ($verified !== true && $verified !== 'true') {
            throw new SsoRefusal(SsoRefusal::UNVERIFIED, $email);
        }
        $required = $connection->hosted_domain === null || $connection->hosted_domain === '' ? null : strtolower($connection->hosted_domain);
        if ($required !== null) {
            $hd = $this->string($raw['hd'] ?? null);
            if ($hd === null || strtolower($hd) !== $required || $this->domain($email) !== $required) {
                throw new SsoRefusal(SsoRefusal::DOMAIN, $email);
            }
        }

        return new VerifiedIdentity(SsoProvider::Google, 'google', $subject, [strtolower($email)], $this->string($user->getName()) ?? strtolower($email));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function domain(string $email): string
    {
        return strtolower(substr($email, (int) strrpos($email, '@') + 1));
    }
}
