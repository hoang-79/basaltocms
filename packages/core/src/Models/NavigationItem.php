<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein freier Menüeintrag (M-07): gehört zu einem Menü des Themes (`nav`), zeigt auf eine Seite oder eine URL,
 * trägt die Beschriftung je Sprache (leer = Titel der Seite bzw. Standardsprache).
 *
 * @property int $id
 * @property int $site_id
 * @property string $nav
 * @property int|null $parent_id
 * @property int $position
 * @property int|null $entry_id
 * @property string|null $url
 * @property array<string, string>|null $label
 * @property bool $visible
 * @property bool $new_window
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NavigationItem extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'nav', 'parent_id', 'position', 'entry_id', 'url', 'label', 'visible', 'new_window'];

    protected function casts(): array
    {
        return ['label' => 'array', 'visible' => 'boolean', 'new_window' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * Beschriftung in einer Sprache entlang der Fallback-Kette — null, wenn keine Sprache eine hat.
     *
     * @param  list<string>  $chain
     */
    public function labelFor(array $chain): ?string
    {
        foreach ($chain as $locale) {
            $label = $this->label[$locale] ?? null;
            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        return null;
    }
}
