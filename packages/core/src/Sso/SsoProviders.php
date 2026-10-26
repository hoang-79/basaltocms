<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use Basalto\Core\Models\SsoConnection;
use InvalidArgumentException;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Testing\SocialiteFake;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use SocialiteProviders\Manager\Config;

/**
 * TB 13 Scheibe 4 (M-25): Socialite-Anbieter aus der Einrichtung einer Site statt aus `config/services.php` — je Aufruf
 * neu gebaut, ohne Zustand (Octane). Steht in Tests die Attrappe (`Socialite::fake('basalto-entra', …)`), kommt sie
 * zum Zug; die Treibernamen stehen in {@see SsoProvider::driver()}.
 */
final class SsoProviders
{
    /** Der Anbieter für eine Verbindung — Rückkehr immer an die Adresse auf dem Host dieser Anfrage. */
    public function for(SsoConnection $connection): Provider
    {
        $factory = Socialite::getFacadeRoot();
        if ($factory instanceof SocialiteFake) {
            try {
                return $factory->driver($connection->providerEnum()->driver());
            } catch (InvalidArgumentException) {
                // Für diesen Anbieter keine Attrappe — dann der echte (ohne Netz bis zur Rückkehr)
            }
        }

        return $this->build($connection);
    }

    /** Baut den echten Anbieter. */
    public function build(SsoConnection $connection): AbstractProvider
    {
        $request = app('request');
        $redirect = self::callbackUrl($connection->providerEnum());

        return match ($connection->providerEnum()) {
            SsoProvider::Entra => (new EntraProvider($request, $connection->client_id, $connection->client_secret, $redirect))
                // Tenant fest — nie «common»: nur Konten dieses Verzeichnisses kommen bis zur Rückkehr
                ->setConfig(new Config($connection->client_id, $connection->client_secret, $redirect, ['tenant' => (string) $connection->tenant_id])),
            SsoProvider::Google => new GoogleProvider($request, $connection->client_id, $connection->client_secret, $redirect),
        };
    }

    /** Rückkehr-Adresse (Redirect-URI) auf dem Host der Anfrage oder unter einer anderen Wurzel. */
    public static function callbackUrl(SsoProvider $provider, ?string $root = null): string
    {
        $path = route('basalto.login.sso.callback', ['provider' => $provider->value], false);

        return $root === null ? url($path) : rtrim($root, '/').$path;
    }
}
