<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai\Support;

use Basalto\Core\Content\MarkdownDocument;
use Basalto\Core\Content\RichText;

/**
 * Fliesstext ↔ Markdown für die KI (M-24): Hin geht der Text als Markdown (Überschriften, Absätze, Listen, Zitate,
 * Code, fett, kursiv, Code und Links), zurück kommt Markdown, das der Säuberer des Kerns ({@see MarkdownDocument}:
 * rohes HTML fällt weg, unsichere Links auch) wieder zum Dokument des Editors macht — Links zusätzlich nur mit
 * http(s), mailto, tel oder relativ. Enthält der Text Bilder, Tabellen, Aufgaben oder Einbettungen, gibt es kein
 * Markdown: Die gingen auf dem Weg verloren, also macht die KI dort nichts.
 */
final class RichTextMarkdown
{
    private const UNSUPPORTED = ['image', 'youtube', 'embed', 'table', 'taskList', 'taskItem'];

    /** Das Dokument als Markdown — null, wenn es Knoten enthält, die Markdown nicht trägt. */
    public static function fromValue(mixed $value): ?string
    {
        $doc = RichText::doc($value);
        if ($doc === null) {
            return '';
        }
        if (self::hasUnsupported($doc)) {
            return null;
        }

        return trim(self::blocks(self::children($doc), ''));
    }

    /** @return array{type: 'doc', content: list<array<string, mixed>>} */
    public static function toDoc(string $markdown): array
    {
        return (new MarkdownDocument(static fn (string $url): ?string => self::safeLink($url)))->convert($markdown);
    }

    public static function safeLink(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return null;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : null;
    }

    /** @param  array<string, mixed>  $node */
    private static function hasUnsupported(array $node): bool
    {
        if (in_array($node['type'] ?? '', self::UNSUPPORTED, true)) {
            return true;
        }
        foreach (self::children($node) as $child) {
            if (self::hasUnsupported($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function children(array $node): array
    {
        return is_array($node['content'] ?? null) ? array_values(array_filter($node['content'], 'is_array')) : [];
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private static function blocks(array $nodes, string $indent): string
    {
        $parts = [];
        foreach ($nodes as $node) {
            $parts[] = self::block($node, $indent);
        }

        return implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /** @param  array<string, mixed>  $node */
    private static function block(array $node, string $indent): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        return match ($node['type'] ?? '') {
            'paragraph' => $indent.self::inlines(self::children($node), $indent),
            'heading' => $indent.str_repeat('#', max(1, min(6, (int) ($attrs['level'] ?? 2)))).' '.self::inlines(self::children($node), $indent),
            'bulletList' => self::listItems(self::children($node), $indent, null),
            'orderedList' => self::listItems(self::children($node), $indent, max(1, (int) ($attrs['start'] ?? 1))),
            'blockquote' => implode("\n", array_map(static fn (string $line): string => $indent.'> '.ltrim(substr($line, strlen($indent))), explode("\n", self::blocks(self::children($node), $indent)))),
            'codeBlock' => $indent."```\n".$indent.str_replace("\n", "\n".$indent, self::plain($node))."\n".$indent.'```',
            'horizontalRule' => $indent.'---',
            default => $indent.self::inlines(self::children($node), $indent),
        };
    }

    /** @param  list<array<string, mixed>>  $items */
    private static function listItems(array $items, string $indent, ?int $start): string
    {
        $lines = [];
        foreach ($items as $i => $item) {
            $marker = $start === null ? '- ' : ($start + $i).'. ';
            $inner = self::blocks(self::children($item), $indent.str_repeat(' ', strlen($marker)));
            $lines[] = $indent.$marker.ltrim($inner);
        }

        return implode("\n", $lines);
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private static function inlines(array $nodes, string $indent): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'hardBreak') {
                $out .= "\\\n".$indent;

                continue;
            }
            if (($node['type'] ?? '') !== 'text') {
                $out .= self::inlines(self::children($node), $indent);

                continue;
            }
            $text = (string) ($node['text'] ?? '');
            $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];
            $types = array_map(static fn (mixed $m): string => is_array($m) ? (string) ($m['type'] ?? '') : '', $marks);
            if (in_array('code', $types, true)) {
                $out .= '`'.str_replace('`', '', $text).'`';

                continue;
            }
            $piece = self::escape($text);
            if (in_array('italic', $types, true)) {
                $piece = '*'.$piece.'*';
            }
            if (in_array('bold', $types, true)) {
                $piece = '**'.$piece.'**';
            }
            foreach ($marks as $mark) {
                if (is_array($mark) && ($mark['type'] ?? '') === 'link' && is_string($mark['attrs']['href'] ?? null)) {
                    $piece = '['.$piece.']('.str_replace([' ', ')'], ['%20', '%29'], $mark['attrs']['href']).')';
                }
            }
            $out .= $piece;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $node */
    private static function plain(array $node): string
    {
        $out = '';
        foreach (self::children($node) as $child) {
            $out .= ($child['type'] ?? '') === 'text' ? (string) ($child['text'] ?? '') : self::plain($child);
        }

        return $out;
    }

    private static function escape(string $text): string
    {
        $text = (string) preg_replace('/([\\\\`*_\[\]<>])/u', '\\\\$1', $text);

        return (string) preg_replace('/^(\s*)([#+\-]|\d+\.)(\s)/u', '$1\\\\$2$3', $text);
    }
}
