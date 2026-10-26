<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Models;

use Basalto\Core\Models\Entry;
use Basalto\Core\Sites\BelongsToSite;
use Basalto\Modules\Comments\CommentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Kommentar an einer Seite oder einem Beitrag (M-27): Name und Kommentar öffentlich, E-Mail nie, Website auf
 * Wunsch; IP nur als Prüfsumme (HMAC mit dem App-Schlüssel, wie Formulareingänge), Zeitpunkt der Zustimmung.
 * Antworten hängen über `parent_id` an einem Kommentar der ersten Ebene; Antworten aus dem Backend tragen `user_id`.
 *
 * @property int $id
 * @property int $site_id
 * @property int $entry_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $locale
 * @property string $author_name
 * @property string|null $author_email
 * @property string|null $author_url
 * @property string $body
 * @property CommentStatus $status
 * @property string|null $ip_hash
 * @property Carbon|null $consent_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Comment extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'entry_id', 'parent_id', 'user_id', 'locale', 'author_name', 'author_email', 'author_url', 'body', 'status', 'ip_hash', 'consent_at', 'notified_at'];

    protected function casts(): array
    {
        return ['status' => CommentStatus::class, 'consent_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Die IP als Prüfsumme — nie die Adresse selbst (N-05). */
    public static function ipHash(?string $ip): string
    {
        return hash_hmac('sha256', (string) $ip, (string) config('app.key'));
    }
}
