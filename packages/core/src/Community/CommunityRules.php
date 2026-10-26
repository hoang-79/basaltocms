<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Models\Site;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Register der Regeln für Trophäen und die automatische Gruppenzuordnung (CR-006 FO-K13). Eine Regel liefert die
 * Konten einer Site, die sie mit einer Schwelle erfüllen — der Kern bringt Beiträge, erhaltene Reaktionen und
 * Mitgliedsdauer mit, Module melden eigene an (Forum: hilfreichste Antworten; Lizenzverkauf: «hat Lizenz»; Marktplatz:
 * «ist Autor»). Beim Booten gefüllt (Singleton), danach nur gelesen.
 *
 * @phpstan-type Rule array{label: string, help: string, threshold: bool, find: Closure(Site, int): list<int>}
 */
final class CommunityRules
{
    /** @var array<string, Rule> */
    private array $rules = [];

    public function __construct()
    {
        $this->define('posts', 'Beiträge', 'Mindestens so viele sichtbare Beiträge.', static fn (Site $site, int $n): array => self::ids(
            CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('posts', '>=', $n)->pluck('customer_id')->all()));
        $this->define('reactions', 'Erhaltene Reaktionen', 'Mindestens so viele Reaktionen auf eigene Beiträge.', static fn (Site $site, int $n): array => self::ids(
            CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('reactions_received', '>=', $n)->pluck('customer_id')->all()));
        $this->define('member_days', 'Mitglied seit (Tagen)', 'Das Community-Profil besteht seit mindestens so vielen Tagen.', static fn (Site $site, int $n): array => self::ids(
            CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('created_at', '<=', now()->subDays($n))->pluck('customer_id')->all()));
        $this->define('followers', 'Follower', 'Mindestens so viele Mitglieder folgen der Person.', static fn (Site $site, int $n): array => self::ids(
            DB::table('community_follows')->where('site_id', $site->id)->groupBy('followed_id')->havingRaw('count(*) >= ?', [max(1, $n)])->pluck('followed_id')->all()));
    }

    /**
     * @param  Closure(Site, int): list<int>  $find
     * @param  bool  $threshold  braucht die Regel eine Zahl? («hat Lizenz» nicht)
     */
    public function define(string $key, string $label, string $help, Closure $find, bool $threshold = true): self
    {
        $this->rules[$key] = ['label' => $label, 'help' => $help, 'threshold' => $threshold, 'find' => $find];

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->rules[$key]);
    }

    /** @return array<string, array{label: string, help: string, threshold: bool}> */
    public function all(): array
    {
        return array_map(static fn (array $r): array => ['label' => $r['label'], 'help' => $r['help'], 'threshold' => $r['threshold']], $this->rules);
    }

    /** @return list<int> die Konten, die die Regel heute erfüllen (nur solche mit Community-Profil) */
    public function matching(Site $site, string $key, int $threshold): array
    {
        $rule = $this->rules[$key] ?? null;
        if ($rule === null) {
            return [];
        }
        $ids = ($rule['find'])($site, $threshold);
        if ($ids === []) {
            return [];
        }

        return self::ids(CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('customer_id', $ids)->pluck('customer_id')->all());
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    public static function ids(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
