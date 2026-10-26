<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Models;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Ein Thema (CR-006 FO-05). `replies` zählt die Antworten (ohne Startbeitrag); `last_*` zeigt auf den letzten Beitrag.
 *
 * @property int $id
 * @property int $site_id
 * @property int $node_id
 * @property int|null $customer_id
 * @property string $title
 * @property string $slug
 * @property string $locale
 * @property bool $is_sticky
 * @property bool $is_closed
 * @property bool $is_disabled
 * @property int|null $first_post_id
 * @property int|null $last_post_id
 * @property int|null $last_customer_id
 * @property Carbon|null $last_posted_at
 * @property int $replies
 * @property int $views
 * @property int|null $prefix_id
 * @property int|null $best_post_id
 * @property bool $is_done
 * @property array<string, string>|null $form_values
 * @property bool $is_announcement
 * @property bool $is_featured
 * @property int|null $moved_to_id
 * @property Carbon|null $redirect_until
 * @property string|null $trash_reason
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 */
final class Thread extends Model
{
    use BelongsToSite;
    use SoftDeletes;

    protected $table = 'forum_threads';

    protected $fillable = ['site_id', 'node_id', 'customer_id', 'title', 'slug', 'locale', 'prefix_id', 'form_values', 'is_sticky', 'is_closed', 'is_disabled'];

    protected function casts(): array
    {
        return ['is_sticky' => 'boolean', 'is_closed' => 'boolean', 'is_disabled' => 'boolean', 'is_done' => 'boolean', 'best_post_id' => 'integer', 'is_announcement' => 'boolean', 'is_featured' => 'boolean', 'moved_to_id' => 'integer', 'redirect_until' => 'datetime', 'last_posted_at' => 'datetime', 'replies' => 'integer', 'views' => 'integer', 'form_values' => 'array'];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return HasOne<Poll, $this> */
    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class);
    }

    /** @return BelongsTo<Prefix, $this> */
    public function prefix(): BelongsTo
    {
        return $this->belongsTo(Prefix::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Customer, $this> */
    public function lastCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'last_customer_id')->withoutGlobalScopes();
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** Ein Verweis-Thema (TB 39): steht nach dem Verschieben noch im alten Forum und führt zum Ziel. */
    public function isRedirect(): bool
    {
        return $this->moved_to_id !== null;
    }

    /** Das Stück der Adresse: `12-wie-baue-ich-ein-theme`. */
    public function pathSegment(): string
    {
        return $this->id.'-'.$this->slug;
    }
}
