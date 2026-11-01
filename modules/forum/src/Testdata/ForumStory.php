<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Testdata;

use Basalto\Modules\Forum\Console\ForumSeedCommand;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;

/**
 * Die Geschichte des Forums als Text (`resources/testdaten/forum-geschichte.txt`) — Mitglieder, Themen und Beiträge
 * mit Ortszeit (Europe/Zurich), Querverweisen und Auszeichnungen. Aufbau der Datei:
 *
 *     @mitglied luca | Luca Meier | 2026-08-04 09:12 | Webentwickler in Zürich
 *
 *     @team hoang | Hoang Nguyen | 2026-08-01 08:00 | Baut Basalto in Winterthur
 *
 *     @thema willkommen | ankundigungen | - | Willkommen | angeheftet
 *
 *     @felder version=1.2.0; hosting=Shared Hosting
 *     --- hoang | 2026-08-01 10:00
 *     Text mit **fett**, `code`, [Verweis](@thema:schluessel) oder [Antwort](@beitrag:schluessel:2), Listen (- / 1.),
 *     ## Überschrift, > Zitat und Codeblöcken mit ```sprache.
 *     --- luca | 2026-08-01 11:20 | beste
 *
 * Zeilen, die mit `//` beginnen, sind Kommentare (ausser in Codeblöcken). Beiträge des Teams sind offiziell.
 */
final class ForumStory
{
    public const FILE = __DIR__.'/../../resources/testdaten/forum-geschichte.txt';

    public const ZONE = 'Europe/Zurich';

    private const THREAD_FLAGS = ['angeheftet', 'geschlossen', 'ankuendigung'];

    private const POST_FLAGS = ['beste'];

