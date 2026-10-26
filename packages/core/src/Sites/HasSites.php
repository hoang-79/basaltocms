<?php

declare(strict_types=1);

namespace Basalto\Core\Sites;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Für das Benutzermodell der Anwendung: Sites mit Rolle (K-01 Benutzerzuordnung). */
trait HasSites
{
    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_user')->withPivot('role')->withTimestamps()->orderBy('name');
    }

    /** @return BelongsToMany<Account, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_user')->withPivot('role')->withTimestamps()->orderBy('name');
    }

    public function roleFor(Site|int $site): ?SiteRole
    {
        $site = $site instanceof Site ? $site : Site::query()->find($site);

        return $site?->roleOf($this);
    }

    public function isMemberOf(Site|int $site): bool
    {
        $site = $site instanceof Site ? $site : Site::query()->find($site);

        return $site?->roleKeyOf($this) !== null;
    }

    public function canOn(Site|int $site, Permission $permission): bool
    {
        return $this->roleFor($site)?->can($permission) ?? false;
    }
}
