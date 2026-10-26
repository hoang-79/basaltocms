<?php

declare(strict_types=1);

namespace Basalto\Core\Sites;

use Basalto\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Für Modelle mit `site_id`: Scope auf die aktuelle Site, Site beim Anlegen setzen, Relation. */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope(new SiteScope);
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('site_id') === null) {
                $model->setAttribute('site_id', app(CurrentSite::class)->id());
            }
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