    /**
     * @return array{members: array<string, array{name: string, joined: CarbonImmutable, about: string, team: bool}>,
     *     threads: list<array{key: string, forum: string, prefix: string|null, title: string, flags: list<string>, fields: array<string, string>, line: int,
     *     posts: list<array{author: string, at: CarbonImmutable, flags: list<string>, text: string, line: int}>}>}
     */
    public static function parse(string $source): array
    {
        // Erst in Abschnitte zerlegen (Anweisung mit Zeile und den Zeilen ihres Texts), dann jedes Thema als Ganzes bauen
        $items = [];
        $bodies = [];
        $fence = false;
        // Nur an \n trennen: `\R` ohne `u` hielte das Byte 0x85 in Emoji wie 😅 für ein Zeilenende
        foreach (explode("\n", str_replace("\r\n", "\n", $source)) as $index => $line) {
            if (! $fence && str_starts_with($line, '//')) {
                continue;
            }
            $inPost = $items !== [] && $items[count($items) - 1]['kind'] === 'post';
            if ($fence || ($inPost && ! str_starts_with($line, '@') && ! str_starts_with($line, '--- '))) {
                if ($inPost) {
                    $fence = str_starts_with($line, '```') ? ! $fence : $fence;
                    $bodies[count($items) - 1][] = $line;
                }

                continue;
            }
            if (preg_match('/^(?:@(mitglied|team|thema|felder) |(---) )(.+)$/u', $line, $m) === 1) {
                $items[] = ['kind' => $m[2] === '---' ? 'post' : $m[1], 'line' => $index + 1, 'value' => $m[3]];
            } elseif (trim($line) !== '') {
                throw self::error($index + 1, 'Zeile ausserhalb eines Beitrags');
            }
        }
        if ($fence) {
            throw new RuntimeException('Codeblock bis zum Ende der Datei offen');
        }

        $members = [];
        $threads = [];
        $current = null;
        $posts = [];
        foreach ([...$items, ['kind' => 'ende', 'line' => 0, 'value' => '']] as $i => $item) {
            $number = $item['line'];
            if (in_array($item['kind'], ['thema', 'mitglied', 'team', 'ende'], true) && $current !== null) {
                $threads[] = ['key' => $current['key'], 'forum' => $current['forum'], 'prefix' => $current['prefix'], 'title' => $current['title'],
                    'flags' => $current['flags'], 'fields' => $current['fields'], 'line' => $current['line'], 'posts' => $posts];
                $current = null;
                $posts = [];
            }
            if ($item['kind'] === 'mitglied' || $item['kind'] === 'team') {
                [$key, $name, $joined, $about] = self::cells($item['value'], 4, $number);
                if (isset($members[$key])) {
                    throw self::error($number, 'Mitglied «'.$key.'» doppelt');
                }
                $members[$key] = ['name' => $name, 'joined' => self::time($joined, $number), 'about' => $about, 'team' => $item['kind'] === 'team'];
            } elseif ($item['kind'] === 'thema') {
                $cells = self::cells($item['value'], 4, $number, 5);
                $current = ['key' => $cells[0], 'forum' => $cells[1], 'prefix' => $cells[2] === '-' ? null : $cells[2], 'title' => $cells[3],
                    'flags' => self::flags($cells[4] ?? '', self::THREAD_FLAGS, $number), 'fields' => [], 'line' => $number];
            } elseif ($item['kind'] === 'felder' || $item['kind'] === 'post') {
                if ($current === null) {
                    throw self::error($number, 'gehört zu keinem Thema');
                }
                if ($item['kind'] === 'felder') {
                    foreach (explode(';', $item['value']) as $pair) {
                        $parts = array_map('trim', explode('=', $pair, 2));
                        $current['fields'][$parts[0]] = $parts[1] ?? '';
                    }
                } else {
                    $cells = self::cells($item['value'], 2, $number, 3);
                    $posts[] = ['author' => $cells[0], 'at' => self::time($cells[1], $number), 'flags' => self::flags($cells[2] ?? '', self::POST_FLAGS, $number),
                        'text' => trim(implode("\n", $bodies[$i] ?? []), "\n"), 'line' => $number];
                }
            }
        }

        return ['members' => $members, 'threads' => $threads];
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function flags(string $text, array $allowed, int $line): array
    {
        $flags = $text === '' ? [] : array_map('trim', explode(',', $text));
        foreach ($flags as $flag) {
            if (! in_array($flag, $allowed, true)) {
                throw self::error($line, 'unbekannte Auszeichnung «'.$flag.'»');
            }
        }

        return $flags;
    }

    /**
     * Prüft die Geschichte gegen die Foren von `basalto:forum-seed`: Mitglieder, Reihenfolge, Foren, Präfixe, Pflichtfelder,
     * beste Antworten und Querverweise (nur auf Früheres).
     *
     * @param  array{members: array<string, array{name: string, joined: CarbonImmutable, about: string, team: bool}>, threads: list<array<string, mixed>>}  $story
     * @return list<string> Befunde mit Zeile
     */
    public static function problems(array $story): array
    {
        $boards = self::boards();
        $problems = [];
        $first = [];
        $times = [];
        foreach ($story['threads'] as $thread) {
            $line = $thread['line'];
            if (isset($first[$thread['key']])) {
                $problems[] = $line.': Thema «'.$thread['key'].'» doppelt';
            }
            $board = $boards[$thread['forum']] ?? null;
            if ($board === null) {
                $problems[] = $line.': unbekanntes Forum «'.$thread['forum'].'»';
            } else {
                if ($thread['prefix'] !== null && ! in_array($thread['prefix'], $board['prefixes'], true)) {
                    $problems[] = $line.': Präfix «'.$thread['prefix'].'» gibt es in «'.$thread['forum'].'» nicht';
                }
                foreach ($board['required'] as $field) {
                    if (($thread['fields'][$field] ?? '') === '') {
                        $problems[] = $line.': Pflichtfeld «'.$field.'» fehlt';
                    }
                }
                foreach (array_keys($thread['fields']) as $field) {
                    if (! in_array($field, $board['fields'], true)) {
                        $problems[] = $line.': Feld «'.$field.'» gibt es in «'.$thread['forum'].'» nicht';
                    }
                }
            }
            if ($thread['posts'] === []) {
                $problems[] = $line.': Thema ohne Beitrag';

                continue;
            }
            $previous = null;
            foreach ($thread['posts'] as $n => $post) {
                $member = $story['members'][$post['author']] ?? null;
                if ($member === null) {
                    $problems[] = $post['line'].': unbekanntes Mitglied «'.$post['author'].'»';
                } elseif ($post['at']->lt($member['joined'])) {
                    $problems[] = $post['line'].': «'.$post['author'].'» schreibt vor dem Beitritt';
                }
                if ($previous !== null && $post['at']->lte($previous)) {
                    $problems[] = $post['line'].': Beitrag nicht nach dem vorigen';
                }
                if (in_array('beste', $post['flags'], true) && ($n === 0 || $post['author'] === $thread['posts'][0]['author'])) {
                    $problems[] = $post['line'].': beste Antwort muss eine Antwort einer anderen Person sein';
                }
                if (trim($post['text']) === '') {
                    $problems[] = $post['line'].': Beitrag ohne Text';
                }
                $stamp = $post['at']->format('Y-m-d H:i');
                if (isset($times[$stamp])) {
                    $problems[] = $post['line'].': gleiche Minute wie Zeile '.$times[$stamp];
                }
                $times[$stamp] = $post['line'];
                $previous = $post['at'];
            }
            $first[$thread['key']] = $thread;
        }
        // Querverweise erst, wenn alle Themen bekannt sind: nur auf Früheres
        foreach ($story['threads'] as $thread) {
            foreach ($thread['posts'] as $post) {
                preg_match_all('/@(thema|beitrag):([a-z0-9-]+)(?::(\d+))?/', $post['text'], $refs, PREG_SET_ORDER);
                foreach ($refs as $ref) {
                    $target = $first[$ref[2]] ?? null;
                    $n = $ref[1] === 'beitrag' ? (int) ($ref[3] ?? 0) : 1;
                    $aim = $target['posts'][$n - 1]['at'] ?? null;
                    if ($target === null || $aim === null) {
                        $problems[] = $post['line'].': Verweis «'.$ref[0].'» ohne Ziel';
                    } elseif ($aim->gte($post['at'])) {
                        $problems[] = $post['line'].': Verweis «'.$ref[0].'» zeigt nach vorn';
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Text eines Beitrags als Dokument des Editors (TipTap): Absätze, Listen, Überschriften, Zitate, Codeblöcke, fett,
     * Code und Links. `$link` übersetzt `@thema:…`/`@beitrag:…` in Adressen.
     *
     * @param  Closure(string): string  $link
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public static function doc(string $text, Closure $link): array
    {
        $blocks = [];
        $chunks = [];
        $buffer = [];
        $fence = false;
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, '```')) {
                $buffer[] = $line;
                if ($fence) {
                    $chunks[] = $buffer;
                    $buffer = [];
                }
                $fence = ! $fence;

                continue;
            }
            if (! $fence && trim($line) === '') {
                if ($buffer !== []) {
                    $chunks[] = $buffer;
                    $buffer = [];
                }

                continue;
            }
            $buffer[] = $line;
        }
        if ($buffer !== []) {
            $chunks[] = $buffer;
        }
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk[0], '```')) {
                $language = trim(substr($chunk[0], 3));
                $blocks[] = ['type' => 'codeBlock', 'attrs' => ['language' => $language !== '' ? $language : 'plaintext'],
                    'content' => [['type' => 'text', 'text' => implode("\n", array_slice($chunk, 1, -1))]]];
            } elseif (str_starts_with($chunk[0], '## ')) {
                $blocks[] = ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => self::inline(substr($chunk[0], 3), $link)];
                if (count($chunk) > 1) {
                    $blocks[] = ['type' => 'paragraph', 'content' => self::lines(array_slice($chunk, 1), $link)];
                }
            } elseif (self::every($chunk, '/^- /')) {
                $blocks[] = ['type' => 'bulletList', 'content' => array_map(static fn (string $l): array => ['type' => 'listItem',
                    'content' => [['type' => 'paragraph', 'content' => self::inline(substr($l, 2), $link)]]], $chunk)];
            } elseif (self::every($chunk, '/^\d+\. /')) {
                $blocks[] = ['type' => 'orderedList', 'content' => array_map(static fn (string $l): array => ['type' => 'listItem',
                    'content' => [['type' => 'paragraph', 'content' => self::inline((string) preg_replace('/^\d+\. /', '', $l), $link)]]], $chunk)];
            } elseif (self::every($chunk, '/^> ?/')) {
                $blocks[] = ['type' => 'blockquote', 'content' => [['type' => 'paragraph',
                    'content' => self::lines(array_map(static fn (string $l): string => (string) preg_replace('/^> ?/', '', $l), $chunk), $link)]]];
            } else {
                $blocks[] = ['type' => 'paragraph', 'content' => self::lines($chunk, $link)];
            }
        }

