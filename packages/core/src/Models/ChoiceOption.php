<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Wert einer Auswahlliste, den die Site ergänzt oder gepflegt hat (CR-022) — siehe `Choices\Choices`.
 *
 * @property int $id
 * @property int $site_id
 * @property string $list
 * @property string $value
 * @property string|null $label
 * @property string|null $group
 * @property int|null $position
 * @property Carbon|null $hidden_at
 * @property int|null $created_by
 */
final class ChoiceOption extends Model
{
    protected $table = 'choice_options';

    protected $fillable = ['site_id', 'list', 'value', 'label', 'group', 'position', 'hidden_at', 'created_by'];

    protected function casts(): array
    {
        return ['site_id' => 'integer', 'position' => 'integer', 'hidden_at' => 'datetime', 'created_by' => 'integer'];
    }
}
