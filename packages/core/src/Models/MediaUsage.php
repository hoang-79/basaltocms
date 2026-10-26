<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verwendungsnachweis (K-08): welches Medium in welcher Sprachfassung eines Inhalts in welchem Feld eingebunden ist —
 * nachgeführt bei jedem Speichern einer Fassung.
 *
 * @property int $id
 * @property int $media_id
 * @property int $entry_id
 * @property string $locale
 * @property string $field
 */
final class MediaUsage extends Model
{
    protected $fillable = ['media_id', 'entry_id', 'locale', 'field'];

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
