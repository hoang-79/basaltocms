<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use InvalidArgumentException;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Testing\SocialiteFake;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\LinkedInOpenIdProvider;
use SocialiteProviders\Manager\Config;

/**
 * TB 53: Socialite-Anbieter aus der Einrichtung einer Site statt aus `config/services.php` — je Aufruf neu gebaut,
 * ohne Zustand (Octane). Steht in Tests die Attrappe (`Socialite::fake('basalto-konto-google', …)`), kommt sie zum Zug.
 * Die Rückkehr geht immer an die Adresse auf dem Host dieser Anfrage — die Site kann mehrere Domains haben.
 */
final class SocialProviders
{
    public function for(SocialConnection $connection): Provider
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

    public function build(SocialConnection $connection): AbstractProvider
    {
        $request = app('request');
        $provider = $connection->providerEnum();
        $redirect = self::callbackUrl($provider);
        $driver = match ($provider) {
            SocialProvider::Google => new GoogleProvider($request, $connection->client_id, $connection->client_secret, $redirect),
            SocialProvider::Microsoft => (new MicrosoftProvider($request, $connection->client_id, $connection->client_secret, $redirect))
                ->setConfig(new Config($connection->client_id, $connection->client_secret, $redirect, ['tenant' => $connection->microsoftTenant()])),
            SocialProvider::GitHub => new GitHubProvider($request, $connection->client_id, $connection->client_secret, $redirect),
            SocialProvider::LinkedIn => new LinkedInOpenIdProvider($request, $connection->client_id, $connection->client_secret, $redirect),
        };

        return $driver->setScopes($provider->scopes());
    }

    /** Rückkehr-Adresse auf dem Host der Anfrage oder unter einer anderen Wurzel (für die Liste zum Eintragen). */
    public static function callbackUrl(SocialProvider $provider, ?string $root = null): string
    {
        $path = route('basalto.account.social.callback', ['provider' => $provider->value], false);

        return $root === null ? url($path) : rtrim($root, '/').$path;
    }
}
