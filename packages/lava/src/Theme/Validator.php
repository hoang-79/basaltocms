<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Diagnostics\LavaSyntaxException;
use Basalto\Lava\Diagnostics\Step;
use Basalto\Lava\Markup\Node;
use Basalto\Lava\Markup\Parser as MarkupParser;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Value;

/**
 * Prüft ein Theme: bekannte Schlüsselwörter und Eigenschaften (fremde warnen, brechen nicht — L-02), Verweise
 * (Layout, Template, Typ, Block, Nav, Global, Seiten), Feldtypen aus Kern und Modulen (L-12), Markup-Syntax,
 * Logik in Struktur-Teilen und zu tiefe Verschachtelung (R-99) — jeder Befund mit Datei, Zeile, Spalte, Schritt und Vorschlag.
 */
final class Validator
{
    /** Mehr als so viele Ebenen each/if im Markup gelten als Programm, nicht als Theme (R-99). */
    public const MAX_NESTING = 6;

    /**
     * Erlaubte Eigenschaften und Unterelemente je Schlüsselwort (Konzept § 4/§ 5). Module ergänzen Eigenschaften
     * über die Registrierung; «seed» nimmt zusätzlich jeden Typnamen als Unterelement.
     *
     * @var array<string, array{properties: list<string>, children: list<string>}>
     */
    public const SHAPE = [
        'site' => ['properties' => ['name', 'languages', 'default', 'domain', 'redirects', 'timezone'], 'children' => []],
        'design' => ['properties' => ['space', 'radius', 'images', 'css', 'logo'], 'children' => ['color', 'font', 'images', 'palette']],
        'layout' => ['properties' => [], 'children' => ['region']],
        'nav' => ['properties' => ['from', 'items', 'depth', 'start', 'exclude', 'only', 'label'], 'children' => ['item']],
        'type' => ['properties' => ['label', 'route', 'template'], 'children' => ['field']],
        'global' => ['properties' => ['label'], 'children' => ['field']],
        'block' => ['properties' => ['label', 'group', 'hint'], 'children' => ['field']],
        'template' => ['properties' => ['for', 'layout', 'noindex'], 'children' => ['region']],
        'form' => ['properties' => ['label', 'to', 'subject', 'thanks', 'spam', 'submit', 'store', 'redirect', 'webhook', 'retain', 'consent'], 'children' => ['field']],
        'seed' => ['properties' => [], 'children' => ['page', 'global']],
        'publish' => ['properties' => ['seo', 'sitemap', 'robots', 'cookie', 'legal', 'redirects', 'check'], 'children' => ['seo', 'legal', 'redirects']],
    ];

    /** @var list<Diagnostic> */
    private array $found = [];

    private readonly Registry $registry;

    public function __construct(private readonly Theme $theme, ?Registry $registry = null)
    {
        $this->registry = $registry ?? Registry::standard();
    }

