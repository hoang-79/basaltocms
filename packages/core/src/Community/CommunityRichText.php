<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Content\RichText;

/**
 * Text von Besuchern (CR-006 FO-06, FO-K5): Beiträge, Antworten, später Konversationen und Kommentare. Gespeichert wird
 * das TipTap-Dokument der Editor-Insel, gesäubert mit einer Positivliste — dazu, was eine Community braucht:
 *
 * - **Formate** (TB 49): fett, kursiv, unterstrichen, durchgestrichen, markiert in fünf Farben, hoch- und tiefgestellt,
 *   Code; Überschriften (Stufen 2–4), Ausrichtung als Klasse (mitte, rechts, Blocksatz), Listen auch verschachtelt;
 * - **Tabellen** (höchstens 50 Zeilen × 12 Spalten, Zellen mit Absätzen, Kopfzellen der ersten Zeile mit `scope`);
 * - **Zitate** (`blockquote` mit `author` und `post`): «Anna schrieb:» mit Sprung zum Beitrag;
 * - **Code** (`codeBlock` mit Sprache, Dateiname und erster Zeilennummer) mit Zeilennummern neben dem Code — die
 *   Hervorhebung macht der Browser nachträglich (Insel `code.ts`); **Spoiler** (`spoiler` mit Titel, aufklappbar);
 * - **Erwähnungen** (`mention` mit Profil-Kennung) — der Name kommt beim Anzeigen frisch aus dem Profil;
 * - **Bilder** nur aus Anhängen dieser Site (`/community/anhang/…`), **Einbettungen** nur YouTube und Vimeo und erst
 *   nach Zustimmung (Zwei-Klick wie im Kern);
 * - **Links** mit `rel="nofollow ugc noopener"` — Spam-Links bringen keinem etwas.
 *
 * Alles andere fällt weg, Text bleibt Text. Die Ausgabe escaped jeden Text.
 */
final class CommunityRichText
{
    public const MAX_DEPTH = 8;

    private const MARKS = ['bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'code' => 'code', 'subscript' => 'sub', 'superscript' => 'sup'];

    /** Farben des Markierstifts — als Klasse, nie als freie Farbe. */
    public const HIGHLIGHTS = ['gelb', 'gruen', 'blau', 'rot', 'violett'];

    /** Ausrichtung aus dem Editor → Klasse; links ist die Grundeinstellung und bleibt ohne Angabe. */
    private const ALIGN = ['center' => 'mitte', 'right' => 'rechts', 'justify' => 'block'];

    /** Sprachen des Codeblocks (Namen von highlight.js) mit Beschriftung — dieselbe Liste wie in der Insel (`code.ts`). */
    public const LANGUAGES = ['plaintext' => 'Text', 'apache' => 'Apache', 'bash' => 'Bash', 'c' => 'C', 'cpp' => 'C++', 'csharp' => 'C#', 'css' => 'CSS', 'diff' => 'Diff',
        'dockerfile' => 'Docker', 'go' => 'Go', 'xml' => 'HTML/XML', 'http' => 'HTTP', 'ini' => 'INI/TOML', 'java' => 'Java', 'javascript' => 'JavaScript', 'json' => 'JSON',
        'kotlin' => 'Kotlin', 'lava' => 'Lava', 'markdown' => 'Markdown', 'nginx' => 'Nginx', 'php' => 'PHP', 'powershell' => 'PowerShell', 'python' => 'Python', 'ruby' => 'Ruby',
        'rust' => 'Rust', 'scss' => 'SCSS', 'shell' => 'Shell', 'sql' => 'SQL', 'swift' => 'Swift', 'typescript' => 'TypeScript', 'yaml' => 'YAML'];

    private const TABLE_ROWS = 50;

    private const TABLE_COLUMNS = 12;

    private const IMAGE_SRC = '#^/community/anhang/\d+/[a-z0-9]{16}\.(webp|png|jpe?g|gif)$#';

    private const EMBED_HOSTS = ['youtube' => ['www.youtube.com', 'youtube.com', 'youtu.be', 'www.youtube-nocookie.com'], 'vimeo' => ['vimeo.com', 'player.vimeo.com']];

    /**
     * Ein Dokument säubern; leer → null.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}|null
     */
    public static function clean(mixed $value): ?array
    {
        if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
            return null;
        }
        // Höchstens 5000 Knoten — Schutz vor riesigen Dokumenten
        $budget = 5000;
        $content = self::blocks(is_array($value['content'] ?? null) ? $value['content'] : [], 0, $budget);
        $doc = ['type' => 'doc', 'content' => $content];

        return self::isEmpty($content) ? null : $doc;
    }

