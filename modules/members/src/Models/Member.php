<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Models;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Sites\BelongsToSite;
use Basalto\Modules\Members\MemberStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Mitgliedschaft (M-23): ein Frontend-Konto der Site ist Mitglied — wartend, aktiv oder gesperrt.
 *
 * @property int $id
 * @property int $site_id
 * @property int $customer_id
 * @property MemberStatus $status
 * @property Carbon|null $invited_at
 * @property int|null $invited_by
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $blocked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 */
final class Member extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'customer_id', 'status', 'invited_at', 'invited_by', 'approved_at', 'approved_by', 'blocked_at'];

    protected function casts(): array
    {
        return ['status' => MemberStatus::class, 'invited_at' => 'datetime', 'approved_at' => 'datetime', 'blocked_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }
}