    /** @return list<Diagnostic> */
    public function run(): array
    {
        $doc = $this->theme->document;
        if ($doc->version === null) {
            $this->warn('Versionszeile «lava 1» fehlt am Anfang', $doc->file, 1, 1, null);
        }
        foreach ($doc->definitions as $def) {
            if (! in_array($def->kind, Step::keywords(), true)) {
                $suggestion = self::suggest($def->kind, Step::keywords());
                $this->error(sprintf('Unbekanntes Schlüsselwort «%s»', $def->kind), $def, $suggestion !== null ? Step::letterFor($suggestion) : null, $suggestion);

                continue;
            }
            $this->shape($def);
            $this->logicInStructure($def, Step::letterFor($def->kind));
        }
        foreach ($doc->all('layout') as $layout) {
            if (($parent = $layout->modifierAfter('extends')) !== null && $this->theme->layout($parent) === null) {
                $this->error(sprintf('Layout «%s» erweitert unbekanntes Layout «%s»', $layout->name, $parent), $layout, 'C', self::suggest($parent, $doc->names('layout')));
            }
            $this->markup($layout, 'layouts');
        }
        foreach ($doc->all('type') as $type) {
            $this->fields($type, 'E');
            if (($tpl = $type->value('template')) !== null && $this->theme->template($tpl) === null) {
                $this->error(sprintf('Typ «%s» verweist auf unbekanntes Template «%s»', $type->name, $tpl), $type, 'E', self::suggest($tpl, $doc->names('template')));
            }
        }
        foreach ($doc->all('global') as $global) {
            $this->fields($global, 'E');
        }
        foreach ($doc->all('design') as $design) {
            $this->design($design);
        }
        foreach ($doc->all('block') as $block) {
            $this->fields($block, 'F');
            $this->markup($block, 'blocks');
        }
        foreach ($doc->all('form') as $form) {
            $this->formFields($form);
        }
        foreach ($doc->all('nav') as $nav) {
            foreach ($nav->childrenOf('item') as $item) {
                $target = $item->arrowTarget();
                if ($target === null) {
                    $this->error(sprintf('Menüeintrag «%s» ohne Ziel («-> …»)', $item->name), $item, 'D');
                } elseif ($target->type === Value::REF && $this->seedPage($target->text()) === null) {
                    $this->error(sprintf('Menüeintrag «%s» zeigt auf unbekannte Seite «%s»', $item->name, $target->text()), $item, 'D', self::suggest($target->text(), $this->seedPageNames()));
                }
            }
            foreach (['only', 'exclude'] as $key) {
                $property = $nav->property($key);
                foreach ($property !== null ? $property->refs() : [] as $tpl) {
                    if ($this->theme->template($tpl) === null) {
                        $this->error(sprintf('Nav «%s»: «%s» nennt unbekanntes Template «%s»', $nav->name, $key, $tpl), $nav, 'D', self::suggest($tpl, $doc->names('template')));
                    }
                }
            }
            // Quelle: Seitenbaum («pages») oder Typ-Liste (ein Typname); freie Einträge aus dem Backend nur mit «items: db»
            $from = $nav->value('from');
            if ($from !== null && $from !== 'pages' && $doc->find('type', $from) === null) {
                $this->error(sprintf('Nav «%s»: «from: %s» ist weder «pages» noch ein Typ', $nav->name, $from), $nav, 'D', self::suggest($from, array_merge(['pages'], $doc->names('type'))));
            }
            $items = $nav->value('items');
            if ($items !== null && $items !== 'db') {
                $this->error(sprintf('Nav «%s»: «items: %s» — erlaubt ist «items: db» (Einträge aus Station D)', $nav->name, $items), $nav, 'D', 'db');
            }
            $this->markup($nav, 'navs');
        }
        // Lava-Teile (Station C, M-28): Art aus der Registry, Einstellungen der Art, Quelle vorhanden
        foreach ($doc->all('part') as $part) {
            $kind = $part->typedFirst();
            $kinds = $this->registry->parts();
            if ($kind === null || ! isset($kinds[$kind])) {
                $this->error(sprintf('Teil «%s»: unbekannte Art «%s»', $part->name, $kind ?? '—'), $part, 'C', $kind === null ? null : self::suggest($kind, array_keys($kinds)));

                continue;
            }
            foreach ($part->properties as $p) {
                if (! in_array($p->key, $kinds[$kind]['settings'], true)) {
                    $this->warn(sprintf('Teil «%s» (%s) kennt die Einstellung «%s» nicht', $part->name, $kind, $p->key), $part->file, $p->line, $p->column, 'C', self::suggest($p->key, $kinds[$kind]['settings']));
                }
            }
            $source = $part->value('source');
            // Die Quelle ist eine Definition: Navigation (auch für die Sitemap), globaler Inhalt, Formular, Typ (Liste)
            $sourceKind = ['nav' => 'nav', 'global' => 'global', 'form' => 'form', 'sitemap' => 'nav', 'list' => 'type'][$kind] ?? null;
            if ($sourceKind !== null) {
                if ($source === null) {
                    $this->error(sprintf('Teil «%s» (%s) braucht «source: …»', $part->name, $kind), $part, 'C');
                } elseif ($doc->find($sourceKind, $source) === null) {
                    $this->error(sprintf('Teil «%s»: %s «%s» gibt es nicht', $part->name, $sourceKind, $source), $part, 'C', self::suggest($source, $doc->names($sourceKind)));
                }
            }
            $this->markup($part, 'parts');
        }
        // Vorgabe-Füllung der Regionen im Layout («region header: part main_nav»)
        foreach ($doc->all('layout') as $layout) {
            foreach ($layout->childrenOf('region') as $region) {
                $values = $region->typed;
                for ($i = 0; $i < count($values); $i++) {
                    $word = $values[$i]->text();
                    if (in_array($word, ['nav', 'global', 'part'], true) && isset($values[$i + 1])) {
                        $name = $values[++$i]->text();
                        if ($doc->find($word, $name) === null) {
                            $this->error(sprintf('Layout «%s», Region «%s»: %s «%s» gibt es nicht', $layout->name, $region->name, $word, $name), $region, 'C', self::suggest($name, $doc->names($word)));
                        }
                    }
                }
            }
        }
        foreach ($doc->all('template') as $tpl) {
            $for = $tpl->value('for');
            if ($for === null) {
                $this->error(sprintf('Template «%s» ohne «for: typ»', $tpl->name), $tpl, 'G');
            } elseif ($this->theme->document->find('type', explode('.', $for)[0]) === null) {
                $this->error(sprintf('Template «%s» ist für unbekannten Typ «%s»', $tpl->name, $for), $tpl, 'G', self::suggest(explode('.', $for)[0], $doc->names('type')));
            }
            $layoutName = $tpl->value('layout');
            $layout = $layoutName !== null ? $this->theme->layout($layoutName) : null;
            if ($layoutName === null) {
                $this->error(sprintf('Template «%s» ohne «layout: …»', $tpl->name), $tpl, 'G');
            } elseif ($layout === null) {
                $this->error(sprintf('Template «%s» verweist auf unbekanntes Layout «%s»', $tpl->name, $layoutName), $tpl, 'G', self::suggest($layoutName, $doc->names('layout')));
            }
            foreach ($tpl->childrenOf('region') as $binding) {
                if ($layout !== null && ! $this->layoutHasRegion($layout, (string) $binding->name)) {
                    $this->error(sprintf('Template «%s» füllt Region «%s», die Layout «%s» nicht hat', $tpl->name, $binding->name, $layoutName), $binding, 'G', self::suggest((string) $binding->name, $this->regionNames($layout)));
                }
                $values = $binding->typed;
                for ($i = 0; $i < count($values); $i++) {
                    $word = $values[$i]->text();
                    if (in_array($word, ['nav', 'global', 'part'], true) && isset($values[$i + 1])) {
                        $name = $values[$i + 1]->text();
                        if ($this->theme->document->find($word, $name) === null) {
                            $this->error(sprintf('Template «%s»: %s «%s» gibt es nicht', $tpl->name, $word, $name), $binding, 'G', self::suggest($name, $doc->names($word)));
                        }
                        $i++;
                    }
                }
            }
            $this->markup($tpl, 'templates');
        }
        $seed = $doc->find('seed');
        if ($seed !== null) {
            foreach ($seed->childrenOf('page') as $page) {
                if (($tpl = $page->value('template')) !== null && $this->theme->template($tpl) === null) {
                    $this->error(sprintf('Seite «%s» nennt unbekanntes Template «%s»', $page->name, $tpl), $page, 'I', self::suggest($tpl, $doc->names('template')));
                }
                $content = $this->firstChildOfKind($page, 'content');
                foreach ($content !== null ? $content->children : [] as $use) {
                    if ($this->theme->block($use->kind) === null) {
                        $this->error(sprintf('Seite «%s» verwendet unbekannten Block «%s»', $page->name, $use->kind), $use, 'I', self::suggest($use->kind, $doc->names('block')));
                    }
                }
            }
        }
        $publish = $doc->find('publish');
        if ($publish !== null) {
            $legalProperty = $publish->property('legal');
            $legal = $this->firstChildOfKind($publish, 'legal') ?? ($legalProperty !== null ? $legalProperty->block : null);
            foreach ($legal !== null ? $legal->properties : [] as $p) {
                $first = $p->first();
                $name = $first !== null ? $first->text() : null;
                if ($name !== null && $this->seedPage($name) === null) {
                    $this->error(sprintf('publish.legal verlangt Seite «%s», die in «seed» fehlt', $name), $publish, 'Z', self::suggest($name, $this->seedPageNames()));
                }
            }
        }

        return $this->found;
    }

