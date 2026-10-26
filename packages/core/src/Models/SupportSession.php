<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Befristeter Support-Zugriff des Herstellers in eine Kunden-Site (CR-004, P-16): nur lesend, Grund Pflicht, beim
 * Kunden als Band sichtbar und im Protokoll der Site.
 *
 * @property int $id
 * @property int $site_id
 * @property int $user_id
 * @property string $reason
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property-read Site|null $site
 */
final class SupportSession extends Model
{
    protected $fillable = ['site_id', 'user_id', 'reason', 'expires_at', 'ended_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
