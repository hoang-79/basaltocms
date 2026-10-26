<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Antwortmöglichkeit einer Umfrage mit ihrer Stimmenzahl.
 *
 * @property int $id
 * @property int $poll_id
 * @property string $label
 * @property int $position
 * @property int $votes
 */
final class PollOption extends Model
{
    public $timestamps = false;

    protected $table = 'forum_poll_options';

    protected $fillable = ['poll_id', 'label', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'votes' => 'integer'];
    }
}
