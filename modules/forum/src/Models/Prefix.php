<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Präfix (CR-006 FO-09, bei WoltLab «Label»): kurzes Schild vor dem Titel eines Themas, z. B. «Frage», «Gelöst»,
 * «Fehler». Je Forum wählbar, auf Wunsch Pflicht. Farbe aus einer festen Palette, damit jedes Theme sie lesbar zeigt.
 *
 * @property int $id
 * @property int $site_id
 * @property array<string, string> $label
 * @property string $color
 * @property int $position
 */
final class Prefix extends Model
{
    use BelongsToSite;

    public const COLORS = ['grey', 'lava', 'green', 'blue', 'yellow', 'red'];

    protected $table = 'forum_prefixes';

    protected $fillable = ['site_id', 'label', 'color', 'position'];

    protected function casts(): array
    {
        return ['label' => 'array', 'position' => 'integer'];
    }

    public function labelIn(string $locale): string
    {
        $value = $this->label[$locale] ?? null;
        if ($value !== null && trim($value) !== '') {
            return $value;
        }
        foreach ($this->label as $other) {
            if (trim($other) !== '') {
                return $other;
            }
        }

        return '';
    }
}
