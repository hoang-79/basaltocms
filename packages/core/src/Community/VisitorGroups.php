<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Besuchergruppen einer Site (CR-006 FO-K3): die Systemgruppen entstehen bei Bedarf, die Gruppen einer Person ergeben
 * sich aus dem Konto (Gäste, Alle, Mitglieder, Team) und aus den Zuweisungen (Moderation, eigene Gruppen).
 */
final class VisitorGroups
{
    /** @var array<string, array{name: string, badge: string|null, position: int}> */
    public const SYSTEM = [
        VisitorGroup::EVERYONE => ['name' => 'Alle', 'badge' => null, 'position' => 0],
        VisitorGroup::GUESTS => ['name' => 'Gäste', 'badge' => null, 'position' => 1],
        VisitorGroup::MEMBERS => ['name' => 'Mitglieder', 'badge' => null, 'position' => 2],
        VisitorGroup::MODERATORS => ['name' => 'Moderation', 'badge' => 'Moderation', 'position' => 3],
        VisitorGroup::TEAM => ['name' => 'Team', 'badge' => 'Team', 'position' => 4],
    ];

    /** @var array<int, Collection<int, VisitorGroup>> */
    private array $groups = [];

    /** @return Collection<int, VisitorGroup> alle Gruppen der Site, Systemgruppen zuerst — fehlende Systemgruppen werden angelegt */
    public function ensure(Site $site): Collection
    {
        if (isset($this->groups[$site->id])) {
            return $this->groups[$site->id];
        }
        $existing = VisitorGroup::query()->withoutGlobalScopes()->where('site_id', $site->id)->pluck('key')->filter()->all();
        foreach (self::SYSTEM as $key => $group) {
            if (! in_array($key, $existing, true)) {
                VisitorGroup::query()->withoutGlobalScopes()->firstOrCreate(['site_id' => $site->id, 'key' => $key], ['name' => $group['name'], 'badge' => $group['badge'], 'position' => $group['position']]);
            }
        }

        return $this->groups[$site->id] = VisitorGroup::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('position')->orderBy('id')->get();
    }

    public function forget(): void
    {
        $this->groups = [];
    }

    public function byKey(Site $site, string $key): VisitorGroup
    {
        $group = $this->ensure($site)->firstWhere('key', $key);
        assert($group instanceof VisitorGroup);

        return $group;
    }

    /**
     * Die Gruppen einer Person auf der Site (ohne Konto oder gesperrt: Gäste und Alle).
     *
     * @return Collection<int, VisitorGroup>
     */
    public function of(Site $site, ?Customer $customer): Collection
    {
        $groups = $this->ensure($site);
        // TB 39 (FO-17): gesperrt zählt wie ein Gast; CR-011 TB 60: ohne Zustimmung zu den Bedingungen ebenso
        if ($customer === null || app(Sanctions::class)->isBanned($customer) || ! app(CommunityTerms::class)->accepted($site, $customer)) {
            return $groups->filter(static fn (VisitorGroup $g): bool => in_array($g->key, [VisitorGroup::GUESTS, VisitorGroup::EVERYONE], true))->values();
        }
        $assigned = DB::table('visitor_group_customer')->where('customer_id', $customer->id)->pluck('visitor_group_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $team = CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->whereNotNull('user_id')->exists();

        return $groups->filter(static fn (VisitorGroup $g): bool => in_array($g->key, [VisitorGroup::EVERYONE, VisitorGroup::MEMBERS], true)
            || ($g->key === VisitorGroup::TEAM && $team) || ($g->key !== VisitorGroup::GUESTS && in_array($g->id, $assigned, true)))->values();
    }

    /** TB 52: Recht, ganze Gruppen zu erwähnen (`@Moderation`). */
    public const MENTION_PERMISSION = 'community.mention_groups';

    /**
     * Gruppen, die sich erwähnen lassen — nicht «Alle», «Gäste» oder «Mitglieder» (zu breit).
     *
     * @return Collection<int, VisitorGroup>
     */
    public function mentionable(Site $site): Collection
    {
        return $this->ensure($site)->reject(static fn (VisitorGroup $g): bool => in_array($g->key, [VisitorGroup::EVERYONE, VisitorGroup::GUESTS, VisitorGroup::MEMBERS], true))->values();
    }

    /**
     * Die Konten einer Gruppe (Team: wer ein Team-Konto hat; sonst die Zuordnungen), höchstens `$limit`.
     *
     * @return list<int>
     */
    public function members(Site $site, VisitorGroup $group, int $limit = 100): array
    {
        $ids = $group->key === VisitorGroup::TEAM
            ? CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNotNull('user_id')->whereNotNull('customer_id')->limit($limit)->pluck('customer_id')
            : DB::table('visitor_group_customer')->where('visitor_group_id', $group->id)->limit($limit)->pluck('customer_id');

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids->all()));
    }

    /** Das sichtbare Abzeichen einer Person (Team vor Moderation vor eigenen Gruppen) oder null. */
    public function badge(Site $site, ?Customer $customer): ?string
    {
        if ($customer === null) {
            return null;
        }
        $groups = $this->of($site, $customer)->filter(static fn (VisitorGroup $g): bool => is_string($g->badge) && $g->badge !== '');
        $team = $groups->firstWhere('key', VisitorGroup::TEAM) ?? $groups->firstWhere('key', VisitorGroup::MODERATORS) ?? $groups->first();

        return $team === null ? null : (string) $team->badge;
    }
}
