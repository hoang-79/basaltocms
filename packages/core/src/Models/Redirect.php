<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Umleitung je Site und Sprache (M-01): von einem alten Pfad auf einen neuen — automatisch nach Slug-Wechsel oder
 * Verschieben, von Hand oder per Import. Pfade stehen mit Sprachpräfix, wie sie in der Adresszeile vorkommen.
 * Status 301/302 leitet weiter, 410 meldet «gibt es nicht mehr» (dann ohne Ziel).
 *
 * @property int $id
 * @property int $site_id
 * @property string $locale
 * @property string $from_path
 * @property string $to_path
 * @property int $status
 * @property string $source
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Redirect extends Model
{
    use BelongsToSite;

    public const SOURCE_SLUG_CHANGE = 'slug_change';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = ['site_id', 'locale', 'from_path', 'to_path', 'status', 'source', 'hits', 'last_hit_at'];

    protected function casts(): array
    {
        return ['status' => 'integer', 'hits' => 'integer', 'last_hit_at' => 'datetime'];
    }
}
