<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRank;
use Basalto\Core\Community\CommunityTrophy;
use Basalto\Core\Community\Recognition;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Die Personen einer Ansicht in einem Zug: Profile und Abzeichen (Team, Moderation, eigene Gruppen) für eine Menge
 * von Konten — statt je Beitrag nachzuladen.
 */
final class People
{
    /** @var array<int, CommunityProfile> */
    private array $profiles = [];

    /** @var array<int, string> */
    private array $badges = [];

    /** @var array<int, Customer> */
    private array $customers = [];

    /** @var array<int, CommunityRank> TB 40 */
    private array $ranks = [];

    /** @var array<int, list<CommunityTrophy>> */
    private array $trophies = [];

    /** @param  array<mixed>  $customerIds  Kennungen der Konten (leere werden übergangen) */
    public function __construct(private readonly Site $site, array $customerIds)
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, array_filter($customerIds, static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id))))));
        if ($ids === []) {
            return;
        }
        $this->profiles = CommunityProfile::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->get()->keyBy('customer_id')->all();
        $this->customers = Customer::query()->withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id')->all();
        $groups = app(VisitorGroups::class)->ensure($site)->filter(static fn (VisitorGroup $g): bool => is_string($g->badge) && $g->badge !== '' && $g->isAssignable());
        if ($groups->isNotEmpty()) {
            $rows = DB::table('visitor_group_customer')->whereIn('customer_id', $ids)->whereIn('visitor_group_id', $groups->modelKeys())->get();
            foreach ($rows as $row) {
                $this->badges[(int) $row->customer_id] ??= (string) $groups->firstWhere('id', (int) $row->visitor_group_id)?->badge;
            }
        }
        $this->ranks = app(Recognition::class)->ranksOf($site, collect($this->profiles));
        $this->trophies = app(Recognition::class)->trophiesOf($site, $ids);
        foreach ($this->profiles as $customerId => $profile) {
            if ($profile->isTeam()) {
                $this->badges[$customerId] = (string) (app(VisitorGroups::class)->byKey($site, VisitorGroup::TEAM)->badge ?? 'Team');
            }
        }
    }

    public function profile(?int $customerId): ?CommunityProfile
    {
        return $customerId === null ? null : ($this->profiles[$customerId] ?? null);
    }

    public function name(?int $customerId, string $lang): string
    {
        if ($customerId === null) {
            return (string) __('Ehemaliges Mitglied', [], $lang);
        }
        $profile = $this->profiles[$customerId] ?? null;
        if ($profile !== null) {
            return $profile->name;
        }
        $customer = $this->customers[$customerId] ?? null;

        return $customer !== null && is_string($customer->name) && trim($customer->name) !== '' ? trim($customer->name) : (string) __('Mitglied', [], $lang);
    }

    public function rank(?int $customerId): ?CommunityRank
    {
        return $customerId === null ? null : ($this->ranks[$customerId] ?? null);
    }

    /** @return list<CommunityTrophy> */
    public function trophies(?int $customerId): array
    {
        return $customerId === null ? [] : ($this->trophies[$customerId] ?? []);
    }

    /** Der Name als Verweis aufs Profil (TB 40) — ohne Profil nur der Name. */
    public function link(?int $customerId, string $lang, callable $url): string
    {
        $profile = $this->profile($customerId);
        $name = Markup::e($this->name($customerId, $lang));

        return $profile === null ? $name : sprintf('<a href="%s">%s</a>', Markup::e($url('mitglied/'.$profile->pathSegment())), $name);
    }

    public function badge(?int $customerId): ?string
    {
        return $customerId === null ? null : ($this->badges[$customerId] ?? null);
    }

    public function isTeam(?int $customerId): bool
    {
        return $this->profile($customerId)?->isTeam() ?? false;
    }

    public function avatar(?int $customerId, string $lang, string $size = ''): string
    {
        return Markup::avatar($this->profile($customerId), $this->name($customerId, $lang), $size);
    }
}
