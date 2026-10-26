<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Eintrag auf der Pinnwand eines Profils (TB 52): wer ihn geschrieben hat (`customer_id`, leer nach dem Löschen
 * der Person) und der Text als gesäubertes Dokument der Editor-Insel.
 *
 * @property int $id
 * @property int $site_id
 * @property int $profile_id
 * @property int|null $customer_id
 * @property array<string, mixed> $body
 * @property Carbon|null $created_at
 */
final class CommunityWallPost extends Model
{
    use BelongsToSite;

    protected $table = 'community_wall_posts';

    protected $fillable = ['site_id', 'profile_id', 'customer_id', 'body'];

    protected function casts(): array
    {
        return ['body' => 'array'];
    }
}
