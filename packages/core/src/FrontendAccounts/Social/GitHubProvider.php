<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use Laravel\Socialite\Two\GithubProvider as Base;

/**
 * TB 53: GitHub mit der bestätigten Hauptadresse. Mit dem Scope `user:email` ersetzt Socialite die öffentliche Adresse
 * des Profils durch die bestätigte Hauptadresse aus `/user/emails` (oder null) — das hält dieser Treiber ausdrücklich
 * fest, damit die Prüfung nicht davon abhängt, wie Socialite das intern löst.
 */
final class GitHubProvider extends Base
{
    /** @return array<string, mixed> */
    protected function getUserByToken($token)
    {
        /** @var array<string, mixed> $user */
        $user = parent::getUserByToken($token);
        $user['basalto_email_verified'] = in_array('user:email', $this->scopes, true) && is_string($user['email'] ?? null);

        return $user;
    }
}
