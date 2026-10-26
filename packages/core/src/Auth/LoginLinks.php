<?php

declare(strict_types=1);

namespace Basalto\Core\Auth;

use Basalto\Core\Models\LoginChallenge;
use Basalto\Core\Settings\Settings;

/** Einmal-Links erzeugen (Installer, Konsole, später Einladungen) — die URL enthält das Geheimnis einmalig im Klartext. */
final class LoginLinks
{
    public function create(int $userId, string $purpose = 'login', ?int $minutes = null): string
    {
        [, $secret] = LoginChallenge::createLink($userId, $purpose, $minutes ?? (int) app(Settings::class)->get('auth.link_minutes', (int) config('basalto.auth.link_minutes', 60)));

        return route('basalto.login.link', ['token' => $secret]);
    }
}
