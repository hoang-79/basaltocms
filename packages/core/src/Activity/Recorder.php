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
        $siteId = ($site ?? $this->current->get())?->id;
        // CR-013: eine Folge von Autosaves derselben Person am selben Gegenstand ist eine Zeile — im Kundenkonto (ohne
        // Benutzer des Backends) zählt der Gegenstand allein, etwa das eigene Kundenkonto
        if (AutosaveRequest::current() && ($user !== null || $subject !== null)) {
            $recent = $this->recent($action, $subject, $siteId, $user === null ? null : (int) $user->getAuthIdentifier());
            if ($recent !== null) {
                $recent->after = $after;
                $recent->created_at = CarbonImmutable::now();
                $recent->save();

                return $recent;
            }
        }

        return Activity::query()->create([
            'site_id' => $siteId,
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

    private function recent(string $action, ?Model $subject, ?int $siteId, ?int $userId): ?Activity
    {
        $query = Activity::query()->where('action', $action)->where('created_at', '>=', AutosaveRequest::since());
        $userId === null ? $query->whereNull('user_id') : $query->where('user_id', $userId);
        $siteId === null ? $query->whereNull('site_id') : $query->where('site_id', $siteId);
        if ($subject === null) {
            $query->whereNull('subject_type')->whereNull('subject_id');
        } else {
            $query->where('subject_type', $subject->getMorphClass())->where('subject_id', (int) $subject->getKey());
        }

        return $query->latest('id')->first();
    }
}
