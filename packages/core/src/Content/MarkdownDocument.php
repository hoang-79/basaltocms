<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Closure;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Markdown → Fliesstext-Dokument (TipTap/ProseMirror-JSON), wie es der Block-Editor speichert (TB 11 Scheibe 4): für
 * `basalto:docs-import`, das die Produkt-Doku aus `docs/` als Inhalte einer Site anlegt. CommonMark mit Tabellen (GFM);
 * Überschriften, Absätze, Listen, Code, Zitate, Trennlinien, Tabellen, fett, kursiv, Code und Links. Rohes HTML fällt
 * weg, Bilder werden zu ihrem Alt-Text. Links gehen durch einen Umschreiber (relative `.md`-Verweise → Doku-Seiten).
 */
final class MarkdownDocument
{
    /**
     * @param  (Closure(string): ?string)|null  $links  Ziel eines Links → neue Adresse, null = Link weglassen
     * @param  (Closure(string): ?string)|null  $images  TB 29: Quelle eines Bildes → Adresse, null = nur der Alt-Text bleibt
     */
    public function __construct(private readonly ?Closure $links = null, private readonly ?Closure $images = null) {}

    /** @return array{type: 'doc', content: list<array<string, mixed>>} */
    public function convert(string $markdown): array
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $document = (new MarkdownParser($environment))->parse($markdown);

        return ['type' => 'doc', 'content' => $this->blocks($document)];
    }

    /** @return list<array<string, mixed>> */
    private function blocks(Node $parent): array
    {
        $out = [];
        foreach ($parent->children() as $node) {
            $block = $this->block($node);
            if ($block !== null) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function block(Node $node): ?array
    {
        return match (true) {
            $node instanceof Heading => ['type' => 'heading', 'attrs' => ['level' => max(2, min(6, $node->getLevel()))], 'content' => $this->inlines($node)],
            $node instanceof Paragraph => $this->figure($node) ?? ['type' => 'paragraph', 'content' => $this->inlines($node)],
            $node instanceof ListBlock => $node->getListData()->type === ListBlock::TYPE_ORDERED
                ? ['type' => 'orderedList', 'attrs' => ['start' => $node->getListData()->start ?? 1], 'content' => $this->blocks($node)]
                : ['type' => 'bulletList', 'content' => $this->blocks($node)],
            $node instanceof ListItem => ['type' => 'listItem', 'content' => $this->blocks($node) ?: [['type' => 'paragraph', 'content' => []]]],
            $node instanceof FencedCode => self::code(rtrim($node->getLiteral(), "\n"), $node->getInfoWords()[0] ?? null),
            $node instanceof IndentedCode => self::code(rtrim($node->getLiteral(), "\n"), null),
            $node instanceof BlockQuote => ['type' => 'blockquote', 'content' => $this->blocks($node)],
            $node instanceof ThematicBreak => ['type' => 'horizontalRule'],
            $node instanceof Table => ['type' => 'table', 'content' => $this->tableRows($node)],
            default => null,
        };
    }

    /**
     * TB 29: Ein Absatz, der nur aus einem Bild besteht, wird ein Bild des Editors — der Titel (`![Alt](pfad "Titel")`)
     * die Bildunterschrift. Ohne Zuordnung bleibt es beim Alt-Text im Absatz.
     *
     * @return array<string, mixed>|null
     */
    private function figure(Paragraph $paragraph): ?array
    {
        $children = array_values(array_filter(iterator_to_array($paragraph->children(), false), static fn (Node $n): bool => ! ($n instanceof Text && trim($n->getLiteral()) === '') && ! $n instanceof Newline));
        $image = $children[0] ?? null;
        if (count($children) !== 1 || ! $image instanceof Image || $this->images === null) {
            return null;
        }
        $src = ($this->images)($image->getUrl());
        if ($src === null) {
            return null;
        }
        $alt = '';
        foreach ($image->children() as $child) {
            if ($child instanceof Text) {
                $alt .= $child->getLiteral();
            }
        }

        return ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => $alt, 'caption' => (string) ($image->getTitle() ?? '')]];
    }

    /** @return array<string, mixed> */
    private static function code(string $literal, ?string $language): array
    {
        return ['type' => 'codeBlock', 'attrs' => ['language' => $language !== null && $language !== '' ? $language : null], 'content' => $literal === '' ? [] : [['type' => 'text', 'text' => $literal]]];
    }

    /** @return list<array<string, mixed>> */
    private function tableRows(Table $table): array
    {
        $rows = [];
        foreach ($table->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }
            foreach ($section->children() as $row) {
                if (! $row instanceof TableRow) {
                    continue;
                }
                $cells = [];
                foreach ($row->children() as $cell) {
                    if ($cell instanceof TableCell) {
                        $cells[] = ['type' => $cell->getType() === TableCell::TYPE_HEADER ? 'tableHeader' : 'tableCell', 'content' => [['type' => 'paragraph', 'content' => $this->inlines($cell)]]];
                    }
                }
                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private function inlines(Node $parent, array $marks = []): array
    {
        $out = [];
        foreach ($parent->children() as $node) {
            $text = static fn (string $value, array $with): array => $with === [] ? ['type' => 'text', 'text' => $value] : ['type' => 'text', 'text' => $value, 'marks' => $with];
            if ($node instanceof Text) {
                if ($node->getLiteral() !== '') {
                    $out[] = $text($node->getLiteral(), $marks);
                }
            } elseif ($node instanceof Code) {
                $out[] = $text($node->getLiteral(), [...$marks, ['type' => 'code']]);
            } elseif ($node instanceof Newline) {
                $out[] = $node->getType() === Newline::HARDBREAK ? ['type' => 'hardBreak'] : $text(' ', $marks);
            } elseif ($node instanceof Strong) {
                array_push($out, ...$this->inlines($node, [...$marks, ['type' => 'bold']]));
            } elseif ($node instanceof Emphasis) {
                array_push($out, ...$this->inlines($node, [...$marks, ['type' => 'italic']]));
            } elseif ($node instanceof Link) {
                $href = $this->links !== null ? ($this->links)($node->getUrl()) : $node->getUrl();
                array_push($out, ...$this->inlines($node, $href === null ? $marks : [...$marks, ['type' => 'link', 'attrs' => ['href' => $href]]]));
            } elseif ($node instanceof Image) {
                array_push($out, ...$this->inlines($node, $marks));
            }
        }

        return self::merge($out);
    }

    /**
     * Benachbarte Texte mit denselben Marken zusammenfassen (wie der Editor speichert).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function merge(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $last = $out === [] ? null : $out[count($out) - 1];
            if ($last !== null && $last['type'] === 'text' && $node['type'] === 'text' && ($last['marks'] ?? []) === ($node['marks'] ?? [])) {
                $out[count($out) - 1]['text'] = $last['text'].$node['text'];

                continue;
            }
            $out[] = $node;
        }

        return $out;
    }
}
