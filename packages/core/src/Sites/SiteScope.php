<?php

declare(strict_types=1);

namespace Basalto\Core\Sites;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope (K-02): Ist eine Site gesetzt, sieht jede Abfrage nur deren Datensätze. `forAllSites()` hebt ihn bewusst auf.
 *
 * @implements Scope<Model>
 */
final class SiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $siteId = app(CurrentSite::class)->id();
        if ($siteId !== null) {
            $builder->where($model->qualifyColumn('site_id'), $siteId);
        }
    }

    /** @param  Builder<Model>  $builder */
    public function extend(Builder $builder): void
    {
        $builder->macro('forAllSites', fn (Builder $builder): Builder => $builder->withoutGlobalScope(self::class));
    }
}
