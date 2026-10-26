<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Live\Live;
use Basalto\Core\Live\Presence;
use Basalto\Core\Models\Entry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * «Wer bearbeitet gerade» im Backend (TB 49): Der Editor eines Inhalts meldet sich alle 20 Sekunden, solange er offen
 * und sichtbar ist, und beim Verlassen ab. Wer den Inhalt ebenfalls offen hat, sieht es über die Live-Aktualisierung —
 * damit niemand die Änderungen des anderen überschreibt.
 */
final class PresenceController
{
    public const TTL = 45;

    public static function key(int $entryId): string
    {
        return 'bearbeitet.eintrag.'.$entryId;
    }

    /**
     * Was der Editor braucht: Schlüssel mit Unterschrift, Adresse, die eigene Kennung und wer schon da ist (sonst sähe
     * man die anderen erst bei ihrem nächsten Herzschlag).
     *
     * @return array{site: int, key: string, token: string, version: int, interval: int, url: string, me: int, people: list<array{id: int, name: string, rest: int}>}
     */
    public static function config(Entry $entry, int $userId): array
    {
        $live = app(Live::class);
        $key = self::key($entry->id);

        return ['site' => $entry->site_id, 'key' => $key, 'token' => $live->token($entry->site_id, $key), 'version' => $live->version($entry->site_id, $key),
            'interval' => min(15, $live->interval()), 'url' => route('basalto.editor.presence', ['entry' => $entry->id], false), 'me' => $userId,
            'people' => app(Presence::class)->current($entry->site_id, $key)];
    }

    public function __invoke(Request $request, Entry $entry, Presence $presence): Response
    {
        Gate::authorize('view', $entry);
        $user = $request->user();
        abort_if($user === null, 403);
        $presence->update($entry->site_id, self::key($entry->id), (int) $user->getAuthIdentifier(), (string) $user->getAttribute('name'), $request->boolean('aktiv'), self::TTL);

        return response()->noContent();
    }
}
