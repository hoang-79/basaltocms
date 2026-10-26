<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Level;
use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\ExtranetMember;
use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Wer eine Benachrichtigung bekommt (TB 27): Mitglieder einer Site (dazu Besitzer und Admins des Kontos) mit einem
 * Bereich in mindestens der verlangten Stufe — oder das Team des Herstellers im Extranet. Deaktivierte nie.
 */
final class Recipients
{
    /** @return Collection<int, Model&Authenticatable> */
    public static function site(Site $site, ?string $area = null, Level $level = Level::View): Collection
    {
        $members = $site->users()->get();
        $account = $site->account;
        if ($account !== null) {
            $members = $members->merge($account->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])->get());
        }
        $access = app(Access::class);

        return $members->unique(static fn (Model $user): mixed => $user->getKey())
            ->filter(static fn (Model $user): bool => $user->getAttribute('deactivated_at') === null && $user instanceof Authenticatable
                && ($area === null ? $access->definition($user, $site) !== null : $access->allows($user, $site, $area, $level)))
            ->values()->toBase();
    }

    /**
     * Für Dinge der ganzen Installation (Sicherung, Updates): wer auf einer Site des Installationskontos den Bereich hat.
     *
     * @return Collection<int, Model&Authenticatable>
     */
    public static function installation(?string $area = null, Level $level = Level::View): Collection
    {
        $users = collect();
        foreach (Site::query()->where('account_id', Account::installation()->id)->get() as $site) {
            $users = $users->merge(self::site($site, $area, $level));
        }

        return $users->unique(static fn (Model $user): mixed => $user->getKey())->values();
    }

    /** @return Collection<int, Model&Authenticatable> */
    public static function extranet(?string $area = null, Level $level = Level::View): Collection
    {
        $model = config('auth.providers.users.model');
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            return collect();
        }
        $ids = Account::installation()->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])->pluck('users.id')
            ->merge(ExtranetMember::query()->pluck('user_id'))->unique()->all();
        $access = app(ExtranetAccess::class);

        return $model::query()->whereKey($ids)->get()
            ->filter(static fn (Model $user): bool => $user->getAttribute('deactivated_at') === null && $user instanceof Authenticatable
                && ($area === null ? $access->isMember($user) : $access->allows($user, $area, $level)))
            ->values()->toBase();
    }
}
