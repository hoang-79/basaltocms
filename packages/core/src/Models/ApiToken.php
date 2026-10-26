<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ein persönliches API-Token (M-17) — das Modell von Sanctum mit der Site, für die es gilt. Fähigkeiten sind `read`
 * (lesende Anfragen) und `write` (anlegen, ändern, löschen, Workflow); was der Benutzer darf, entscheiden weiterhin
 * seine Rolle und die Policies. Gespeichert ist nur die SHA-256-Prüfsumme, nie der Klartext.
 *
 * @property int $id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property int|null $site_id
 * @property string $name
 * @property string $token
 * @property list<string>|null $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ApiToken extends PersonalAccessToken
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** @var list<string> */
    public const ABILITIES = [self::READ, self::WRITE];

    protected $table = 'personal_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'site_id'];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
