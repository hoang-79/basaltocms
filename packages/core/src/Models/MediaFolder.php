<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Ordner der Mediathek (K-08) — je Site, beliebig verschachtelt; beim Löschen wandern die Dateien nach «Alle».
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $parent_id
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MediaFolder extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'parent_id', 'name', 'position'];

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    /** @return HasMany<Media, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }
}
