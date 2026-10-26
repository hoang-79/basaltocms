<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Sprache einer Site: Standard, Pflicht (für die Freigabe) und Fallback-Sprache (R-20).
 *
 * @property int $id
 * @property int $site_id
 * @property string $locale
 * @property bool $is_default
 * @property bool $is_required
 * @property string|null $fallback_locale
 * @property int $sort_order
 */
final class SiteLocale extends Model
{
    protected $fillable = ['site_id', 'locale', 'is_default', 'is_required', 'fallback_locale', 'sort_order'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_required' => 'boolean'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