    /** Fremde Eigenschaften und Unterelemente eines Schlüsselworts warnen mit Vorschlag — und brechen nicht (L-02). */
    private function shape(Definition $def): void
    {
        $shape = self::SHAPE[$def->kind] ?? null;
        if ($shape === null) {
            return;
        }
        $step = Step::letterFor($def->kind);
        $properties = array_merge($shape['properties'], $this->registry->properties($def->kind));
        $children = $shape['children'];
        if ($def->kind === 'seed') {
            $children = array_merge($children, $this->theme->document->names('type'));
        }
        $candidates = array_values(array_unique(array_merge($properties, $children)));
        foreach ($def->properties as $p) {
            if (! in_array($p->key, $properties, true)) {
                $where = $this->label($def);
                $this->warn(sprintf('Unbekannte Eigenschaft «%s» in «%s»', $p->key, $where), $def->file, $p->line, $p->column, $step, self::suggest($p->key, $candidates));
            }
        }
        foreach ($def->children as $child) {
            if (in_array($child->kind, ['row', 'redirect'], true)) {
                continue;
            }
            if (! in_array($child->kind, $children, true)) {
                $this->warn(sprintf('Unbekanntes Unterelement «%s» in «%s»', $child->kind, $this->label($def)), $child->file, $child->line, $child->column, $step, self::suggest($child->kind, $candidates));
            }
        }
    }

