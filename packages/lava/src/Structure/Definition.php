<?php

declare(strict_types=1);

namespace Basalto\Lava\Structure;

/**
 * Eine Definition «wort name { … }» oder ein Unterelement («field title: text required», «region main», «item "Blog" -> /blog»).
 * Markup-Zäune liegen in $markups (Schlüssel «main» oder der Name hinter «markup»).
 */
final class Definition
{
    /** Sprache eines Texts ohne Suffix («label: …») */
    public const SOURCE_LANG = 'de';

    /** Rückfall für weitere Sprachen, bevor der Text ohne Suffix gilt */
    public const FALLBACK_LANG = 'en';

    /** @var list<Property> */
    public array $properties = [];

    /** @var list<Definition> */
    public array $children = [];

    /** @var array<string, string> */
    public array $markups = [];

    /** @var array<string, array{line: int, column: int}> Erste Inhaltszeile und -spalte je Markup-Zaun — für genaue Fehlerstellen */
    public array $markupPositions = [];

    /**
     * Reihenfolge der Elemente im Block, wie sie in der Quelle stehen: «p» Eigenschaft, «c» Unterelement, «m» Markup —
     * der Drucker schreibt sie in derselben Reihenfolge zurück (Roundtrip, ADR-005-Nachtrag).
     *
     * @var list<array{0: 'p'|'c'|'m', 1: int|string}>
     */
    public array $order = [];

    /**
     * @param  list<Value>  $typed  Werte hinter dem Doppelpunkt eines typisierten Unterelements («text required translatable»)
     * @param  list<Value>  $modifiers  Werte hinter dem Namen («extends standard», «-> /blog», «optional»)
     * @param  bool  $quotedName  Stand der Name in Anführungszeichen («item "Blog"»)? Der Drucker schreibt ihn dann wieder so.
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $name,
        public readonly array $typed,
        public readonly array $modifiers,
        public readonly string $file,
        public readonly int $line,
        public readonly int $column,
        public readonly bool $quotedName = false,
    ) {}

    /** Zeile der ersten Inhaltszeile eines Markup-Zauns (sonst die Zeile der Definition). */
    public function markupLine(string $name = 'main'): int
    {
        return $this->markupPositions[$name]['line'] ?? $this->line;
    }

    public function markupColumn(string $name = 'main'): int
    {
        return $this->markupPositions[$name]['column'] ?? $this->column;
    }

    public function property(string $key, ?string $lang = null): ?Property
    {
        foreach ($this->properties as $p) {
            if ($p->key === $key && $p->lang === $lang) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Wert in der gewünschten Sprache. Ohne Suffix gilt ein Text als Deutsch (Quellsprache wie bei den
     * Oberflächentexten). Fehlt die Sprache, nimmt Lava zuerst Englisch — eine französische Redaktion liest
     * «Hero» eher als «Bühne» — und erst dann den Text ohne Suffix.
     */
    public function value(string $key, ?string $lang = null): ?string
    {
        $p = $this->property($key, $lang);
        if ($p === null && $lang !== null && ! in_array($lang, [self::SOURCE_LANG, self::FALLBACK_LANG], true)) {
            $p = $this->property($key, self::FALLBACK_LANG);
        }
        $p ??= $lang !== null ? $this->property($key) : null;

        return $p?->first()?->text();
    }

    /** @return list<Definition> */
    public function childrenOf(string $kind): array
    {
        return array_values(array_filter($this->children, static fn (Definition $d): bool => $d->kind === $kind));
    }

    public function child(string $kind, string $name): ?Definition
    {
        foreach ($this->children as $c) {
            if ($c->kind === $kind && $c->name === $name) {
                return $c;
            }
        }

        return null;
    }

    public function hasModifier(string $word): bool
    {
        foreach ($this->modifiers as $m) {
            if ($m->type === Value::REF && $m->value === $word) {
                return true;
            }
        }

        return false;
    }

    /** Der Wert nach einem Modifikator-Wort («extends standard» → «standard»). */
    public function modifierAfter(string $word): ?string
    {
        foreach ($this->modifiers as $i => $m) {
            if ($m->type === Value::REF && $m->value === $word && isset($this->modifiers[$i + 1])) {
                return $this->modifiers[$i + 1]->text();
            }
        }

        return null;
    }

    /** Das Ziel hinter einem Pfeil («item "Blog" -> /blog»). */
    public function arrowTarget(): ?Value
    {
        foreach ($this->modifiers as $i => $m) {
            if ($m->type === Value::ARROW && isset($this->modifiers[$i + 1])) {
                return $this->modifiers[$i + 1];
            }
        }

        return null;
    }

    public function typedFirst(): ?string
    {
        return ($this->typed[0] ?? null)?->text();
    }

    public function hasTypedFlag(string $flag): bool
    {
        foreach (array_slice($this->typed, 1) as $v) {
            if ($v->type === Value::REF && $v->value === $flag) {
                return true;
            }
        }

        return false;
    }

    public function markup(string $name = 'main'): ?string
    {
        return $this->markups[$name] ?? null;
    }
}
