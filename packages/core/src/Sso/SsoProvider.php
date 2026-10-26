<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

/** TB 13 Scheibe 4 (M-25): die Anbieter für SSO im Backend — Microsoft Entra ID und Google. */
enum SsoProvider: string
{
    case Entra = 'entra';
    case Google = 'google';

    /** Name für die Socialite-Attrappe in Tests (`Socialite::fake('basalto-entra', …)`); gebaut wird je Site, nicht aus `config/services.php`. */
    public function driver(): string
    {
        return 'basalto-'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Entra => 'Microsoft Entra ID',
            self::Google => 'Google',
        };
    }

    public function buttonLabel(): string
    {
        return match ($this) {
            self::Entra => __('Mit Microsoft anmelden'),
            self::Google => __('Mit Google anmelden'),
        };
    }

    /** Kurzname in Meldungen («bei Microsoft»). */
    public function shortName(): string
    {
        return match ($this) {
            self::Entra => 'Microsoft',
            self::Google => 'Google',
        };
    }
}