    /** Logik gehört ins Markup: ein Eigenschaftswert mit «{{» oder «{%» in einem Struktur-Teil ist ein Befund (R-99). */
    private function logicInStructure(Definition $def, ?string $step): void
    {
        foreach ($def->properties as $p) {
            foreach ($p->values as $v) {
                if ($v->type === Value::STRING && is_string($v->value) && preg_match('/\{\{|\{%/', $v->value)) {
                    $this->warn(sprintf('Logik in Struktur-Teil: «%s» in «%s» enthält «%s» — gehört ins Markup', $p->key, $this->label($def), str_contains($v->value, '{{') ? '{{ … }}' : '{% … %}'), $def->file, $v->line, $v->column, $step);
                    break;
                }
            }
            if ($p->block !== null) {
                $this->logicInStructure($p->block, $step);
            }
        }
        foreach ($def->children as $child) {
            $this->logicInStructure($child, $step);
        }
    }

    private function fields(Definition $def, string $step): void
    {
        $types = $this->registry->fieldTypes();
        foreach ($def->childrenOf('field') as $field) {
            $type = $field->typedFirst();
            if ($type === null) {
                $this->error(sprintf('Feld «%s» ohne Typ («field %s: text»)', $field->name, $field->name), $field, $step);
            } elseif (! in_array($type, $types, true)) {
                $this->error(sprintf('Feld «%s» hat unbekannten Typ «%s»', $field->name, $type), $field, $step, $this->suggestFieldType($type, $types));
            }
            if ($type === 'blocks') {
                $allow = $field->property('allow');
                foreach ($allow !== null ? $allow->refs() : [] as $block) {
                    if ($this->theme->block($block) === null) {
                        $this->error(sprintf('Feld «%s» erlaubt unbekannten Block «%s»', $field->name, $block), $field, $step, self::suggest($block, $this->theme->document->names('block')));
                    }
                }
            }
            if ($type === 'relation' && isset($field->typed[1]) && ! in_array($field->typed[1]->text(), ['form', 'page', 'global'], true) && $this->theme->document->find('type', $field->typed[1]->text()) === null) {
                $this->error(sprintf('Feld «%s» verweist auf unbekannten Typ «%s»', $field->name, $field->typed[1]->text()), $field, $step, self::suggest($field->typed[1]->text(), $this->theme->document->names('type')));
            }
        }
    }

    /** Felder eines Formulars (M-11): Typen des Baukastens, Auswahl und Radio mit Optionen, Gruppen mit eigenen Feldern. */
    private function formFields(Definition $def): void
    {
        foreach ($def->childrenOf('field') as $field) {
            $type = $field->typedFirst();
            if ($type === null) {
                $this->error(sprintf('Formularfeld «%s» ohne Typ («field %s: text»)', $field->name, $field->name), $field, 'H');

                continue;
            }
            if (! in_array($type, Registry::FORM_FIELD_TYPES, true)) {
                $this->error(sprintf('Formularfeld «%s» hat unbekannten Typ «%s»', $field->name, $type), $field, 'H', self::suggest($type, Registry::FORM_FIELD_TYPES));

                continue;
            }
            if (in_array($type, ['select', 'radio'], true) && ($field->property('options')->values ?? []) === []) {
                $this->error(sprintf('Formularfeld «%s» (%s) braucht «options: …»', $field->name, $type), $field, 'H');
            }
            if ($type === 'group') {
                $this->formFields($field);
            }
        }
    }

