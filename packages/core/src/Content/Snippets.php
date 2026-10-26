<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\Snippet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Bausteine (M-28): Eine Seite setzt einen Baustein als Verweis ein — `{type: "@snippet", data: {snippet: handle}}` —
 * und beim Rendern stehen an seiner Stelle seine Blöcke. Ändert jemand den Baustein, ändern sich alle Seiten mit;
 * «Auflösen» macht aus jedem Verweis eine Kopie der Blöcke und löscht den Baustein, ohne dass etwas verloren geht.
 * Bausteine enthalten keine Bausteine (keine Verschachtelung, keine Kreise).
 */
final class Snippets
{
    public const TYPE = '@snippet';

    public const HANDLE = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public function __construct(private readonly Recorder $recorder) {}

    /** Ist ein Block ein Verweis auf einen Baustein? */
    public static function isReference(mixed $block): bool
    {
        return is_array($block) && ($block['type'] ?? null) === self::TYPE;
    }

    public static function handleOf(mixed $block): ?string
    {
        $handle = is_array($block) && is_array($block['data'] ?? null) ? ($block['data']['snippet'] ?? null) : null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * Die Blockliste mit aufgelösten Verweisen — ein unbekannter Baustein fällt still weg (wie ein Block ohne
     * Entsprechung im Theme).
     *
     * @param  array<mixed>  $blocks
     * @return list<mixed>
     */
    public function expand(Site $site, array $blocks): array
    {
        $handles = array_values(array_filter(array_map(static fn (mixed $b): ?string => self::isReference($b) ? self::handleOf($b) : null, $blocks)));
        if ($handles === []) {
            return array_values($blocks);
        }
        $snippets = Snippet::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('handle', array_unique($handles))->get()->keyBy('handle');
        $out = [];
        foreach ($blocks as $block) {
            if (! self::isReference($block)) {
                $out[] = $block;

                continue;
            }
            $snippet = $snippets->get((string) self::handleOf($block));
            foreach ($snippet instanceof Snippet ? $snippet->blocks : [] as $inner) {
                if (! self::isReference($inner)) {
                    $out[] = $inner;
                }
            }
        }

        return $out;
    }

    /**
     * Wo die Bausteine stehen: je Handle die Inhalte, deren aktuelle Fassung (je Sprache) einen Verweis hat.
     *
     * @return array<string, list<array{id: int, title: string, type: string, locale: string}>>
     */
    public function usage(Site $site): array
    {
        $candidates = array_values(array_unique(array_map(static fn (array $v): string => $v['entry_id'].':'.$v['locale'], $this->versionsWithReferences($site))));
        $out = [];
        foreach ($candidates as $pair) {
            [$entryId, $locale] = explode(':', $pair, 2);
            $latest = EntryVersion::query()->where('entry_id', (int) $entryId)->where('locale', $locale)->orderByDesc('number')->first();
            $entry = Entry::query()->withoutGlobalScopes()->whereKey((int) $entryId)->whereNull('deleted_at')->first();
            if ($latest === null || $entry === null) {
                continue;
            }
            foreach (is_array($latest->data['content'] ?? null) ? $latest->data['content'] : [] as $block) {
                $handle = self::isReference($block) ? self::handleOf($block) : null;
                if ($handle !== null && ! in_array($entry->id, array_column($out[$handle] ?? [], 'id'), true)) {
                    $out[$handle][] = ['id' => $entry->id, 'title' => $latest->title, 'type' => $entry->type, 'locale' => $latest->locale];
                }
            }
        }

        return $out;
    }

    /**
     * Löst einen Baustein überall auf: Jeder Verweis in jeder Fassung (auch in älteren — sonst zeigte eine
     * wiederhergestellte Fassung ein Loch) wird zu einer Kopie seiner Blöcke; danach ist der Baustein weg.
     *
     * @return int Anzahl geänderter Fassungen
     */
    public function dissolve(Site $site, Snippet $snippet, Authenticatable $user): int
    {
        return DB::transaction(function () use ($site, $snippet, $user): int {
            $copies = array_values(array_filter($snippet->blocks, static fn (mixed $b): bool => ! self::isReference($b)));
            $changed = 0;
            foreach ($this->versionsWithReferences($site) as $row) {
                $data = json_decode($row['data'], true);
                if (! is_array($data) || ! is_array($data['content'] ?? null)) {
                    continue;
                }
                $content = [];
                $found = false;
                foreach ($data['content'] as $block) {
                    if (self::isReference($block) && self::handleOf($block) === $snippet->handle) {
                        array_push($content, ...$copies);
                        $found = true;
                    } else {
                        $content[] = $block;
                    }
                }
                if ($found) {
                    $data['content'] = $content;
                    DB::table('entry_versions')->where('id', $row['id'])->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
                    $changed++;
                }
            }
            $this->recorder->record('snippet.dissolve', $snippet, ['handle' => $snippet->handle, 'name' => $snippet->name], ['fassungen' => $changed], $site, $user);
            $snippet->delete();

            return $changed;
        });
    }

    /**
     * Fassungen der Site, deren Daten einen Verweis auf einen Baustein enthalten können (Vorfilter, geprüft wird danach).
     *
     * @return list<array{id: int, entry_id: int, locale: string, data: string}>
     */
    private function versionsWithReferences(Site $site): array
    {
        $query = DB::table('entry_versions')->join('entries', 'entries.id', '=', 'entry_versions.entry_id')->where('entries.site_id', $site->id);
        // Vorfilter in der Datenbank — PostgreSQL vergleicht JSON nicht mit LIKE, dort als Text
        DB::connection()->getDriverName() === 'pgsql'
            ? $query->whereRaw('entry_versions.data::text like ?', ['%'.self::TYPE.'%'])
            : $query->where('entry_versions.data', 'like', '%'.self::TYPE.'%');

        return array_values($query->orderBy('entry_versions.id')->get(['entry_versions.id', 'entry_versions.entry_id', 'entry_versions.locale', 'entry_versions.data'])
            ->map(static fn (object $r): array => ['id' => (int) $r->id, 'entry_id' => (int) $r->entry_id, 'locale' => (string) $r->locale, 'data' => (string) $r->data])->all());
    }
}
