<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Access\Level;
use Basalto\Core\Access\RoleDefinition;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SupportSession;
use Basalto\Core\Notifications\Notify;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Support-Zugriff (CR-004, P-16): Das Team öffnet eine Kunden-Site dieser Installation befristet und nur lesend — mit
 * Pflichtgrund, als Band bei allen sichtbar, die in der Site arbeiten, und im Protokoll der Site. Schreiben ist mit der
 * Rolle «Support» (überall «ansehen») ausgeschlossen; nach Ablauf gibt es keinen Zugang mehr.
 */
final class SupportAccess
{
    public const ROLE = 'support';

    public function __construct(private readonly Recorder $recorder) {}

    public function start(Authenticatable $user, Site $site, string $reason, int $minutes): SupportSession
    {
        $minutes = max(5, min($minutes, (int) config('basalto.extranet.support_max_minutes', 240)));
        $session = SupportSession::query()->create(['site_id' => $site->id, 'user_id' => $user->getAuthIdentifier(), 'reason' => $reason, 'expires_at' => now()->addMinutes($minutes)]);
        $this->recorder->record('support.started', $session, null, ['grund' => $reason, 'bis' => $session->expires_at->toIso8601String()], $site, $user);
        // TB 27: wer die Site verwaltet, erfährt es sofort — mit Grund und Dauer
        try {
            Notify::make('support.access')->title('Der Support des Herstellers hat lesenden Zugriff auf «:site»', ['site' => $site->name])
                ->body('Grund: :reason · bis :until', ['reason' => $reason, 'until' => $session->expires_at->setTimezone($site->timezone)->format('d.m.Y H:i')])
                ->url(route('basalto.activity.index', absolute: false), 'Protokoll öffnen')->toArea($site)->send();
        } catch (\Throwable $e) {
            report($e);
        }

        return $session;
    }

    public function end(SupportSession $session, Authenticatable $user): void
    {
        if ($session->ended_at === null) {
            $session->forceFill(['ended_at' => now()])->save();
            $site = $session->site;
            $this->recorder->record('support.ended', $session, null, ['grund' => $session->reason], $site, $user);
        }
    }

    public static function active(Authenticatable $user, Site $site): ?SupportSession
    {
        return SupportSession::query()->active()->where('user_id', $user->getAuthIdentifier())->where('site_id', $site->id)->latest('id')->first();
    }

    /** @return Collection<int, Site> Sites, die die Person gerade lesend öffnen darf */
    public static function sitesFor(Authenticatable $user): Collection
    {
        $ids = SupportSession::query()->active()->where('user_id', $user->getAuthIdentifier())->pluck('site_id');

        return $ids->isEmpty() ? new Collection : Site::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /** Die Rolle während des Support-Zugriffs: überall «ansehen», keine Sprache bearbeitbar. */
    public static function definition(): RoleDefinition
    {
        return new RoleDefinition(self::ROLE, __('Support (nur lesen)'), ['*' => Level::View->value], ['mode' => 'only', 'codes' => []]);
    }

    /**
     * Band im Backend der Kunden-Site, solange ein Support-Zugriff läuft.
     *
     * @return array{text: string, until: string}|null
     */
    public static function notice(?Site $site): ?array
    {
        if ($site === null) {
            return null;
        }
        $session = SupportSession::query()->active()->where('site_id', $site->id)->with('user')->latest('expires_at')->first();
        if ($session === null) {
            return null;
        }
        $until = $session->expires_at->timezone($site->timezone)->format('d.m.Y, H:i');

        return ['text' => __('Support des Herstellers (:name) hat bis :until lesenden Zugriff auf diese Site — Grund: :reason', [
            'name' => (string) ($session->user?->getAttribute('name') ?? '—'), 'until' => $until, 'reason' => $session->reason,
        ]), 'until' => $until];
    }
}