    /**
     * Vorschlag für einen Feldtyp: bei «shop.price» ohne registriertes Modul den Namen hinter dem Punkt gegen die
     * bekannten Typen halten («price» → «example.price»), sonst die übliche Nähe.
     *
     * @param  list<string>  $types
     */
    private function suggestFieldType(string $type, array $types): ?string
    {
        $direct = self::suggest($type, $types);
        if ($direct !== null) {
            return $direct;
        }
        $short = str_contains($type, '.') ? substr($type, (int) strrpos($type, '.') + 1) : $type;
        foreach ($types as $candidate) {
            $candidateShort = str_contains($candidate, '.') ? substr($candidate, (int) strrpos($candidate, '.') + 1) : $candidate;
            if ($candidateShort === $short) {
                return $candidate;
            }
        }

        return null;
    }

    private function markup(Definition $def, string $folder): void
    {
        $step = Step::letterFor($def->kind);
        foreach (array_keys($def->markups) as $name) {
            $label = sprintf('%s %s · markup %s', $def->kind, $def->name, $name);
            try {
                $nodes = MarkupParser::parse((string) $def->markup($name), $label, $this->registry);
            } catch (LavaSyntaxException $e) {
                $this->found[] = new Diagnostic(Diagnostic::ERROR, sprintf('Markup von %s «%s»: %s', $def->kind, $def->name, Compiler::bareMessage($e)), $def->file,
                    $def->markupLine($name) + $e->lavaLine - 1, $def->markupColumn($name) + $e->lavaColumn - 1, $step);

                continue;
            }
            $deep = self::deepestNesting($nodes, 0);
            if ($deep['depth'] > self::MAX_NESTING) {
                $this->warn(sprintf('Markup von %s «%s»: each/if %d Ebenen tief verschachtelt — höchstens %d, sonst wird das Theme zum Programm', $def->kind, $def->name, $deep['depth'], self::MAX_NESTING),
                    $def->file, $def->markupLine($name) + $deep['line'] - 1, $def->markupColumn($name), $step);
            }
        }
        $file = sprintf('%s/%s.lava.html', $folder, $def->name);
        if (FileSource::isInside($file) && $this->theme->source->has($file)) {
            try {
                $nodes = MarkupParser::parse($this->theme->source->read($file), $file, $this->registry);
            } catch (LavaSyntaxException $e) {
                $this->found[] = new Diagnostic(Diagnostic::ERROR, Compiler::bareMessage($e), $file, $e->lavaLine, $e->lavaColumn, $step);

                return;
            }
            $deep = self::deepestNesting($nodes, 0);
            if ($deep['depth'] > self::MAX_NESTING) {
                $this->warn(sprintf('Markup von %s «%s»: each/if %d Ebenen tief verschachtelt — höchstens %d, sonst wird das Theme zum Programm', $def->kind, $def->name, $deep['depth'], self::MAX_NESTING), $file, $deep['line'], 1, $step);
            }
        }
    }

    /**
     * Tiefste Verschachtelung von each/if im Baum und die Zeile, an der sie beginnt.
     *
     * @param  list<Node>  $nodes
     * @return array{depth: int, line: int}
     */
    public static function deepestNesting(array $nodes, int $depth): array
    {
        $best = ['depth' => $depth, 'line' => 1];
        foreach ($nodes as $node) {
            $children = [];
            $nested = $depth;
            if ($node->kind === 'each') {
                $nested = $depth + 1;
                $children = [$node->children, $node->data['else']];
            } elseif ($node->kind === 'if') {
                $nested = $depth + 1;
                $children = array_map(static fn (array $branch): array => $branch['body'], $node->data['branches']);
            } elseif ($node->kind === 'macro') {
                $children = [$node->children];
            }
            if ($nested > $best['depth']) {
                $best = ['depth' => $nested, 'line' => $node->line];
            }
            foreach ($children as $body) {
                $inner = self::deepestNesting($body, $nested);
                if ($inner['depth'] > $best['depth']) {
                    $best = $inner;
                }
            }
        }

        return $best;
    }

