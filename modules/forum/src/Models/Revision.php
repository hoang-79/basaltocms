<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Models;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine frühere Fassung eines Beitrags (CR-006 FO-08): Text (und beim Startbeitrag der Titel) **vor** einer Änderung,
 * wer geändert hat und warum.
 *
 * @property int $id
 * @property int $site_id
 * @property int $post_id
 * @property int|null $customer_id
 * @property array<string, mixed> $body
 * @property string|null $title
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
final class Revision extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    protected $table = 'forum_post_revisions';

    protected $fillable = ['site_id', 'post_id', 'customer_id', 'body', 'title', 'reason'];

    protected function casts(): array
    {
        return ['body' => 'array'];
    }
}