    /**
     * HTML für die Anzeige. `$names` ordnet Profil-Kennungen ihren heutigen Namen zu (Erwähnungen), `$postUrl` macht aus
     * der Kennung eines zitierten Beitrags eine Adresse.
     *
     * @param  array<string, mixed>|null  $doc
     * @param  array<int, string>  $names
     * @param  (callable(int): ?string)|null  $postUrl
     */
    public static function toHtml(?array $doc, string $lang = 'de', array $names = [], ?callable $postUrl = null): string
    {
        if ($doc === null) {
            return '';
        }

        return self::render($doc, $lang, $names, $postUrl);
    }

    /**
     * Die Profile, die ein Dokument erwähnt.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<int>
     */
    public static function mentions(?array $doc): array
    {
        $ids = [];
        $walk = static function (array $node) use (&$walk, &$ids): void {
            if (($node['type'] ?? null) === 'mention' && is_numeric($node['attrs']['id'] ?? null) && ! ($node['attrs']['group'] ?? false)) {
                $ids[] = (int) $node['attrs']['id'];
            }
            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        if ($doc !== null) {
            $walk($doc);
        }

        return array_values(array_unique($ids));
    }

    /**
     * TB 52: die Besuchergruppen, die ein Dokument erwähnt (`@Moderation`).
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<int>
     */
    public static function groupMentions(?array $doc): array
    {
        $ids = [];
        $walk = static function (array $node) use (&$walk, &$ids): void {
            if (($node['type'] ?? null) === 'mention' && ($node['attrs']['group'] ?? false) === true && is_numeric($node['attrs']['id'] ?? null)) {
                $ids[] = (int) $node['attrs']['id'];
            }
            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        if ($doc !== null) {
            $walk($doc);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Die Anhänge, die ein Dokument als Bild zeigt.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<int>
     */
    public static function images(?array $doc): array
    {
        $ids = [];
        $walk = static function (array $node) use (&$walk, &$ids): void {
            if (($node['type'] ?? null) === 'image' && is_numeric($node['attrs']['attachment'] ?? null)) {
                $ids[] = (int) $node['attrs']['attachment'];
            }
            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        if ($doc !== null) {
            $walk($doc);
        }

        return array_values(array_unique($ids));
    }

    // ── Säubern ─────────────────────────────────────────────────

    /**
     * @param  array<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $nodes, int $depth, int &$budget): array
    {
        $out = [];
        $inline = [];
        if ($depth > self::MAX_DEPTH) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (! is_array($node) || --$budget < 0) {
                continue;
            }
            $type = $node['type'] ?? null;
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $children = is_array($node['content'] ?? null) ? $node['content'] : [];
            if (in_array($type, ['text', 'hardBreak', 'mention'], true)) {
                $inline = [...$inline, ...self::inline([$node], $budget)];

                continue;
            }
            if ($inline !== []) {
                $out[] = ['type' => 'paragraph', 'content' => $inline];
                $inline = [];
            }
            $clean = match ($type) {
                'paragraph' => ['type' => 'paragraph'] + self::alignAttr($attrs) + self::contentOf(self::inline($children, $budget)),
                'heading' => self::heading($attrs, self::inline($children, $budget)),
                'table' => self::table($children, $depth, $budget),
                'bulletList', 'orderedList' => self::list((string) $type, $children, $depth, $budget),
                'blockquote' => self::quote($attrs, $children, $depth, $budget),
                'codeBlock' => self::code($attrs, $children),
                'spoiler' => ['type' => 'spoiler'] + self::titleAttr($attrs) + ['content' => self::blocks($children, $depth + 1, $budget) ?: [['type' => 'paragraph']]],
                'image' => self::image($attrs),
                'embed', 'youtube' => self::embed($type === 'youtube' ? ['provider' => 'youtube'] + $attrs : $attrs),
                'horizontalRule' => ['type' => 'horizontalRule'],
                default => null,
            };
            if ($clean !== null) {
                $out[] = $clean;
            }
        }
        if ($inline !== []) {
            $out[] = ['type' => 'paragraph', 'content' => $inline];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $attrs
     * @return array{attrs?: array{textAlign: string}}
     */
    private static function alignAttr(array $attrs): array
    {
        $align = $attrs['textAlign'] ?? null;

        return is_string($align) && isset(self::ALIGN[$align]) ? ['attrs' => ['textAlign' => $align]] : [];
    }

    /**
     * Überschrift der Stufe 2 bis 4 — leer bleibt sie weg.
     *
     * @param  array<mixed>  $attrs
     * @param  list<array<string, mixed>>  $inline
     * @return array<string, mixed>|null
     */
    private static function heading(array $attrs, array $inline): ?array
    {
        if ($inline === []) {
            return null;
        }
        $level = is_numeric($attrs['level'] ?? null) ? max(2, min(4, (int) $attrs['level'])) : 2;
        $align = self::alignAttr($attrs);

        return ['type' => 'heading', 'attrs' => ['level' => $level] + ($align['attrs'] ?? []), 'content' => $inline];
    }

    /**
     * Tabelle: Zeilen mit Kopf- und Datenzellen, Zellen mit Absätzen; Spannen 1–10. Ohne Zeilen bleibt sie weg.
     *
     * @param  array<mixed>  $children
     * @return array<string, mixed>|null
     */
    private static function table(array $children, int $depth, int &$budget): ?array
    {
        $rows = [];
        foreach ($children as $row) {
            if (! is_array($row) || ($row['type'] ?? null) !== 'tableRow' || count($rows) >= self::TABLE_ROWS) {
                continue;
            }
            $cells = [];
            foreach (is_array($row['content'] ?? null) ? $row['content'] : [] as $cell) {
                $type = is_array($cell) ? ($cell['type'] ?? null) : null;
                if (! in_array($type, ['tableHeader', 'tableCell'], true) || count($cells) >= self::TABLE_COLUMNS || --$budget < 0) {
                    continue;
                }
                $blocks = array_values(array_filter(self::blocks(is_array($cell['content'] ?? null) ? $cell['content'] : [], $depth + 1, $budget),
                    static fn (array $node): bool => in_array($node['type'], ['paragraph', 'bulletList', 'orderedList'], true)));
                $cellAttrs = [];
                foreach (['colspan', 'rowspan'] as $span) {
                    $value = is_numeric($cell['attrs'][$span] ?? null) ? max(1, min(10, (int) $cell['attrs'][$span])) : 1;
                    if ($value > 1) {
                        $cellAttrs[$span] = $value;
                    }
                }
                $cells[] = ['type' => $type] + ($cellAttrs === [] ? [] : ['attrs' => $cellAttrs]) + ['content' => $blocks ?: [['type' => 'paragraph']]];
            }
            if ($cells !== []) {
                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return $rows === [] ? null : ['type' => 'table', 'content' => $rows];
    }

    /**
     * @param  array<mixed>  $children
     * @return array<string, mixed>|null
     */
    private static function list(string $type, array $children, int $depth, int &$budget): ?array
    {
        $items = [];
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'listItem') {
                $items[] = ['type' => 'listItem', 'content' => self::blocks(is_array($child['content'] ?? null) ? $child['content'] : [], $depth + 1, $budget) ?: [['type' => 'paragraph']]];
            }
        }

        return $items === [] ? null : ['type' => $type, 'content' => $items];
    }

    /**
     * @param  array<mixed>  $attrs
     * @param  array<mixed>  $children
     * @return array<string, mixed>
     */
    private static function quote(array $attrs, array $children, int $depth, int &$budget): array
    {
        $author = is_string($attrs['author'] ?? null) ? mb_substr(trim($attrs['author']), 0, 40) : '';
        $post = is_numeric($attrs['post'] ?? null) ? (int) $attrs['post'] : 0;
        $quoteAttrs = array_filter(['author' => $author, 'post' => $post], static fn (mixed $v): bool => $v !== '' && $v !== 0);

        return ['type' => 'blockquote'] + ($quoteAttrs === [] ? [] : ['attrs' => $quoteAttrs]) + ['content' => self::blocks($children, $depth + 1, $budget) ?: [['type' => 'paragraph']]];
    }

    /**
     * @param  array<mixed>  $attrs
     * @param  array<mixed>  $children
     * @return array<string, mixed>|null
     */
    private static function code(array $attrs, array $children): ?array
    {
        $text = '';
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'text' && is_string($child['text'] ?? null)) {
                $text .= $child['text'];
            } elseif (is_array($child) && ($child['type'] ?? null) === 'hardBreak') {
                $text .= "\n";
            }
        }
        $text = mb_substr((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text), 0, 20000);
        $language = is_string($attrs['language'] ?? null) ? strtolower((string) preg_replace('/[^a-z0-9_+-]/i', '', mb_substr($attrs['language'], 0, 20))) : '';
        $filename = is_string($attrs['filename'] ?? null) ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F<>"]/u', '', $attrs['filename'])), 0, 120) : '';
        $start = is_numeric($attrs['lineStart'] ?? null) ? max(1, min(999999, (int) $attrs['lineStart'])) : 1;
        $codeAttrs = array_filter(['language' => $language, 'filename' => $filename, 'lineStart' => $start === 1 ? 0 : $start], static fn (mixed $v): bool => $v !== '' && $v !== 0);

        return $text === '' ? null : ['type' => 'codeBlock'] + ($codeAttrs === [] ? [] : ['attrs' => $codeAttrs]) + ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param  array<mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function titleAttr(array $attrs): array
    {
        $title = is_string($attrs['title'] ?? null) ? mb_substr(trim(strip_tags($attrs['title'])), 0, 60) : '';

        return $title === '' ? [] : ['attrs' => ['title' => $title]];
    }

    /**
     * @param  array<mixed>  $attrs
     * @return array<string, mixed>|null
     */
    private static function image(array $attrs): ?array
    {
        $src = is_string($attrs['src'] ?? null) ? trim($attrs['src']) : '';
        if (preg_match(self::IMAGE_SRC, $src) !== 1) {
            return null;
        }
        $alt = is_string($attrs['alt'] ?? null) ? mb_substr(trim($attrs['alt']), 0, 200) : '';
        $id = is_numeric($attrs['attachment'] ?? null) ? (int) $attrs['attachment'] : (int) explode('/', $src)[3];

        return ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => $alt, 'attachment' => $id]];
    }

    /**
     * @param  array<mixed>  $attrs
     * @return array<string, mixed>|null
     */
    private static function embed(array $attrs): ?array
    {
        $provider = is_string($attrs['provider'] ?? null) && isset(self::EMBED_HOSTS[$attrs['provider']]) ? $attrs['provider'] : null;
        $src = is_string($attrs['src'] ?? null) ? trim($attrs['src']) : '';
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        if ($provider === null || ! str_starts_with($src, 'https://') || ! in_array($host, self::EMBED_HOSTS[$provider], true) || mb_strlen($src) > 500) {
            return null;
        }

        return ['type' => 'embed', 'attrs' => ['provider' => $provider, 'src' => $src]];
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function inline(array $nodes, int &$budget): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || --$budget < 0) {
                continue;
            }
            $type = $node['type'] ?? null;
            if ($type === 'hardBreak') {
                $out[] = ['type' => 'hardBreak'];

                continue;
            }
            if ($type === 'mention') {
                $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
                if (is_numeric($attrs['id'] ?? null) && (int) $attrs['id'] > 0) {
                    $out[] = ['type' => 'mention', 'attrs' => ['id' => (int) $attrs['id'], 'label' => is_string($attrs['label'] ?? null) ? mb_substr(trim($attrs['label']), 0, 40) : '']
                        + (($attrs['group'] ?? false) === true ? ['group' => true] : [])];
                }

                continue;
            }
            if ($type !== 'text' || ! is_string($node['text'] ?? null)) {
                continue;
            }
            $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $node['text']);
            if ($text === '') {
                continue;
            }
            $marks = self::marks(is_array($node['marks'] ?? null) ? $node['marks'] : []);
            $out[] = ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks]);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $marks
     * @return list<array<string, mixed>>
     */
    private static function marks(array $marks): array
    {
        $out = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! is_string($type) || isset($out[$type])) {
                continue;
            }
            if (isset(self::MARKS[$type])) {
                $out[$type] = ['type' => $type];
            } elseif ($type === 'highlight') {
                $color = is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['color'] ?? null) ? $mark['attrs']['color'] : '';
                $out['highlight'] = ['type' => 'highlight', 'attrs' => ['color' => in_array($color, self::HIGHLIGHTS, true) ? $color : self::HIGHLIGHTS[0]]];
            } elseif ($type === 'link') {
                $href = is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['href'] ?? null) ? trim($mark['attrs']['href']) : '';
                if ($href !== '' && mb_strlen($href) <= 2000 && preg_match('#^(https?://|mailto:|/(?!/)|\#)#i', $href) === 1) {
                    $out['link'] = ['type' => 'link', 'attrs' => ['href' => $href]];
                }
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array{content?: list<array<string, mixed>>}
     */
    private static function contentOf(array $content): array
    {
        return $content === [] ? [] : ['content' => $content];
    }

    /** @param  list<array<string, mixed>>  $content */
    private static function isEmpty(array $content): bool
    {
        foreach ($content as $node) {
            if (in_array($node['type'], ['image', 'embed', 'codeBlock', 'horizontalRule', 'table'], true) || RichText::toText(['type' => 'doc', 'content' => [$node]]) !== '' || self::mentions($node) !== []) {
                return false;
            }
        }

        return true;
    }

    // ── Darstellen ──────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $names
     * @param  (callable(int): ?string)|null  $postUrl
     */
    private static function render(array $node, string $lang, array $names, ?callable $postUrl): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $children = implode('', array_map(static fn (mixed $c): string => is_array($c) ? self::render($c, $lang, $names, $postUrl) : '', is_array($node['content'] ?? null) ? $node['content'] : []));
        $e = static fn (mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES, 'UTF-8');

        return match ($node['type'] ?? null) {
            'doc' => $children,
            'paragraph' => '<p'.self::alignClass($attrs).'>'.$children.'</p>',
            'heading' => sprintf('<h%1$d class="forum-ueberschrift%3$s">%2$s</h%1$d>', max(2, min(4, (int) ($attrs['level'] ?? 2))), $children, self::alignClass($attrs, false)),
            'table' => '<div class="forum-tabelle"><table>'.self::renderRows($node, $lang, $names, $postUrl).'</table></div>',
            'bulletList' => '<ul>'.$children.'</ul>',
            'orderedList' => '<ol>'.$children.'</ol>',
            'listItem' => '<li>'.$children.'</li>',
            'blockquote' => self::renderQuote($attrs, $children, $lang, $postUrl),
            'codeBlock' => self::renderCode($attrs, self::plain($node), $lang),
            'spoiler' => sprintf('<details class="forum-spoiler"><summary>%s</summary><div class="forum-spoiler__inhalt">%s</div></details>', $e($attrs['title'] ?? __('Spoiler', [], $lang)), $children),
            'image' => sprintf('<figure class="forum-bild"><a href="%1$s"><img src="%1$s" alt="%2$s" loading="lazy"></a></figure>', $e($attrs['src'] ?? ''), $e($attrs['alt'] ?? '')),
            'embed' => RichText::toHtml(['type' => 'doc', 'content' => [['type' => 'embed', 'attrs' => $attrs]]]),
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'mention' => ($attrs['group'] ?? false) === true
                ? sprintf('<span class="forum-mention forum-mention--gruppe">@%s</span>', $e($attrs['label'] ?? ''))
                : sprintf('<span class="forum-mention">@%s</span>', $e($names[(int) ($attrs['id'] ?? 0)] ?? ($attrs['label'] ?? ''))),
            'text' => self::text((string) ($node['text'] ?? ''), is_array($node['marks'] ?? null) ? $node['marks'] : []),
            default => $children,
        };
    }

    /**
     * Klasse der Ausrichtung — als ganzes Attribut (`$attribute`) oder als Zusatz zu einer vorhandenen Klasse.
     *
     * @param  array<mixed>  $attrs
     */
    private static function alignClass(array $attrs, bool $attribute = true): string
    {
        $align = is_string($attrs['textAlign'] ?? null) ? (self::ALIGN[$attrs['textAlign']] ?? null) : null;
        if ($align === null) {
            return '';
        }

        return $attribute ? ' class="forum-text--'.$align.'"' : ' forum-text--'.$align;
    }

    /**
     * Zeilen einer Tabelle; Kopfzellen der ersten Zeile beschriften Spalten, spätere ihre Zeile.
     *
     * @param  array<string, mixed>  $table
     * @param  array<int, string>  $names
     * @param  (callable(int): ?string)|null  $postUrl
     */
    private static function renderRows(array $table, string $lang, array $names, ?callable $postUrl): string
    {
        $html = '';
        foreach (is_array($table['content'] ?? null) ? $table['content'] : [] as $index => $row) {
            $cells = '';
            foreach (is_array($row['content'] ?? null) ? $row['content'] : [] as $cell) {
                $attrs = is_array($cell['attrs'] ?? null) ? $cell['attrs'] : [];
                $span = '';
                foreach (['colspan', 'rowspan'] as $name) {
                    if (is_numeric($attrs[$name] ?? null) && (int) $attrs[$name] > 1) {
                        $span .= sprintf(' %s="%d"', $name, min(10, (int) $attrs[$name]));
                    }
                }
                $content = implode('', array_map(static fn (mixed $c): string => is_array($c) ? self::render($c, $lang, $names, $postUrl) : '', is_array($cell['content'] ?? null) ? $cell['content'] : []));
                $cells .= ($cell['type'] ?? null) === 'tableHeader'
                    ? sprintf('<th scope="%s"%s>%s</th>', $index === 0 ? 'col' : 'row', $span, $content)
                    : sprintf('<td%s>%s</td>', $span, $content);
            }
            $html .= '<tr>'.$cells.'</tr>';
        }

        return $html;
    }

    /**
     * Codeblock: Kopf mit Dateiname und Sprache, Zeilennummern daneben (für Vorlesen und Kopieren unsichtbar), der Code
     * selbst unverändert in `<pre><code>`. Die Farben setzt der Browser nachträglich (`data-sprache`, leer = erkennen).
     *
     * @param  array<mixed>  $attrs
     */
    private static function renderCode(array $attrs, string $code, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $language = is_string($attrs['language'] ?? null) ? $attrs['language'] : '';
        $filename = is_string($attrs['filename'] ?? null) ? $attrs['filename'] : '';
        $label = $language === '' ? '' : (self::LANGUAGES[$language] ?? strtoupper($language));
        $start = is_numeric($attrs['lineStart'] ?? null) ? max(1, (int) $attrs['lineStart']) : 1;
        $lines = substr_count(rtrim($code, "\n"), "\n") + 1;
        $numbers = $lines > 1 || $start > 1 ? sprintf('<span class="forum-code__zeilen" aria-hidden="true">%s</span>', implode("\n", range($start, $start + $lines - 1))) : '';
        $head = sprintf('<figcaption class="forum-code__kopf"><span class="forum-code__titel">%s</span>%s</figcaption>',
            $e($filename !== '' ? $filename : ($label !== '' ? $label : (string) __('Code', [], $lang))), $filename !== '' && $label !== '' ? '<span class="forum-code__sprache">'.$e($label).'</span>' : '');

        return sprintf('<figure class="forum-code" data-insel="code">%s<div class="forum-code__rahmen">%s<pre><code%s data-sprache="%s">%s</code></pre></div></figure>',
            $head, $numbers, $language === '' || $language === 'plaintext' ? '' : ' class="language-'.$e($language).'"', $e($language), $e($code));
    }

    /**
     * @param  array<mixed>  $attrs
     * @param  (callable(int): ?string)|null  $postUrl
     */
    private static function renderQuote(array $attrs, string $children, string $lang, ?callable $postUrl): string
    {
        $author = is_string($attrs['author'] ?? null) ? $attrs['author'] : '';
        if ($author === '') {
            return '<blockquote>'.$children.'</blockquote>';
        }
        $url = is_numeric($attrs['post'] ?? null) && $postUrl !== null ? $postUrl((int) $attrs['post']) : null;
        $label = htmlspecialchars((string) __(':name schrieb:', ['name' => $author], $lang), ENT_QUOTES, 'UTF-8');

        return sprintf('<blockquote class="forum-quote"><p class="forum-quote__kopf">%s</p>%s</blockquote>', $url === null ? $label : sprintf('<a href="%s">%s</a>', htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $label), $children);
    }

    /** @param  array<string, mixed>  $node */
    private static function plain(array $node): string
    {
        $out = '';
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child) && is_string($child['text'] ?? null)) {
                $out .= $child['text'];
            }
        }

        return $out;
    }

    /** @param  array<mixed>  $marks */
    private static function text(string $text, array $marks): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        foreach (array_reverse($marks) as $mark) {
            $type = is_array($mark) ? (string) ($mark['type'] ?? '') : '';
            if (isset(self::MARKS[$type])) {
                $html = sprintf('<%1$s>%2$s</%1$s>', self::MARKS[$type], $html);
            } elseif ($type === 'highlight') {
                $color = is_string($mark['attrs']['color'] ?? null) && in_array($mark['attrs']['color'], self::HIGHLIGHTS, true) ? $mark['attrs']['color'] : self::HIGHLIGHTS[0];
                $html = sprintf('<mark class="forum-markiert forum-markiert--%s">%s</mark>', $color, $html);
            } elseif ($type === 'link' && is_string($mark['attrs']['href'] ?? null)) {
                $href = $mark['attrs']['href'];
                $external = preg_match('#^https?://#i', $href) === 1;
                $html = sprintf('<a href="%s"%s>%s</a>', htmlspecialchars($href, ENT_QUOTES, 'UTF-8'), $external ? ' rel="nofollow ugc noopener" target="_blank"' : '', $html);
            }
        }

        return $html;
    }
}