    private function layoutHasRegion(Definition $layout, string $region): bool
    {
        if ($layout->child('region', $region) !== null) {
            return true;
        }
        $parent = $layout->modifierAfter('extends');

        return $parent !== null && ($p = $this->theme->layout($parent)) !== null && $this->layoutHasRegion($p, $region);
    }

    /** @return list<string> */
    private function regionNames(Definition $layout): array
    {
        $names = array_map(static fn (Definition $r): string => (string) $r->name, $layout->childrenOf('region'));
        $parent = $layout->modifierAfter('extends');
        if ($parent !== null && ($p = $this->theme->layout($parent)) !== null) {
            $names = array_merge($names, $this->regionNames($p));
        }

        return $names;
    }

    private function firstChildOfKind(Definition $def, string $kind): ?Definition
    {
        return $def->childrenOf($kind)[0] ?? null;
    }

    private function seedPage(string $name): ?Definition
    {
        $seed = $this->theme->seed();

        return $seed !== null ? $seed->child('page', $name) : null;
    }

    /** @return list<string> */
    private function seedPageNames(): array
    {
        $seed = $this->theme->seed();

        return array_map(static fn (Definition $p): string => (string) $p->name, $seed !== null ? $seed->childrenOf('page') : []);
    }

    private function label(Definition $def): string
    {
        return $def->name !== null ? $def->kind.' '.$def->name : $def->kind;
    }

    /** @param  list<string>  $candidates */
    public static function suggest(string $word, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $c) {
            $d = levenshtein(mb_strtolower($word), mb_strtolower($c));
            if ($d < $bestDistance) {
                $best = $c;
                $bestDistance = $d;
            }
        }

