<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use SocialiteProviders\Microsoft\Provider;

/**
 * TB 13 Scheibe 4 (M-25): Microsoft Entra ID mit festem Tenant. Ergänzt die Angaben aus Graph um die Ansprüche des
 * geprüften ID-Tokens (Signatur, Aussteller, Zielgruppe und Ablauf prüft der Anbieter-Treiber): `tid` (Tenant) und
 * `oid` (Objekt-ID) — {@see SsoVerifier} vergleicht den Tenant mit der Einrichtung der Site.
 */
final class EntraProvider extends Provider
{
    /** @return array<string, mixed> */
    protected function getUserByToken($token)
    {
        /** @var array<string, mixed> $user */
        $user = parent::getUserByToken($token);
        $claims = $this->getClaims();
        $user['tid'] = $claims !== null && isset($claims->tid) && is_string($claims->tid) ? $claims->tid : null;
        $user['oid'] = $claims !== null && isset($claims->oid) && is_string($claims->oid) ? $claims->oid : null;

        return $user;
    }
}
