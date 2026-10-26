<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Entwurf einer Theme-Datei (L-17): liegt neben der Live-Quelle in `lava_sources`, bis er veröffentlicht wird.
 * `source = null` heisst: die Datei fällt mit der Veröffentlichung weg.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $site_theme_id
 * @property string $path
 * @property string|null $source
 * @property string|null $base_hash
 * @property int|null $updated_by
 * @property Carbon|null $submitted_at
 * @property int|null $submitted_by
 * @property Carbon|null $updated_at
 */
final class ThemeDraft extends Model
{
    protected $fillable = ['site_id', 'site_theme_id', 'path', 'source', 'base_hash', 'updated_by', 'submitted_at', 'submitted_by'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }
}