        return $best !== null && $bestDistance <= max(2, (int) (strlen($word) / 3)) ? $best : null;
    }

    /**
     * Station B (L-10, N-02): Farbwerte, Stylesheets, Logo und Schriftdateien im Theme-Ordner, Kontrast der Farbpaare
     * nach WCAG 2.2 AA — ein zu schwacher Kontrast ist eine Warnung, kein Fehler (die Site bleibt baubar).
     */
    private function design(Definition $design): void
    {
        $this->colorLines($design);
        $this->palettes($design);
        $files = [];
        foreach ($design->property('css')->values ?? [] as $value) {
            $files[] = [$value->text(), ['css'], 'Stylesheet'];
        }
        if (($logo = $design->value('logo')) !== null) {
            $files[] = [$logo, ['svg', 'png', 'webp', 'jpg', 'jpeg'], 'Logo'];
        }
        foreach (Design::fromDefinition($design)->fonts as $name => $font) {
            if ($font['src'] !== null) {
                $files[] = [$font['src'], ['woff2', 'woff'], sprintf('Schrift «%s»', $name)];
            }
        }
        foreach ($files as [$path, $extensions, $label]) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($extension, $extensions, true)) {
                $this->error(sprintf('%s «%s»: erlaubt sind .%s', $label, $path, implode(', .', $extensions)), $design, 'B');
            } elseif ($this->theme->hasSource() && (! FileSource::isInside($path) || ! $this->theme->source->has($path))) {
                $this->error(sprintf('%s «%s» fehlt im Theme-Ordner', $label, $path), $design, 'B');
            }
        }
        foreach (Design::fromDefinition($design)->contrastIssues() as $issue) {
            $this->warn(sprintf('Kontrast «%s» auf «%s» ist %s:1 — WCAG 2.2 AA verlangt %s:1 für Text', $issue['foreground'], $issue['background'], number_format($issue['ratio'], 2, '.', ''), number_format($issue['required'], 1, '.', '')), $design->file, $design->line, $design->column, 'B');
        }
        foreach (Design::fromDefinition($design)->darkContrastIssues() as $issue) {
            $this->warn(sprintf('Kontrast «%s» auf «%s» in der dunklen Darstellung ist %s:1 — WCAG 2.2 AA verlangt %s:1 für Text', $issue['foreground'], $issue['background'], number_format($issue['ratio'], 2, '.', ''), number_format($issue['required'], 1, '.', '')), $design->file, $design->line, $design->column, 'B');
        }
    }

    /** Die Zeilen `color name: #hell [dark #dunkel]` in `design` oder einer Farbvariante. */
    private function colorLines(Definition $owner): void
    {
        foreach ($owner->childrenOf('color') as $color) {
            $value = $color->typed[0] ?? null;
            if ($value === null || ! Design::isColor($value->text())) {
                $this->error(sprintf('Farbe «%s»: «%s» ist kein Farbwert — erlaubt sind #rgb, #rrggbb und #rrggbbaa', $color->name, $value?->text() ?? '—'), $color, 'B');
            }
            // TB 21: «dark #…» — der Wert der dunklen Darstellung
            $words = array_map(static fn ($v): string => $v->text(), $color->typed);
            $at = array_search('dark', $words, true);
            if ($at !== false && ! Design::isColor($words[$at + 1] ?? '')) {
                $this->error(sprintf('Farbe «%s»: nach «dark» fehlt ein Farbwert (#rgb, #rrggbb, #rrggbbaa)', $color->name), $color, 'B');
            }
        }
    }

    /**
     * Farbvarianten (CR-015, TB 83): `palette name { label: …  color …: … }` — nur Farben, die `design` nennt, und je
     * Variante dieselbe Kontrastprüfung wie für das Theme, hell und dunkel.
     */
    private function palettes(Definition $design): void
    {
        $base = Design::fromDefinition($design);
        $seen = [];
        foreach ($design->childrenOf('palette') as $palette) {
            if ($palette->name === null) {
                $this->error('Farbvariante ohne Namen — so: «palette salbei { label: "Salbei"  color accent: #2f4a3a }»', $palette, 'B');

                continue;
            }
            if (isset($seen[$palette->name])) {
                $this->warn(sprintf('Farbvariante «%s» steht doppelt — es gilt die erste', $palette->name), $palette->file, $palette->line, $palette->column, 'B');

                continue;
            }
            $seen[$palette->name] = true;
            foreach ($palette->properties as $p) {
                if ($p->key !== 'label') {
                    $this->warn(sprintf('Unbekannte Eigenschaft «%s» in Farbvariante «%s»', $p->key, $palette->name), $palette->file, $p->line, $p->column, 'B', self::suggest($p->key, ['label', 'color']));
                }
            }
            foreach ($palette->children as $child) {
                if ($child->kind !== 'color') {
                    $this->warn(sprintf('Unbekanntes Unterelement «%s» in Farbvariante «%s» — eine Variante nennt nur Farben', $child->kind, $palette->name), $child->file, $child->line, $child->column, 'B', self::suggest($child->kind, ['color', 'label']));
                }
            }
            $this->colorLines($palette);
            $colors = $palette->childrenOf('color');
            if ($colors === []) {
                $this->warn(sprintf('Farbvariante «%s» nennt keine Farbe', $palette->name), $palette->file, $palette->line, $palette->column, 'B');
            }
            foreach ($colors as $color) {
                if ($color->name !== null && ! isset($base->colors[$color->name])) {
                    $this->warn(sprintf('Farbvariante «%s»: Farbe «%s» fehlt in design — eine Variante ändert nur Farben, die das Theme nennt', $palette->name, $color->name), $color->file, $color->line, $color->column, 'B', self::suggest($color->name, array_map('strval', array_keys($base->colors))));
                }
            }
        }
        foreach ($base->paletteContrastIssues() as $issue) {
            $at = $design->child('palette', $issue['palette']) ?? $design;
            $this->warn(sprintf('Farbvariante «%s»: Kontrast «%s» auf «%s»%s ist %s:1 — WCAG 2.2 AA verlangt %s:1 für Text', $issue['palette'], $issue['foreground'], $issue['background'], $issue['dark'] ? ' in der dunklen Darstellung' : '', number_format($issue['ratio'], 2, '.', ''), number_format($issue['required'], 1, '.', '')), $at->file, $at->line, $at->column, 'B');
        }
    }

    private function error(string $message, Definition $at, ?string $step, ?string $suggestion = null): void
    {
        $this->found[] = new Diagnostic(Diagnostic::ERROR, $message, $at->file, $at->line, $at->column, $step, $suggestion);
    }

    private function warn(string $message, string $file, int $line, int $column, ?string $step, ?string $suggestion = null): void
    {
        $this->found[] = new Diagnostic(Diagnostic::WARNING, $message, $file, $line, $column, $step, $suggestion);
    }
}
