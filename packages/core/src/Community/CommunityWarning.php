<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Verwarnung (CR-006 FO-17): Grund, Punkte, Ablauf. Die gültigen Punkte eines Kontos lösen Schreibsperre oder
 * Sperre aus ({@see Warnings}); zurückgenommene oder abgelaufene zählen nicht mehr.
 *
 * @property int $id
 * @property int $site_id
 * @property int $customer_id
 * @property string $reason
 * @property int $points
 * @property string|null $note
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $issued_by
 * @property int|null $issued_by_customer
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
final class CommunityWarning extends Model
{
    use BelongsToSite;

    protected $table = 'community_warnings';

    protected $fillable = ['site_id', 'customer_id', 'reason', 'points', 'note', 'subject_type', 'subject_id', 'issued_by', 'issued_by_customer', 'expires_at'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
