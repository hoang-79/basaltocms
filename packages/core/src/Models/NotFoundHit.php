<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Pfad, der 404 antwortete (M-10): je Site einmal, mit Zähler, erstem und letztem Treffer und der zuletzt
 * verweisenden Seite. Aus einer Zeile wird mit einem Klick eine Umleitung — dann verschwindet sie.
 *
 * @property int $id
 * @property int $site_id
 * @property string $path
 * @property string $locale
 * @property int $hits
 * @property string|null $referrer
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotFoundHit extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'path', 'locale', 'hits', 'referrer', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
