<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Zustimmung eines Kundenkontos zu den Bedingungen der Community (CR-011 TB 60): Fassung, Zeitpunkt, Widerruf.
 *
 * @property int $id
 * @property int $site_id
 * @property int $customer_id
 * @property string $version
 * @property CarbonImmutable $accepted_at
 * @property ?CarbonImmutable $revoked_at
 */
final class CommunityTermsConsent extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'customer_id', 'version', 'accepted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
