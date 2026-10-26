<?php

declare(strict_types=1);

namespace Basalto\Core\Activity;

use Basalto\Core\Models\Activity;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Schreibt das Protokoll (K-15): Aktion, Gegenstand, alt/neu, Benutzer, Site aus dem Kontext, IP nur als Prüfsumme
 * mit dem App-Schlüssel (N-05). Module rufen `record()` aus ihren Ereignis-Listenern.
 */
final class Recorder
{
    public function __construct(private readonly CurrentSite $current) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?Site $site = null, ?Authenticatable $user = null): Activity
    {
        $request = app()->bound('request') ? app('request') : null;
        $user ??= $request instanceof Request ? $request->user() : null;
        $ip = $request instanceof Request ? $request->ip() : null;

        return Activity::query()->create([
            'site_id' => ($site ?? $this->current->get())?->id,
            'user_id' => $user === null ? null : (int) $user->getAuthIdentifier(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (int) $subject->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip_hash' => $ip === null ? null : hash('sha256', $ip.'|'.(string) config('app.key')),
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
