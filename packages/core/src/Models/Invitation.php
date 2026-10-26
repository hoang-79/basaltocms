<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Basalto\Core\Sites\SiteRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eine Einladung in eine Site (K-03): Mail, Rolle, Link mit Ablauf; gespeichert wird nur der Hash des Tokens.
 *
 * @property int $id
 * @property int $site_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 */
final class Invitation extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    /** Neues Token setzen (Anlegen, erneut senden) — gibt das Klartext-Token für den Link zurück. */
    public function renew(int $hours): string
    {
        $token = Str::random(64);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours($hours)->utc()]);

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function roleEnum(): ?SiteRole
    {
        return SiteRole::tryFrom($this->role);
    }
}