        return ['type' => 'doc', 'content' => $blocks];
    }

    /** @return array<string, array{prefixes: list<string>, fields: list<string>, required: list<string>}> Forum → erlaubte Präfixe und Felder */
    public static function boards(): array
    {
        $out = [];
        $walk = static function (array $structure) use (&$walk, &$out): void {
            foreach ($structure as [$slug, , , , $options, $children]) {
                $fields = array_values(array_map('strval', (array) ($options['fields'] ?? [])));
                $out[(string) $slug] = ['prefixes' => array_values(array_map('strval', (array) ($options['prefixes'] ?? []))), 'fields' => $fields,
                    'required' => $slug === 'fehlermeldungen' && in_array('version', $fields, true) ? ['version'] : []];
                $walk($children);
            }
        };
        $walk(ForumSeedCommand::STRUCTURE);

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function inline(string $text, Closure $link): array
    {
        $out = [];
        $pos = 0;
        preg_match_all('/\*\*(.+?)\*\*|`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $m) {
            [$whole, $at] = $m[0];
            $out = [...$out, ...self::plain(substr($text, $pos, $at - $pos))];
            [$bold, $code, $label, $target] = [$m[1][0] ?? null, $m[2][0] ?? null, $m[3][0] ?? null, $m[4][0] ?? null];
            if ($bold !== null) {
                $out = [...$out, ...array_map(static fn (array $n): array => ['marks' => [['type' => 'bold'], ...($n['marks'] ?? [])]] + $n, self::inline($bold, $link))];
            } elseif ($code !== null) {
                $out[] = ['type' => 'text', 'text' => $code, 'marks' => [['type' => 'code']]];
            } elseif ($label !== null && $target !== null) {
                $href = str_starts_with($target, '@') ? $link($target) : $target;
                $out = [...$out, ...array_map(static fn (array $n): array => ['marks' => [...($n['marks'] ?? []), ['type' => 'link', 'attrs' => ['href' => $href]]]] + $n,
                    self::inline($label, $link))];
            }
            $pos = $at + strlen((string) $whole);
        }

        return [...$out, ...self::plain(substr($text, $pos))];
    }

    /** @return list<array{type: string, text: string}> */
    private static function plain(string $text): array
    {
        return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private static function lines(array $lines, Closure $link): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $out[] = ['type' => 'hardBreak'];
            }
            $out = [...$out, ...self::inline($line, $link)];
        }

        return $out;
    }

    /** @param  list<string>  $lines */
    private static function every(array $lines, string $pattern): bool
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function cells(string $text, int $min, int $line, ?int $max = null): array
    {
        $cells = array_map('trim', explode('|', $text));
        if (count($cells) < $min || count($cells) > ($max ?? $min)) {
            throw self::error($line, 'erwartet '.$min.($max !== null ? '–'.$max : '').' Felder, getrennt mit «|»');
        }

        return $cells;
    }

    private static function time(string $text, int $line): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $text) !== 1) {
            throw self::error($line, 'Zeit «'.$text.'» nicht im Format JJJJ-MM-TT HH:MM');
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', $text, self::ZONE) ?: throw self::error($line, 'Zeit «'.$text.'» ungültig');
    }

    private static function error(int $line, string $message): RuntimeException
    {
        return new RuntimeException(sprintf('forum-geschichte.txt, Zeile %d: %s', $line, $message));
    }
}
