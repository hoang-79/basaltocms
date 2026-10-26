<?php

declare(strict_types=1);

namespace Basalto\Core\Auth;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Models\ApiToken;
use Basalto\Core\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Persönliche API-Tokens (M-17): ausstellen für genau eine Site mit `read` und/oder `write`, auflisten und
 * widerrufen — je mit Eintrag im Protokoll (K-15). Den Klartext gibt es nur einmal, beim Ausstellen; gespeichert wird
 * wie bei Sanctum die SHA-256-Prüfsumme, das Token selbst hat die Form `<id>|<zufall><prüfziffer>`.
 */
final class ApiTokens
{
    public function __construct(private readonly Recorder $recorder, private readonly Dates $dates) {}

    /**
     * @param  list<string>  $abilities
     *
     * @throws ApiTokenException ohne Mitgliedschaft auf der Site oder ohne gültige Fähigkeit
     */
    public function issue(Model&Authenticatable&HasApiTokens $user, Site $site, string $name, array $abilities, ?CarbonInterface $expiresAt = null): NewAccessToken
    {
        if ($site->roleKeyOf($user) === null) {
            throw new ApiTokenException(__('Kein Mitglied der Site «:site» — dafür gibt es kein Token.', ['site' => $site->name]));
        }
        $abilities = array_values(array_intersect(ApiToken::ABILITIES, $abilities));
        if ($abilities === []) {
            throw new ApiTokenException(__('Ein Token braucht mindestens eine Fähigkeit (read oder write).'));
        }
        $entropy = Str::random(40);
        $plain = (string) config('sanctum.token_prefix', '').$entropy.hash('crc32b', $entropy);

        return DB::transaction(function () use ($user, $site, $name, $abilities, $expiresAt, $plain): NewAccessToken {
            /** @var ApiToken $token */
            $token = $user->tokens()->create([
                'name' => $name, 'token' => hash('sha256', $plain), 'abilities' => $abilities, 'expires_at' => $expiresAt, 'site_id' => $site->id,
            ]);
            $this->recorder->record('api_token.created', $token, null, ['name' => $name, 'site' => $site->handle, 'abilities' => $abilities, 'expires_at' => $expiresAt?->toIso8601String()], $site, $user);

            return new NewAccessToken($token, $token->getKey().'|'.$plain);
        });
    }

    /** Widerrufen: nur ein eigenes Token — ein fremdes ist «nicht gefunden». */
    public function revoke(Model&Authenticatable&HasApiTokens $user, int $tokenId): ApiToken
    {
        /** @var ApiToken $token */
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        $site = $token->site_id === null ? null : Site::query()->find($token->site_id);
        $token->delete();
        $this->recorder->record('api_token.revoked', $token, null, ['name' => $token->name, 'site' => $site?->handle], $site, $user);

        return $token;
    }

    /**
     * Die Tokens eines Benutzers für das Profil — ohne Prüfsumme, mit Site und letzter Benutzung.
     *
     * @return list<array{id: int, name: string, site: string|null, abilities: list<string>, last_used: string|null, expires: string|null, expired: bool, created: string|null}>
     */
    public function forUser(Model&HasApiTokens $user): array
    {
        $relative = $this->dates->relativeFormatter();
        $out = [];
        /** @var ApiToken $token */
        foreach ($user->tokens()->with('site')->orderByDesc('id')->get() as $token) {
            $out[] = [
                'id' => (int) $token->id, 'name' => $token->name, 'site' => $token->site?->name,
                'abilities' => array_values(array_intersect(ApiToken::ABILITIES, $token->abilities ?? [])),
                'last_used' => $relative($token->last_used_at), 'expires' => $this->dates->date($token->expires_at), 'expired' => $token->isExpired(),
                'created' => $this->dates->date($token->created_at),
            ];
        }

        return $out;
    }
}
