<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein gesetzter Wert eines Besucherrechts (CR-006 FO-K3) — global (`subject_type` leer) oder für ein Objekt.
 *
 * @property int $id
 * @property int $site_id
 * @property int $visitor_group_id
 * @property string $permission
 * @property string $subject_type
 * @property int $subject_id
 * @property int $value
 */
final class VisitorPermission extends Model
{
    use BelongsToSite;

    public const YES = 1;

    public const NO = 0;

    public const NEVER = -1;

    protected $table = 'visitor_permissions';

    protected $fillable = ['site_id', 'visitor_group_id', 'permission', 'subject_type', 'subject_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'subject_id' => 'integer'];
    }
}
