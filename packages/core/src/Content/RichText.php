<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

/**
 * Fliesstext aus dem Block-Editor (TipTap/ProseMirror-JSON) → HTML für das Theme und → Text für Diff und Suche (M-04).
 * Der Editor speichert nur strukturiertes JSON, nie HTML. Hier ist die Liste der Knoten und Marken, die Basalto kennt;
 * Unbekanntes fällt auf das nächstliegende Element zurück (Kinder bleiben, Marke entfällt). Text wird immer escaped,
 * Links nur mit sicheren Schemas, Bilder nur mit sicheren Quellen, Einbettungen erst nach Zustimmung (Klick).
 */
final class RichText
{
    private const MARKS = ['bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'code' => 'code', 'subscript' => 'sub', 'superscript' => 'sup'];

    private const ALIGNS = ['left', 'center', 'right', 'justify'];

    private const EMBED_HOSTS = ['youtube' => ['www.youtube.com', 'youtube.com', 'youtu.be', 'www.youtube-nocookie.com'], 'vimeo' => ['vimeo.com', 'player.vimeo.com'], 'map' => ['www.google.com', 'maps.google.com', 'www.openstreetmap.org', 'umap.openstreetmap.fr']];

    /** Fliesstext als HTML — nimmt auch Klartext aus der Zeit vor einem Typwechsel (siehe {@see doc()}). */
    public static function toHtml(mixed $value): string
    {
        $doc = self::doc($value);

        return $doc === null ? '' : self::node($doc);
    }

    public static function toText(mixed $value): string
    {
        $doc = self::doc($value);
        if ($doc === null) {
            return '';
        }
        $parts = [];
        self::collectText($doc, $parts);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * Der Wert eines Fliesstextfelds als Dokument. Klartext — ein Feld, das vorher `textarea` war, oder ein Wert aus
     * dem Seed — wird zu Absätzen: Leerzeilen trennen Absätze, einfache Zeilenumbrüche bleiben Umbrüche.
     *
     * @return array<string, mixed>|null
     */
    public static function doc(mixed $value): ?array
    {
        if (is_array($value)) {
            return ($value['type'] ?? null) === 'doc' ? $value : null;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $paragraphs = [];
        foreach (preg_split('/\R\s*\R/u', trim($value)) ?: [] as $paragraph) {
            $content = [];
            foreach (preg_split('/\R/u', trim($paragraph)) ?: [] as $i => $line) {
                if ($i > 0) {
                    $content[] = ['type' => 'hardBreak'];
                }
                if ($line !== '') {
                    $content[] = ['type' => 'text', 'text' => $line];
                }
            }
            $paragraphs[] = ['type' => 'paragraph', 'content' => $content];
        }

        return ['type' => 'doc', 'content' => $paragraphs];
    }

    /**
     * HTML → Dokument des Editors (TB 16 Nachtrag): für Startinhalte in Lava-Seeds. Kennt Absätze, Zwischentitel,
     * Listen, Zitate, Trennlinien, Zeilenumbrüche und die Auszeichnungen fett, kursiv, unterstrichen, durchgestrichen,
     * Code und Link; Unbekanntes wird durchgereicht, Skripte und Stile verworfen.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public static function fromHtml(string $html): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementsByTagName('div')->item(0);

        return ['type' => 'doc', 'content' => $root === null ? [] : self::blocks($root)];
    }

    /** @return list<array<string, mixed>> */
    private static function blocks(\DOMNode $parent): array
    {
        $blocks = [];
        $loose = [];
        $flush = static function () use (&$loose, &$blocks): void {
            $content = self::trimInline($loose);
            if ($content !== []) {
                $blocks[] = ['type' => 'paragraph', 'content' => $content];
            }
            $loose = [];
        };
        foreach ($parent->childNodes as $child) {
            $name = $child instanceof \DOMElement ? strtolower($child->tagName) : '#text';
            if (in_array($name, ['script', 'style'], true)) {
                continue;
            }
            $block = match (true) {
                $name === 'p' => ['type' => 'paragraph', 'content' => self::trimInline(self::inline($child, []))],
                preg_match('/^h([1-6])$/', $name, $level) === 1 => ['type' => 'heading', 'attrs' => ['level' => (int) $level[1]], 'content' => self::trimInline(self::inline($child, []))],
                $name === 'ul' || $name === 'ol' => ['type' => $name === 'ul' ? 'bulletList' : 'orderedList', 'content' => self::items($child)],
                $name === 'blockquote' => ['type' => 'blockquote', 'content' => self::blocks($child)],
                $name === 'hr' => ['type' => 'horizontalRule'],
                in_array($name, ['div', 'section', 'article'], true) => ['type' => '#nested', 'content' => self::blocks($child)],
                default => null,
            };
            if ($block === null) {
                array_push($loose, ...self::inline($child, []));

                continue;
            }
            $flush();
            if ($block['type'] === '#nested') {
                array_push($blocks, ...$block['content']);
            } elseif (! in_array($block['type'], ['paragraph', 'heading'], true) || $block['content'] !== []) {
                $blocks[] = $block;
            }
        }
        $flush();

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private static function items(\DOMNode $list): array
    {
        $items = [];
        foreach ($list->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'li') {
                $content = self::blocks($child);
                $items[] = ['type' => 'listItem', 'content' => $content === [] ? [['type' => 'paragraph']] : $content];
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private static function inline(\DOMNode $node, array $marks): array
    {
        if ($node instanceof \DOMText) {
            $text = (string) preg_replace('/\s+/u', ' ', $node->wholeText);

            return $text === '' ? [] : [['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks])];
        }
        if (! $node instanceof \DOMElement) {
            return [];
        }
        $name = strtolower($node->tagName);
        if ($name === 'br') {
            return [['type' => 'hardBreak']];
        }
        $mark = match ($name) {
            'strong', 'b' => ['type' => 'bold'],
            'em', 'i' => ['type' => 'italic'],
            'u' => ['type' => 'underline'],
            's', 'del', 'strike' => ['type' => 'strike'],
            'code' => ['type' => 'code'],
            'a' => ['type' => 'link', 'attrs' => array_filter(['href' => $node->getAttribute('href'), 'target' => $node->getAttribute('target') ?: null], static fn (?string $v): bool => $v !== null && $v !== '')],
            default => null,
        };
        $inner = [];
        foreach ($node->childNodes as $child) {
            array_push($inner, ...self::inline($child, $mark === null ? $marks : [...$marks, $mark]));
        }

        return $inner;
    }

    /**
     * Leerraum am Anfang und Ende eines Absatzes weg — der Editor kennt ihn nicht.
     *
     * @param  list<array<string, mixed>>  $content
     * @return list<array<string, mixed>>
     */
    private static function trimInline(array $content): array
    {
        if ($content !== [] && ($content[0]['type'] ?? '') === 'text') {
            $content[0]['text'] = ltrim((string) $content[0]['text']);
        }
        $last = count($content) - 1;
        if ($last >= 0 && ($content[$last]['type'] ?? '') === 'text') {
            $content[$last]['text'] = rtrim((string) $content[$last]['text']);
        }

        return array_values(array_filter($content, static fn (array $n): bool => ($n['type'] ?? '') !== 'text' || (string) ($n['text'] ?? '') !== ''));
    }

    /** @param  array<string, mixed>  $node */
    private static function node(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        $children = is_array($node['content'] ?? null) ? implode('', array_map(static fn (mixed $c): string => is_array($c) ? self::node($c) : '', $node['content'])) : '';
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $align = self::alignAttribute($attrs);

        return match ($type) {
            'doc' => $children,
            'paragraph' => '<p'.$align.'>'.$children.'</p>',
            'heading' => sprintf('<h%1$d%3$s>%2$s</h%1$d>', max(1, min(6, (int) ($attrs['level'] ?? 2))), $children, $align),
            'text' => self::text((string) ($node['text'] ?? ''), is_array($node['marks'] ?? null) ? array_values($node['marks']) : []),
            'bulletList' => '<ul>'.$children.'</ul>',
            'orderedList' => (isset($attrs['start']) && (int) $attrs['start'] !== 1 ? sprintf('<ol start="%d">', (int) $attrs['start']) : '<ol>').$children.'</ol>',
            'listItem' => '<li>'.$children.'</li>',
            'taskList' => '<ul class="tasks">'.$children.'</ul>',
            'taskItem' => sprintf('<li class="task%s"><input type="checkbox" disabled%s> <span>%s</span></li>', ($attrs['checked'] ?? false) ? ' done' : '', ($attrs['checked'] ?? false) ? ' checked' : '', $children),
            'blockquote' => '<blockquote>'.$children.'</blockquote>',
            'codeBlock' => self::codeBlock($attrs, $children),
            'hardBreak' => '<br>',
            'horizontalRule' => '<hr>',
            'table' => self::table($node),
            'tableRow' => '<tr>'.$children.'</tr>',
            'tableCell' => '<td'.self::span($attrs).'>'.$children.'</td>',
            'tableHeader' => '<th'.self::span($attrs).'>'.$children.'</th>',
            'image' => self::image($attrs),
            'youtube' => self::embed('youtube', (string) ($attrs['src'] ?? '')),
            'embed' => self::embed((string) ($attrs['provider'] ?? 'vimeo'), (string) ($attrs['src'] ?? '')),
            default => $children,
        };
    }

    /** @param  array<string, mixed>  $node */
    private static function table(array $node): string
    {
        $rows = is_array($node['content'] ?? null) ? array_values(array_filter($node['content'], 'is_array')) : [];
        $head = '';
        $body = '';
        foreach ($rows as $i => $row) {
            $cells = is_array($row['content'] ?? null) ? array_values(array_filter($row['content'], 'is_array')) : [];
            $isHeader = $i === 0 && $cells !== [] && count(array_filter($cells, static fn (array $c): bool => ($c['type'] ?? '') === 'tableHeader')) === count($cells);
            $html = self::node($row);
            if ($isHeader) {
                $head .= $html;
            } else {
                $body .= $html;
            }
        }

        return '<table>'.($head !== '' ? '<thead>'.$head.'</thead>' : '').'<tbody>'.$body.'</tbody></table>';
    }

    /** @param  array<string, mixed>  $attrs */
    private static function span(array $attrs): string
    {
        $out = '';
        foreach (['colspan', 'rowspan'] as $key) {
            if (isset($attrs[$key]) && (int) $attrs[$key] > 1) {
                $out .= sprintf(' %s="%d"', $key, (int) $attrs[$key]);
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $attrs */
    private static function alignAttribute(array $attrs): string
    {
        $align = $attrs['textAlign'] ?? null;

        return is_string($align) && $align !== 'left' && in_array($align, self::ALIGNS, true) ? sprintf(' class="align-%s"', $align) : '';
    }

    /** @param  array<string, mixed>  $attrs */
    private static function codeBlock(array $attrs, string $children): string
    {
        $language = is_string($attrs['language'] ?? null) ? preg_replace('/[^a-z0-9_+-]/i', '', $attrs['language']) : '';

        return sprintf('<pre><code%s>%s</code></pre>', $language !== '' && $language !== null ? sprintf(' class="language-%s"', $language) : '', $children);
    }

    /**
     * Bild aus der Mediathek: Quelle nur relativ oder https, mit Alt-Text, Beschriftung und Ausrichtung als figure.
     *
     * @param  array<string, mixed>  $attrs
     */
    private static function image(array $attrs): string
    {
        $src = self::safeSrc((string) ($attrs['src'] ?? ''));
        if ($src === null) {
            return '';
        }
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $align = is_string($attrs['align'] ?? null) && in_array($attrs['align'], ['left', 'center', 'right'], true) ? ' image-'.$attrs['align'] : '';
        $img = sprintf('<img src="%s" alt="%s"%s loading="lazy">', $e($src), $e($attrs['alt'] ?? ''), isset($attrs['title']) && $attrs['title'] !== '' ? sprintf(' title="%s"', $e($attrs['title'])) : '');
        $caption = is_string($attrs['caption'] ?? null) && trim($attrs['caption']) !== '' ? sprintf('<figcaption>%s</figcaption>', $e($attrs['caption'])) : '';

        return sprintf('<figure class="image%s"%s>%s%s</figure>', $align, is_numeric($attrs['mediaId'] ?? null) ? sprintf(' data-media="%d"', (int) $attrs['mediaId']) : '', $img, $caption);
    }

    /** Einbettung nach Zustimmung (K-14, Datenschutz): kein iframe im HTML, nur Platzhalter mit data-src; der Klick lädt. */
    private static function embed(string $provider, string $src): string
    {
        $provider = in_array($provider, array_keys(self::EMBED_HOSTS), true) ? $provider : 'vimeo';
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        if ($host === '' || ! in_array($host, self::EMBED_HOSTS[$provider], true) || ! str_starts_with($src, 'https://')) {
            return '';
        }
        $label = match ($provider) {
            'youtube' => 'Video laden (YouTube)',
            'map' => 'Karte laden',
            default => 'Video laden (Vimeo)',
        };

        return sprintf('<div class="embed embed-%1$s" data-src="%2$s"><button type="button" class="embed-consent">%3$s</button><p class="embed-note">Beim Laden werden Daten an %4$s übertragen.</p></div>',
            $provider, htmlspecialchars($src, ENT_QUOTES, 'UTF-8'), $label, htmlspecialchars($host, ENT_QUOTES, 'UTF-8'));
    }

    /** @param  list<mixed>  $marks */
    private static function text(string $text, array $marks): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        foreach (array_reverse($marks) as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            $type = (string) ($mark['type'] ?? '');
            if (isset(self::MARKS[$type])) {
                $html = sprintf('<%1$s>%2$s</%1$s>', self::MARKS[$type], $html);
            } elseif ($type === 'link') {
                $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
                $href = self::safeHref((string) ($attrs['href'] ?? ''));
                if ($href !== null) {
                    $blank = ($attrs['target'] ?? '') === '_blank';
                    $title = is_string($attrs['title'] ?? null) && $attrs['title'] !== '' ? sprintf(' title="%s"', htmlspecialchars($attrs['title'], ENT_QUOTES, 'UTF-8')) : '';
                    $html = sprintf('<a href="%s"%s rel="noopener%s"%s>%s</a>', htmlspecialchars($href, ENT_QUOTES, 'UTF-8'), $title, $blank ? ' noreferrer' : '', $blank ? ' target="_blank"' : '', $html);
                }
            }
        }

        return $html;
    }

    private static function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $href) === 1) {
            return $href;
        }

        return null;
    }

    private static function safeSrc(string $src): ?string
    {
        $src = trim($src);
        if ($src === '' || preg_match('#^(https://|/)#i', $src) !== 1) {
            return null;
        }

        return $src;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $parts
     */
    private static function collectText(array $node, array &$parts): void
    {
        if (($node['type'] ?? '') === 'text') {
            $parts[] = (string) ($node['text'] ?? '');
        }
        if (($node['type'] ?? '') === 'image' && is_array($node['attrs'] ?? null)) {
            $parts[] = (string) ($node['attrs']['alt'] ?? '');
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::collectText($child, $parts);
            }
        }
    }
}
