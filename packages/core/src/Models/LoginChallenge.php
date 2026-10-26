<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ein Einmal-Link oder ein Mail-Code für die passwortlose Anmeldung. Das Geheimnis liegt nur als Hash in der Datenbank.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $purpose
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int $attempts
 * @property string|null $created_from_ip
 */
final class LoginChallenge extends Model
{
    public const KIND_LINK = 'link';

    public const KIND_CODE = 'code';

    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['user_id', 'kind', 'purpose', 'token_hash', 'expires_at', 'used_at', 'attempts', 'created_from_ip'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel);
    }

    /**
     * Einmal-Link: 64 zufällige Zeichen, Gültigkeit in Minuten, Zweck «install», «invite» oder «login».
     *
     * @return array{0: self, 1: string} Datensatz und das Geheimnis im Klartext (nur einmal sichtbar)
     */
    public static function createLink(int $userId, string $purpose, int $minutes, ?string $ip = null): array
    {
        $secret = Str::random(64);

        return [self::query()->create(['user_id' => $userId, 'kind' => self::KIND_LINK, 'purpose' => $purpose, 'token_hash' => self::hashOf($secret),
            'expires_at' => now()->addMinutes($minutes), 'created_from_ip' => $ip]), $secret];
    }

    /**
     * Mail-Code: sechs Ziffern, Gültigkeit in Minuten (K-04: zehn). Ältere offene Codes des Benutzers verfallen.
     *
     * @return array{0: self, 1: string}
     */
    public static function createCode(int $userId, int $minutes, ?string $ip = null): array
    {
        self::query()->where('user_id', $userId)->where('kind', self::KIND_CODE)->whereNull('used_at')->update(['used_at' => now()]);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [self::query()->create(['user_id' => $userId, 'kind' => self::KIND_CODE, 'purpose' => 'login', 'token_hash' => self::hashOf($code),
            'expires_at' => now()->addMinutes($minutes), 'created_from_ip' => $ip]), $code];
    }

    /** Löst einen Link ein: gültig, unverbraucht, nicht abgelaufen → verbraucht; sonst null. */
    public static function consumeLink(string $secret): ?self
    {
        $challenge = self::query()->where('kind', self::KIND_LINK)->where('token_hash', self::hashOf($secret))->first();
        if ($challenge === null || ! $challenge->isOpen()) {
            return null;
        }
        $challenge->forceFill(['used_at' => now()])->save();

        return $challenge;
    }

    /** Prüft einen Code für einen Benutzer; zählt Fehlversuche und sperrt den Code nach MAX_ATTEMPTS. */
    public static function consumeCode(int $userId, string $code): ?self
    {
        $challenge = self::query()->where('user_id', $userId)->where('kind', self::KIND_CODE)->whereNull('used_at')->latest('id')->first();
        if ($challenge === null || ! $challenge->isOpen()) {
            return null;
        }
        if (! hash_equals($challenge->token_hash, self::hashOf($code))) {
            $challenge->increment('attempts');
            if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                $challenge->forceFill(['used_at' => now()])->save();
            }

            return null;
        }
        $challenge->forceFill(['used_at' => now()])->save();

        return $challenge;
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture() && $this->attempts < self::MAX_ATTEMPTS;
    }

    public static function hashOf(string $secret): string
    {
        return hash('sha256', $secret.'|'.config('app.key'));
    }
}
