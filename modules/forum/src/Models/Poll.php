<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eine Umfrage am Startbeitrag (CR-006 FO-12): Frage, Optionen, wie viele man wählen darf, ein Ende und ob das Ergebnis
 * erst nach der eigenen Stimme sichtbar ist.
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property string $question
 * @property int $max_choices
 * @property bool $results_after_vote
 * @property Carbon|null $ends_at
 * @property int $voters
 */
final class Poll extends Model
{
    use BelongsToSite;

    protected $table = 'forum_polls';

    protected $fillable = ['site_id', 'thread_id', 'question', 'max_choices', 'results_after_vote', 'ends_at'];

    protected function casts(): array
    {
        return ['max_choices' => 'integer', 'results_after_vote' => 'boolean', 'ends_at' => 'datetime', 'voters' => 'integer'];
    }

    /** @return HasMany<PollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->ends_at === null || $this->ends_at->isFuture();
    }
}
