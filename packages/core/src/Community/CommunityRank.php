<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Rang (CR-006 FO-K13): Titel ab einer Zahl Beiträge, wahlweise nur für eine Gruppe.
 *
 * @property int $id
 * @property int $site_id
 * @property string $title
 * @property int|null $visitor_group_id
 * @property int $min_posts
 * @property string $color
 * @property string|null $icon
 */
final class CommunityRank extends Model
{
    use BelongsToSite;

    public const COLORS = ['grey', 'lava', 'green', 'blue', 'yellow', 'red'];

    protected $table = 'community_ranks';

    protected $fillable = ['site_id', 'title', 'visitor_group_id', 'min_posts', 'color', 'icon'];

    protected function casts(): array
    {
        return ['min_posts' => 'integer', 'visitor_group_id' => 'integer'];
    }
}
