<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine Trophäe (CR-006 FO-K13): automatisch nach einer Regel aus dem Register ({@see CommunityRules}) oder von Hand
 * vergeben. Einmal vergeben, bleibt sie.
 *
 * @property int $id
 * @property int $site_id
 * @property string $title
 * @property string|null $description
 * @property string $icon
 * @property string|null $rule
 * @property int|null $threshold
 * @property bool $is_active
 * @property int $position
 */
final class CommunityTrophy extends Model
{
    use BelongsToSite;

    protected $table = 'community_trophies';

    protected $fillable = ['site_id', 'title', 'description', 'icon', 'rule', 'threshold', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['threshold' => 'integer', 'is_active' => 'boolean', 'position' => 'integer'];
    }
}
