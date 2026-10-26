<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Reaktionen (CR-006 FO-K8): eine je Konto und Inhalt, eine Art aus einer festen Auswahl. Erneut dieselbe Art nimmt sie
 * zurück, eine andere ersetzt sie. Das Modul des Inhalts erfährt es (`Subjects::reacted`) und pflegt Zähler und
 * Benachrichtigungen. Eigene Inhalte lassen sich nicht bewerten.
 */
final class Reactions
{
    /** Art → Zeichen. Die Bezeichnung kommt übersetzt aus {@see label()}. */
    public const TYPES = ['like' => '👍', 'love' => '❤️', 'haha' => '😂', 'wow' => '😮', 'sad' => '😢', 'thanks' => '🙏'];

    public function __construct(private readonly Subjects $subjects) {}

    /**
     * TB 52: die Arten, die die Site anbietet (Einstellung `community.reactions`, Vorgabe: alle) — in fester Reihenfolge.
     *
     * @return array<string, string>
     */
    public static function enabled(?Site $site): array
    {
        $chosen = app(Settings::class)->get('community.reactions', array_keys(self::TYPES), $site);
        $chosen = is_array($chosen) ? array_map('strval', $chosen) : array_keys(self::TYPES);
        $types = array_filter(self::TYPES, static fn (string $type): bool => in_array($type, $chosen, true), ARRAY_FILTER_USE_KEY);

        return $types === [] ? self::TYPES : $types;
    }

    public static function label(string $type, ?string $lang = null): string
    {
        return (string) match ($type) {
            'like' => __('Gefällt mir', [], $lang),
            'love' => __('Super', [], $lang),
            'haha' => __('Lustig', [], $lang),
            'wow' => __('Wow', [], $lang),
            'sad' => __('Traurig', [], $lang),
            default => __('Danke', [], $lang),
        };
    }

    /** @return string|null die Reaktion nach dem Umschalten (null = keine mehr) */
    public function toggle(Customer $customer, string $subjectType, int $subjectId, string $reaction): ?string
    {
        $where = ['customer_id' => $customer->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
        $current = DB::table('community_reactions')->where($where)->value('reaction');
        $owner = $this->subjects->owner($subjectType, $subjectId);
        if ($current === $reaction) {
            DB::table('community_reactions')->where($where)->delete();
            $this->count($owner, -1);
            $this->subjects->reacted($subjectType, $subjectId, $customer, null);

            return null;
        }
        DB::table('community_reactions')->updateOrInsert($where, ['site_id' => $customer->site_id, 'reaction' => $reaction, 'created_at' => now()]);
        if ($current === null) {
            $this->count($owner, 1);
        }
        $this->subjects->reacted($subjectType, $subjectId, $customer, $reaction);

        return $reaction;
    }

    /**
     * Zählung je Inhalt und Art.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    public function summary(string $subjectType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('community_reactions')->where('subject_type', $subjectType)->whereIn('subject_id', $ids)
            ->selectRaw('subject_id, reaction, count(*) as n')->groupBy('subject_id', 'reaction')->get() as $row) {
            $out[(int) $row->subject_id][(string) $row->reaction] = (int) $row->n;
        }

        return $out;
    }

    /**
     * Die eigene Reaktion je Inhalt.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function mine(?Customer $customer, string $subjectType, array $ids): array
    {
        if ($customer === null || $ids === []) {
            return [];
        }

        return array_map('strval', DB::table('community_reactions')->where(['customer_id' => $customer->id, 'subject_type' => $subjectType])
            ->whereIn('subject_id', $ids)->pluck('reaction', 'subject_id')->all());
    }

    private function count(?int $owner, int $delta): void
    {
        if ($owner === null) {
            return;
        }
        $query = CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $owner);
        $delta > 0 ? $query->increment('reactions_received') : $query->where('reactions_received', '>', 0)->decrement('reactions_received');
    }
}
