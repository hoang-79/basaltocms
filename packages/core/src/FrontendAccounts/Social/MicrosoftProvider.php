<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use SocialiteProviders\Microsoft\Provider;

/**
 * TB 53: Microsoft für Kundenkonten — private Konten und auf Wunsch Firmenkonten. Ergänzt die Angaben aus Graph um
 * die Ansprüche des geprüften ID-Tokens (Signatur, Aussteller, Zielgruppe und Ablauf prüft der Anbieter-Treiber):
 * `tid` (Verzeichnis), `oid` (Objekt-ID) und `email`.
 */
final class MicrosoftProvider extends Provider
{
    /** @return array<string, mixed> */
    protected function getUserByToken($token)
    {
        /** @var array<string, mixed> $user */
        $user = parent::getUserByToken($token);
        $claims = $this->getClaims();
        foreach (['tid', 'oid', 'email'] as $claim) {
            $user['claim_'.$claim] = $claims !== null && isset($claims->{$claim}) && is_string($claims->{$claim}) ? $claims->{$claim} : null;
        }

        return $user;
    }
}
