<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Models;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Ein Beitrag (CR-006 FO-05). Der Text ist ein TipTap-Dokument, gesäubert mit der Positivliste des Kerns.
 *
 * @property int $id
 * @property int $site_id
 * @property int $thread_id
 * @property int|null $customer_id
 * @property array<string, mixed> $body
 * @property bool $is_disabled
 * @property bool $is_official
 * @property bool $is_edit_locked
 * @property int $reactions
 * @property Carbon|null $edited_at
 * @property int $edit_count
 * @property string|null $edit_reason
 * @property int|null $edited_by
 * @property string|null $ip_hash
 * @property string|null $trash_reason
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 */
final class Post extends Model
{
    use BelongsToSite;
    use SoftDeletes;

    protected $table = 'forum_posts';

    protected $fillable = ['site_id', 'thread_id', 'customer_id', 'body', 'ip_hash'];

    protected function casts(): array
    {
        return ['body' => 'array', 'is_disabled' => 'boolean', 'is_official' => 'boolean', 'is_edit_locked' => 'boolean', 'reactions' => 'integer', 'edited_at' => 'datetime', 'edit_count' => 'integer'];
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return HasMany<Revision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }
}
