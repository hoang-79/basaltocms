<?php

declare(strict_types=1);

namespace Basalto\Core\Auth;

use Basalto\Core\Backend\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sitzungen eines Kontos (K-03) aus der Sitzungstabelle (`SESSION_DRIVER=database`): sehen und beenden.
 * Mit einem anderen Treiber gibt es keine Liste — beenden geht dann nur über Deaktivieren.
 */
final class Sessions
{
    public function __construct(private readonly Dates $dates) {}

    public function available(): bool
    {
        return config('session.driver') === 'database' && Schema::hasTable((string) config('session.table', 'sessions'));
    }

    /** @return list<array{id: string, ip: string|null, agent: string, last_active: string|null, current: bool}> */
    public function forUser(int $userId, ?string $currentId = null): array
    {
        if (! $this->available()) {
            return [];
        }
        $out = [];
        foreach (DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']) as $row) {
            $out[] = ['id' => (string) $row->id, 'ip' => $row->ip_address === null ? null : (string) $row->ip_address, 'agent' => self::describe((string) ($row->user_agent ?? '')),
                'last_active' => $this->dates->relative(CarbonImmutable::createFromTimestamp((int) $row->last_activity)), 'current' => $row->id === $currentId];
        }

        return $out;
    }

    /** Sitzungen beenden — alle, oder alle ausser einer (der eigenen). */
    public function end(int $userId, ?string $except = null, ?string $only = null): int
    {
        if (! $this->available()) {
            return 0;
        }
        $query = DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($only !== null) {
            $query->where('id', $only);
        }

        return $query->delete();
    }

    /** «Safari macOS», «Chrome iPhone», «Firefox Windows» — genug, um die eigene Sitzung zu erkennen. */
    public static function describe(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            $agent === '' => 'unbekannt',
            default => 'Browser',
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };

        return trim($browser.' '.$system);
    }
}
