<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Live\Live;
use Basalto\Core\Live\Presence;

/**
 * «Wer schreibt gerade» (TB 49): Wer an einer Antwort tippt, meldet sich alle vier Sekunden; die anderen sehen es über
 * die Live-Aktualisierung (CR-008) — im Antwortfeld und am Ende der Beiträge. Ein Ort ist ein Live-Schlüssel unter
 * `schreibt.` (etwa `schreibt.forum.45` für ein Thema, `schreibt.konversation.7`); die Seite gibt ihn mit Unterschrift
 * nur aus, wo die Person lesen darf. Gespeichert wird nichts ausser einer kurzen Liste im Cache (`Presence`).
 */
final class Typing
{
    public const PREFIX = 'schreibt';

    /** Sekunden, die eine Meldung gilt; die Insel meldet sich alle 4 s, solange getippt wird. */
    public const TTL = 8;

    public function __construct(private readonly Presence $presence) {}

    /** Der Live-Schlüssel eines Orts (`forum.45` → `schreibt.forum.45`). */
    public static function key(string $place): string
    {
        return self::PREFIX.'.'.$place;
    }

    public static function isKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX.'.') && preg_match(Live::KEY, $key) === 1;
    }

    /** Ein Profil beginnt zu tippen, tippt weiter oder hört auf. */
    public function update(int $siteId, string $key, CommunityProfile $profile, bool $active): void
    {
        $this->presence->update($siteId, $key, $profile->id, (string) $profile->name, $active, self::TTL);
    }

    /** @return list<array{id: int, name: string, rest: int}> */
    public function current(int $siteId, string $key): array
    {
        return $this->presence->current($siteId, $key);
    }
}
