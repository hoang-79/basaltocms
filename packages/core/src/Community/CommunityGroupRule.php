<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Regel der automatischen Gruppenzuordnung (CR-006 FO-K13): wer die Regel erfüllt, kommt in die Gruppe; wer sie
 * nicht mehr erfüllt, verlässt sie wieder (nur Mitgliedschaften, die die Regel selbst gesetzt hat).
 *
 * @property int $id
 * @property int $site_id
 * @property int $visitor_group_id
 * @property string $rule
 * @property int $threshold
 * @property bool $is_active
 * @property Carbon|null $last_run_at
 */
final class CommunityGroupRule extends Model
{
    use BelongsToSite;

    protected $table = 'community_group_rules';

    protected $fillable = ['site_id', 'visitor_group_id', 'rule', 'threshold', 'is_active'];

    protected function casts(): array
    {
        return ['threshold' => 'integer', 'is_active' => 'boolean', 'visitor_group_id' => 'integer', 'last_run_at' => 'datetime'];
    }
}
