<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Meldungen für jeden Inhaltstyp (CR-006 FO-K9, FO-14). Ein Modul meldet seinen Typ einmal an — mit Bezeichnung, einer
 * Beschreibung für die Moderationsliste und dem vorläufigen Ausblenden ab der Schwelle offener Meldungen. Gemeldet wird
 * je Person einmal (Kundenkonto oder HMAC der Adresse), entschieden wird mit «ausgeblendet» oder «bleibt».
 *
 * Die Definitionen sind Code, keine Daten — sie entstehen beim Booten (Singleton, Octane-sicher).
 *
 * @phpstan-type Description array{title: string, excerpt: string, url: string|null, author: int|null, place: string}
 * @phpstan-type Definition array{label: string, describe: Closure(int): (Description|null), hide: (Closure(int, int): void)|null, threshold: Closure(int): int, reasons: array<string, string>}
 */
final class Reports
{
    /** @var array<string, Definition> */
    private array $types = [];

    /**
     * @param  Closure(int): (Description|null)  $describe  Titel, Auszug, Adresse und Verfasserin für die Liste — null, wenn es den Inhalt nicht mehr gibt
     * @param  (Closure(int, int): void)|null  $hide  vorläufig ausblenden (Kennung, Zahl offener Meldungen)
     * @param  (Closure(int): int)|null  $threshold  ab wie vielen offenen Meldungen (je Site); ohne: Einstellung `community.reports.auto_hide`
     * @param  array<string, string>  $reasons  andere Texte für einzelne Gründe
     */
    public function define(string $type, string $label, Closure $describe, ?Closure $hide = null, ?Closure $threshold = null, array $reasons = []): self
    {
        $this->types[$type] = ['label' => $label, 'describe' => $describe, 'hide' => $hide,
            'threshold' => $threshold ?? static fn (int $siteId): int => (int) app(Settings::class)->get('community.reports.auto_hide', 3, Site::query()->find($siteId)),
            'reasons' => $reasons];

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }

    public function label(string $type, ?string $lang = null): string
    {
        return (string) __($this->types[$type]['label'] ?? $type, [], $lang);
    }

    /** @return array<string, string> Gründe dieses Typs, übersetzt */
    public function reasons(string $type, ?string $lang = null): array
    {
        $out = [];
        foreach ([...CommunityReport::REASONS, ...($this->types[$type]['reasons'] ?? [])] as $key => $text) {
            $out[$key] = (string) __($text, [], $lang);
        }

        return $out;
    }

    public function reasonLabel(string $type, string $reason, ?string $lang = null): string
    {
        return $this->reasons($type, $lang)[$reason] ?? $reason;
    }

    /** @return Description|null */
    public function describe(string $type, int $id): ?array
    {
        $describe = $this->types[$type]['describe'] ?? null;

        return $describe === null ? null : $describe($id);
    }

    public static function reporterKey(?Customer $customer, ?string $ip): string
    {
        return $customer !== null ? 'k:'.$customer->id : hash_hmac('sha256', (string) $ip, (string) config('app.key'));
    }

    /**
     * Eine Meldung aufnehmen — null, wenn diese Person den Inhalt schon gemeldet hat. Ab der Schwelle offener Meldungen
     * blendet der Typ den Inhalt vorläufig aus.
     */
    public function file(int $siteId, string $type, int $id, string $reason, ?string $note, ?Customer $customer, ?string $ip): ?CommunityReport
    {
        $reporter = self::reporterKey($customer, $ip);
        if (CommunityReport::query()->withoutGlobalScopes()->where('subject_type', $type)->where('subject_id', $id)->where('reporter', $reporter)->exists()) {
            return null;
        }
        $report = CommunityReport::query()->withoutGlobalScopes()->create(['site_id' => $siteId, 'subject_type' => $type, 'subject_id' => $id, 'customer_id' => $customer?->id,
            'reporter' => $reporter, 'reason' => array_key_exists($reason, CommunityReport::REASONS) ? $reason : 'anderes',
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, 'status' => CommunityReport::OPEN]);
        $definition = $this->types[$type] ?? null;
        if ($definition !== null && $definition['hide'] !== null) {
            $threshold = ($definition['threshold'])($siteId);
            $open = $this->open($type, $id);
            if ($threshold > 0 && $open >= $threshold) {
                ($definition['hide'])($id, $open);
            }
        }

        return $report;
    }

    public function open(string $type, int $id): int
    {
        return CommunityReport::query()->withoutGlobalScopes()->where('subject_type', $type)->where('subject_id', $id)->where('status', CommunityReport::OPEN)->count();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> Kennung → offene Meldungen
     */
    public function openFor(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return CommunityReport::query()->withoutGlobalScopes()->where('subject_type', $type)->whereIn('subject_id', $ids)->where('status', CommunityReport::OPEN)
            ->selectRaw('subject_id, count(*) as aggregate')->groupBy('subject_id')->pluck('aggregate', 'subject_id')->map(static fn (mixed $n): int => (int) $n)->all();
    }

    /** Offene Meldungen erledigen («hidden» oder «kept») — gibt die Zahl zurück. */
    public function resolve(string $type, int $id, string $decision, Moderator $by): int
    {
        return CommunityReport::query()->withoutGlobalScopes()->where('subject_type', $type)->where('subject_id', $id)->where('status', CommunityReport::OPEN)
            ->update(['status' => $decision, 'resolved_by' => $by->userId(), 'resolved_by_customer' => $by->customerId(), 'resolved_at' => now()]);
    }

    /**
     * Offene Meldungen einer Site, je Inhalt gebündelt, die meistgemeldeten zuerst.
     *
     * @param  list<string>|null  $types
     * @return list<array{type: string, id: int, count: int, reports: list<CommunityReport>}>
     */
    public function pending(int $siteId, ?array $types = null, int $limit = 50): array
    {
        $rows = CommunityReport::query()->withoutGlobalScopes()->where('site_id', $siteId)->where('status', CommunityReport::OPEN)
            ->when($types !== null, static fn ($q) => $q->whereIn('subject_type', $types))->orderBy('id')->limit(1000)->get();
        $groups = [];
        foreach ($rows as $row) {
            $key = $row->subject_type.':'.$row->subject_id;
            $groups[$key] ??= ['type' => $row->subject_type, 'id' => $row->subject_id, 'count' => 0, 'reports' => []];
            $groups[$key]['count']++;
            $groups[$key]['reports'][] = $row;
        }
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => [$b['count'], $a['reports'][0]->id] <=> [$a['count'], $b['reports'][0]->id]);

        return array_slice($groups, 0, $limit);
    }

    /** @param  list<string>|null  $types */
    public function pendingCount(int $siteId, ?array $types = null): int
    {
        $subjects = CommunityReport::query()->withoutGlobalScopes()->where('site_id', $siteId)->where('status', CommunityReport::OPEN)
            ->when($types !== null, static fn ($q) => $q->whereIn('subject_type', $types))->select(['subject_type', 'subject_id'])->distinct();

        return DB::query()->fromSub($subjects, 'gemeldet')->count();
    }
}
