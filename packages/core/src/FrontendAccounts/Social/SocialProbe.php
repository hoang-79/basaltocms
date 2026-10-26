<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use Basalto\Core\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * TB 53: «Ausprobieren» in der Einrichtung — der Betreiber meldet sich einmal echt beim Anbieter an, ohne dass ein
 * Kundenkonto entsteht. Ein Zufallswert im Cache (zehn Minuten, einmal) verbindet Einrichtung und Frontend; so klappt
 * es auch, wenn Backend und Site auf verschiedenen Hosts laufen (eigene Sitzungen). Das Ergebnis sieht nur, wer die
 * Probe gestartet hat.
 */
final class SocialProbe
{
    private const PREFIX = 'basalto.social-probe.';

    private const TTL = 600;

    public static function create(Site $site, SocialProvider $provider, int $userId, string $back): string
    {
        $token = Str::random(40);
        Cache::put(self::PREFIX.$token, ['site_id' => $site->id, 'provider' => $provider->value, 'user_id' => $userId, 'back' => $back], self::TTL);

        return $token;
    }

    /** Beim Weiterleiten: gehört der Wert zu dieser Site und diesem Anbieter? Sonst ist es eine gewöhnliche Anmeldung. */
    public static function start(mixed $token, Site $site, SocialProvider $provider): ?string
    {
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $probe = Cache::get(self::PREFIX.$token);

        return is_array($probe) && ($probe['site_id'] ?? null) === $site->id && ($probe['provider'] ?? null) === $provider->value ? $token : null;
    }

    /** Nach der Rückkehr: Ergebnis ablegen; liefert die Adresse zurück in die Einrichtung. */
    public static function finish(string $token, bool $ok, string $message): ?string
    {
        $probe = Cache::pull(self::PREFIX.$token);
        if (! is_array($probe) || ! is_string($probe['back'] ?? null)) {
            return null;
        }
        Cache::put(self::PREFIX.'ergebnis.'.$token, ['ok' => $ok, 'message' => $message, 'provider' => $probe['provider'], 'user_id' => $probe['user_id']], self::TTL);

        return $probe['back'].(str_contains($probe['back'], '?') ? '&' : '?').'probe='.$token;
    }

    /** @return array{ok: bool, message: string, provider: string}|null nur für die Person, die die Probe gestartet hat */
    public static function result(mixed $token, int $userId): ?array
    {
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $result = Cache::get(self::PREFIX.'ergebnis.'.$token);
        if (! is_array($result) || ($result['user_id'] ?? null) !== $userId) {
            return null;
        }

        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message'], 'provider' => (string) $result['provider']];
    }
}
