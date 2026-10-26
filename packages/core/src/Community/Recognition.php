<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ränge, Trophäen und automatische Gruppen (CR-006 FO-20): Ränge rechnet die Anzeige aus Beitragszahl und Gruppe;
 * Trophäen und Gruppen setzt der tägliche Lauf (`basalto:community-rules`) oder der Knopf im Extranet. Trophäen
 * bleiben, Gruppen aus Regeln gehen wieder, wenn die Regel nicht mehr greift.
 */
final class Recognition
{
    public const NOTIFICATION = 'community.trophy';

    public function __construct(private readonly CommunityRules $rules) {}

    /** @return array{trophies: int, joined: int, left: int} */
    public function run(Site $site): array
    {
        $result = ['trophies' => 0, 'joined' => 0, 'left' => 0];
        foreach (CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('is_active', true)->whereNotNull('rule')->get() as $trophy) {
            $have = DB::table('community_customer_trophies')->where('trophy_id', $trophy->id)->pluck('customer_id')->map(static fn (mixed $id): int => (int) $id)->all();
            foreach (array_diff($this->rules->matching($site, (string) $trophy->rule, (int) $trophy->threshold), $have) as $customerId) {
                $this->award($site, $trophy, (int) $customerId);
                $result['trophies']++;
            }
        }
        foreach (CommunityGroupRule::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('is_active', true)->get() as $rule) {
            $match = $this->rules->matching($site, $rule->rule, $rule->threshold);
            $members = DB::table('visitor_group_customer')->where('visitor_group_id', $rule->visitor_group_id)->get();
            $inGroup = $members->pluck('customer_id')->map(static fn (mixed $id): int => (int) $id)->all();
            foreach (array_diff($match, $inGroup) as $customerId) {
                DB::table('visitor_group_customer')->insert(['visitor_group_id' => $rule->visitor_group_id, 'customer_id' => $customerId, 'rule_id' => $rule->id, 'created_at' => now()]);
                $result['joined']++;
            }
            $leaving = $members->filter(static fn (object $m): bool => (int) $m->rule_id === $rule->id && ! in_array((int) $m->customer_id, $match, true))->pluck('customer_id')->all();
            if ($leaving !== []) {
                $result['left'] += DB::table('visitor_group_customer')->where('visitor_group_id', $rule->visitor_group_id)->where('rule_id', $rule->id)->whereIn('customer_id', $leaving)->delete();
            }
            $rule->forceFill(['last_run_at' => now()])->save();
        }
        app(VisitorGroups::class)->forget();

        return $result;
    }

    /** Wie viele Mitglieder die Regel heute erfüllen — Vorschau im Extranet. */
    public function preview(Site $site, string $rule, int $threshold): int
    {
        return count($this->rules->matching($site, $rule, $threshold));
    }

    public function award(Site $site, CommunityTrophy $trophy, int $customerId, ?Authenticatable $by = null): bool
    {
        $inserted = DB::table('community_customer_trophies')->insertOrIgnore(['trophy_id' => $trophy->id, 'customer_id' => $customerId, 'awarded_at' => now(),
            'awarded_by' => is_numeric($by?->getAuthIdentifier()) ? (int) $by->getAuthIdentifier() : null]) > 0;
        if ($inserted) {
            $customer = Customer::query()->withoutGlobalScopes()->find($customerId);
            if ($customer !== null) {
                Notify::make(self::NOTIFICATION)->site($site)->title('Neue Trophäe: :icon :title', ['icon' => $trophy->icon, 'title' => $trophy->title])->to($customer)->send();
            }
        }

        return $inserted;
    }

    /**
     * Trophäen je Konto, für die Anzeige an Beiträgen und im Profil.
     *
     * @param  list<int>  $customerIds
     * @return array<int, list<CommunityTrophy>>
     */
    public function trophiesOf(Site $site, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $trophies = CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('position')->orderBy('id')->get()->keyBy('id');
        $out = [];
        foreach (DB::table('community_customer_trophies')->whereIn('customer_id', $customerIds)->orderBy('awarded_at')->get() as $row) {
            $trophy = $trophies->get((int) $row->trophy_id);
            if ($trophy !== null) {
                $out[(int) $row->customer_id][] = $trophy;
            }
        }

        return $out;
    }

    /**
     * Der Rang je Konto: der höchste, dessen Schwelle erreicht ist und dessen Gruppe (wenn gesetzt) die Person hat.
     *
     * @param  Collection<int, CommunityProfile>  $profiles  nach customer_id
     * @return array<int, CommunityRank>
     */
    public function ranksOf(Site $site, Collection $profiles): array
    {
        $ranks = CommunityRank::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderByDesc('min_posts')->orderBy('id')->get();
        if ($ranks->isEmpty() || $profiles->isEmpty()) {
            return [];
        }
        $groups = [];
        foreach (DB::table('visitor_group_customer')->whereIn('customer_id', $profiles->keys())->get() as $row) {
            $groups[(int) $row->customer_id][] = (int) $row->visitor_group_id;
        }
        $team = app(VisitorGroups::class)->byKey($site, VisitorGroup::TEAM)->id;
        $out = [];
        foreach ($profiles as $customerId => $profile) {
            $mine = [...($groups[$customerId] ?? []), ...($profile->isTeam() ? [$team] : [])];
            $rank = $ranks->first(static fn (CommunityRank $r): bool => $profile->posts >= $r->min_posts && ($r->visitor_group_id === null || in_array($r->visitor_group_id, $mine, true)));
            if ($rank !== null) {
                $out[(int) $customerId] = $rank;
            }
        }

        return $out;
    }
}
