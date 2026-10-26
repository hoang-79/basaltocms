<?php

declare(strict_types=1);

namespace Basalto\Lava\Theme;

use Basalto\Lava\Markup\Parser;
use Basalto\Lava\Runtime\Context;
use Basalto\Lava\Runtime\LavaRuntimeException;
use Basalto\Lava\Runtime\Limits;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Runtime\Renderer;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Value;
use Closure;

/**
 * Rendert eine Seite aus «seed» durch Template, Layout und Regionen: «region main: content» rendert die Blöcke
 * der Seite, «nav main» ein Menü, «global footer» einen globalen Inhalt; Layout-Markup setzt die Regionen ein.
 */
final class PageRenderer
{
    private readonly Renderer $renderer;

    private readonly Registry $registry;

    /**
     * Liefert die Einträge einer Navigation aus der Anwendung (Seitenbaum, Typ-Liste, Station D) — ohne ihn kommen
     * sie aus «seed». Rückgabe null heisst: nicht zuständig, «seed» nehmen.
     *
     * @var (Closure(string, Definition, string): (list<array<string, mixed>>|null))|null
     */
    private ?Closure $navigation = null;

    /**
     * Ausgabe eines Formulars durch die Anwendung (Versand, Fehler, Danke, Spam-Schutz); ohne sie das blosse Gerüst.
     *
     * @var (Closure(Definition, string): string)|null
     */
    private ?Closure $forms = null;

    /**
     * Werte eines globalen Inhalts aus der Anwendung (Fuss, Kontaktdaten …); null heisst: aus «seed».
     *
     * @var (Closure(string, string): (array<string, mixed>|null))|null
     */
    private ?Closure $globals = null;

    /** @var array<string, mixed> weitere Werte unter `site` aus der Anwendung (z. B. `logo`) */
    private array $siteVars = [];

    /**
     * Werte, die die Anwendung unter `site` bereitstellt — etwa `site.logo` (Adresse des Logos aus Theme oder Mediathek).
     *
     * @param  array<string, mixed>  $vars
     */
    public function withSite(array $vars): self
    {
        $this->siteVars = $vars;

        return $this;
    }

    public function __construct(private readonly Theme $theme, ?Registry $registry = null, private readonly Limits $limits = new Limits)
    {
        $this->registry = $registry ?? Registry::standard();
        $this->registry->tag('form', fn (array $args, array $named, Context $ctx): string => $this->renderForm((string) ($args[0] ?? ''), (string) ($ctx->get('site')['language'] ?? 'de')));
        // «{% part "main_nav" %}» im Markup — derselbe Teil wie «region header: part main_nav»
        $this->registry->tag('part', fn (array $args, array $named, Context $ctx): string => $this->renderPart((string) ($args[0] ?? ''), (string) ($ctx->get('site')['language'] ?? 'de'), ['page' => $ctx->get('page'), 'site' => $ctx->get('site')]));
        // «{% nav "main" %}» im Markup — dieselbe Navigation wie «region header: nav main»
        $this->registry->tag('nav', fn (array $args, array $named, Context $ctx): string => $this->renderNav((string) ($args[0] ?? ''), (string) ($ctx->get('site')['language'] ?? 'de')));
        $this->renderer = $this->withIncludesOf(new Renderer($this->registry), $this->registry);
    }

    /** «{% include "partials/…" %}» liest `<pfad>.lava.html` aus dem Theme — in Blöcken, Templates und (TB 11 Scheibe 4) im Layout. */
    private function withIncludesOf(Renderer $renderer, Registry $registry): Renderer
    {
        return $renderer->withIncludes(function (string $name, Context $ctx) use ($renderer, $registry): string {
            $part = $this->theme->part($name) ?? throw new LavaRuntimeException(sprintf('Eingebundener Teil «%s» fehlt', $name));

            return $renderer->nodes(Parser::parseCached($part, $name, $registry), $ctx);
        });
    }

    /**
     * Navigationen aus der Anwendung statt aus «seed» (M-07): der Resolver bekommt Name, Definition und Sprache und
     * liefert Einträge `{label, href, current, active, new_window, children}` — oder null.
     *
     * @param  Closure(string, Definition, string): (list<array<string, mixed>>|null)  $resolver
     */
    public function withNavigation(Closure $resolver): self
    {
        $this->navigation = $resolver;

        return $this;
    }

    /** @param  Closure(string, string): (array<string, mixed>|null)  $resolver  Name und Sprache → Feldwerte */
    public function withGlobals(Closure $resolver): self
    {
        $this->globals = $resolver;

        return $this;
    }

    /** @param  Closure(Definition, string): string  $renderer  Formular und Sprache → HTML */
    public function withForms(Closure $renderer): self
    {
        $this->forms = $renderer;

        return $this;
    }

    public function renderPage(string $pageName, string $lang = 'de'): string
    {
        $page = $this->theme->seed()?->child('page', $pageName) ?? throw new LavaRuntimeException(sprintf('Seite «%s» fehlt in «seed»', $pageName));
        $type = $this->theme->document->find('type', 'page');
        $templateName = $page->value('template') ?? $type?->value('template') ?? 'default';
        $pageVars = $this->pageVariables($page, $lang);
        $blockHtml = [];
        $content = $page->childrenOf('content')[0] ?? null;
        foreach ($content === null ? [] : $content->children as $use) {
            $blockHtml[] = $this->renderBlocks([$use], $pageVars, $lang);
        }

        return $this->compose($this->templateOrFail($templateName), $pageVars, $blockHtml, $lang);
    }

    /**
     * Rendert einen Inhalt aus Daten statt aus «seed» — die Fassung aus dem Backend: Seitenfelder und eine Blockliste
     * «[{type, data}]», deren Werte schon aufbereitet sind (Fliesstext als HTML, Bilder als {src, alt}, Links als {href, label}).
     *
     * Feldmarken (Frontend-Editor, M-19): `$pageMarks` für Template und Layout («page.title» → Pfad, Art), je Block
     * `marks` für seine Felder — nur für die angemeldete Redaktion, für Gäste leer.
     *
     * @param  array<string, mixed>  $page
     * @param  list<array{type: string, data: array<string, mixed>, marks?: array<string, array{path: string, kind: string}>}>  $blocks
     * @param  array<string, array{path: string, kind: string}>  $pageMarks
     */
    public function renderDocument(string $templateName, array $page, array $blocks, string $lang = 'de', array $pageMarks = []): string
    {
        $site = $this->theme->site();
        // Der Name der Site aus der Anwendung (withSite) gewinnt über den Vorgabewert im Site-Block des Themes
        $vars = ['site' => array_replace(['name' => $site?->value('name'), 'language' => $lang, 'domain' => $site?->value('domain')], $this->siteVars), 'page' => $page];
        $blockHtml = [];
        foreach ($blocks as $i => $block) {
            $blockHtml[] = $this->renderBlockData($block['type'], $block['data'], $vars, $i, $pageMarks + ($block['marks'] ?? []));
        }

        return $this->compose($this->templateOrFail($templateName), $vars, $blockHtml, $lang, $pageMarks);
    }

    /**
     * Ein Block aus Daten: Markup aus dem Theme mit den Feldwerten als Variablen.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $pageVars
     * @param  array<string, array{path: string, kind: string}>  $marks  Feldmarken des Blocks (Frontend-Editor), sonst leer
     */
    public function renderBlockData(string $type, array $data, array $pageVars, int $index = 0, array $marks = []): string
    {
        // CR-006 (FO-K1): fertiges Markup eines Moduls (Einhängeseite) — nur der Kern erzeugt solche Blöcke, nie die Datenbank
        if ($type === self::RAW_BLOCK) {
            return is_string($data['html'] ?? null) ? $data['html'] : '';
        }
        if ($type === self::PART_BLOCK) {
            $name = is_string($data['part'] ?? null) ? $data['part'] : '';
            $lang = is_array($pageVars['site'] ?? null) && is_string($pageVars['site']['language'] ?? null) ? $pageVars['site']['language'] : 'de';

            return $name !== '' && $this->theme->document->find('part', $name) !== null ? $this->renderPart($name, $lang, $pageVars) : '';
        }
        $block = $this->theme->block($type) ?? throw new LavaRuntimeException(sprintf('Block «%s» fehlt', $type));
        $markup = $this->theme->markupOf($block, 'blocks');
        if ($markup === null) {
            return sprintf('<section class="block block-%s">%s</section>', htmlspecialchars($type, ENT_QUOTES), htmlspecialchars((string) ($data['title'] ?? $data['text'] ?? ''), ENT_QUOTES));
        }

        return $this->markup($markup, $pageVars + $data + ['block' => ['name' => $block->name, 'index' => $index]], sprintf('block %s', $block->name), $marks);
    }

    private function templateOrFail(string $name): Definition
    {
        return $this->theme->template($name) ?? throw new LavaRuntimeException(sprintf('Template «%s» fehlt', $name));
    }

    /**
     * Template → Layout → Regionen: Blöcke (ganz, erster, Rest), Navigationen und globale Inhalte in die Regionen,
     * «before/after»-Regionen ins Eltern-Markup, dann das Layout-Markup.
     *
     * @param  array<string, mixed>  $pageVars
     * @param  list<string>  $blockHtml
     * @param  array<string, array{path: string, kind: string}>  $pageMarks
     */
    private function compose(Definition $template, array $pageVars, array $blockHtml, string $lang, array $pageMarks = []): string
    {
        $layout = $this->theme->layout((string) $template->value('layout')) ?? throw new LavaRuntimeException(sprintf('Layout «%s» fehlt', $template->value('layout')));
        $regions = [];
        foreach ($this->regionsOf($layout) as $regionName) {
            // Das Template füllt die Region; sonst gilt die Vorgabe des Layouts («region header: part main_nav»)
            $binding = $template->child('region', $regionName) ?? $this->layoutRegion($layout, $regionName);
            $regions[$regionName] = $binding === null ? '' : $this->renderBinding($binding, $blockHtml, $lang, $pageVars);
        }
        if (($main = $template->markup('main')) !== null) {
            // Das Markup des Templates bekommt die gerenderten Blöcke als «content» ({{ raw content }}), etwa unter einem Beitragskopf
            $regions['main'] = $this->markup($main, $pageVars + ['content' => implode('', $blockHtml)], sprintf('template %s', $template->name), $pageMarks);
        }
        foreach ($this->placedRegions($layout) as [$name, $where, $target]) {
            if (! isset($regions[$target])) {
                continue;
            }
            $regions[$target] = $where === 'before' ? $regions[$name].$regions[$target] : $regions[$target].$regions[$name];
            $regions[$name] = '';
        }
        $vars = $pageVars + ['regions' => array_map(static fn (string $html): bool => $html !== '', $regions)];
        $registry = clone $this->registry;
        $registry->tag('region', static fn (array $args) => $regions[(string) ($args[0] ?? '')] ?? '');
        $layoutMarkup = $this->layoutMarkup($layout) ?? throw new LavaRuntimeException(sprintf('Layout «%s» ohne Markup', $layout->name));

        return $this->withIncludesOf(new Renderer($registry), $registry)->render($layoutMarkup, $vars, sprintf('layout %s', $layout->name), $this->limits, $pageMarks);
    }

    /** @return array<string, mixed> */
    private function pageVariables(Definition $page, string $lang): array
    {
        $site = $this->theme->site();
        $fields = [];
        foreach ($page->properties as $p) {
            if ($p->lang === null) {
                $fields[$p->key] = $p->first()?->value;
            }
        }
        foreach ($page->properties as $p) {
            if ($p->lang === $lang) {
                $fields[$p->key] = $p->first()?->value;
            }
        }

        return [
            'site' => array_replace(['name' => $site?->value('name'), 'language' => $lang, 'domain' => $site?->value('domain')], $this->siteVars),
            'page' => $fields + ['name' => $page->name],
        ];
    }

    /**
     * @param  list<string>  $blockHtml
     * @param  array<string, mixed>  $pageVars
     */
    private function renderBinding(Definition $binding, array $blockHtml, string $lang, array $pageVars = []): string
    {
        $out = '';
        foreach (self::fill($binding->typed) as $item) {
            // «part newsletter off»: platziert, aber ausgeschaltet — die Instanz bleibt, ausgegeben wird nichts (M-28)
            if ($item['off']) {
                continue;
            }
            $name = (string) $item['name'];
            $out .= match ($item['word']) {
                'content' => implode('', $blockHtml),
                'content.first' => implode('', array_slice($blockHtml, 0, 1)),
                'content.rest' => implode('', array_slice($blockHtml, 1)),
                'nav' => $this->renderNav($name, $lang),
                'global' => $this->renderGlobal($name, $lang),
                'part' => $this->renderPart($name, $lang, $pageVars),
                'breadcrumbs' => Registry::breadcrumbsMarkup(is_array($pageVars['page'] ?? null) ? $pageVars['page'] : []),
                'languages' => Registry::languagesMarkup(is_array($pageVars['page'] ?? null) ? $pageVars['page'] : []),
                default => '',
            };
        }

        return $out;
    }

    /**
     * Die Füllung einer Region als Einträge: Wort, Name (bei «part», «nav», «global») und ob er ausgeschaltet ist
     * («part newsletter off»). Eine Quelle für Renderer und Layout-Editor (M-28).
     *
     * @param  list<Value>  $values
     * @return list<array{word: string, name: string|null, off: bool}>
     */
    public static function fill(array $values): array
    {
        $items = [];
        for ($i = 0; $i < count($values); $i++) {
            $word = $values[$i]->text();
            $last = count($items) - 1;
            if ($word === 'off' && isset($items[$last])) {
                $items[$last] = ['word' => $items[$last]['word'], 'name' => $items[$last]['name'], 'off' => true];
            } elseif (in_array($word, ['part', 'nav', 'global'], true) && isset($values[$i + 1])) {
                $items[] = ['word' => $word, 'name' => $values[++$i]->text(), 'off' => false];
            } else {
                $items[] = ['word' => $word, 'name' => null, 'off' => false];
            }
        }

        return $items;
    }

    /**
     * @param  list<Definition>  $uses
     * @param  array<string, mixed>  $pageVars
     */
    public function renderBlocks(array $uses, array $pageVars, string $lang): string
    {
        $out = '';
        foreach ($uses as $use) {
            $block = $this->theme->block($use->kind) ?? throw new LavaRuntimeException(sprintf('Block «%s» fehlt', $use->kind), $use->line);
            $markup = $this->theme->markupOf($block, 'blocks');
            $data = [];
            foreach ($use->properties as $p) {
                if ($p->lang !== null && $p->lang !== $lang) {
                    continue;
                }
                $data[$p->key] = $this->propertyValue($p->values, $p->block, $lang);
            }
            foreach ($use->children as $child) {
                $data[$child->kind] = array_map(fn (Definition $row): array => $this->rowValues($row, $lang), $child->children);
            }
            if ($markup === null) {
                $out .= sprintf('<section class="block block-%s">%s</section>', htmlspecialchars($use->kind, ENT_QUOTES), htmlspecialchars((string) ($data['title'] ?? $data['text'] ?? ''), ENT_QUOTES));

                continue;
            }
            $out .= $this->markup($markup, $pageVars + $data + ['block' => ['name' => $block->name]], sprintf('block %s', $block->name));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rowValues(Definition $row, string $lang = 'de'): array
    {
        $values = [];
        foreach ($row->properties as $p) {
            if ($p->lang !== null && $p->lang !== $lang) {
                continue;
            }
            $values[$p->key] = $this->propertyValue($p->values, $p->block, $lang);
        }

        return $values;
    }

    /**
     * Ein Linkziel «-> kontakt» (Seite aus «seed», beschriftet mit ihrem Titel) oder «-> /pfad» / «-> https://…».
     *
     * @return array{href: string, label: string}
     */
    private function linkTo(Value $target, string $lang): array
    {
        if ($target->type === Value::REF) {
            $page = $this->theme->seed()?->child('page', $target->text());

            return ['href' => '/'.$this->slugOf($target->text()), 'label' => $page?->value('title', $lang) ?? $target->text()];
        }

        return ['href' => $target->text(), 'label' => $target->text()];
    }

    /** @param  list<Value>  $values */
    private function propertyValue(array $values, ?Definition $block, string $lang = 'de'): mixed
    {
        if ($values === []) {
            return $block === null ? null : $this->rowValues($block, $lang);
        }
        if ($values[0]->type === Value::ARROW && isset($values[1])) {
            return $this->linkTo($values[1], $lang);
        }
        if (count($values) === 1) {
            $v = $values[0];
            if ($v->type === Value::PATH && ! str_starts_with($v->text(), '/')) {
                return ['src' => '/'.$v->text(), 'alt' => ''];
            }

            return $v->value;
        }

        return array_map(static fn (Value $v): mixed => $v->value, $values);
    }

    private function slugOf(string $pageName): string
    {
        $page = $this->theme->seed()?->child('page', $pageName);
        $slug = $page?->value('slug') ?? $pageName;

        return trim($slug, '/');
    }

    /**
     * Eine Navigation (L-08): Einträge aus der Anwendung oder aus «seed», dann das Markup der Navigation (Zaun in
     * «nav …», Variable `nav` mit `name`, `label`, `items`) oder das Standard-Markup — verschachtelte Listen mit
     * `aria-current="page"` am aktuellen Eintrag und `active` auf dem Weg dorthin.
     */
    public function renderNav(string $name, string $lang): string
    {
        return $this->renderNavDefinition($name, $this->theme->nav($name) ?? throw new LavaRuntimeException(sprintf('Nav «%s» fehlt', $name)), $lang);
    }

    private function renderNavDefinition(string $name, Definition $nav, string $lang): string
    {
        $items = $this->navigation !== null ? ($this->navigation)($name, $nav, $lang) : null;
        $items ??= $this->seedNavItems($nav, $lang);
        $label = $nav->value('label', $lang) ?? $nav->value('label');
        $markup = $nav->markup('main');
        if ($markup !== null) {
            // Menüs sehen dieselben Werte unter `site` wie die Seite (z. B. `site.cancellation` im Fuss)
            return $this->markup($markup, ['nav' => ['name' => $name, 'label' => $label, 'items' => $items], 'site' => array_replace($this->siteVars, ['language' => $lang])], sprintf('nav %s', $name));
        }

        return self::navMarkup($name, $label, $items);
    }

    /**
     * Das Standard-Markup einer Navigation.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function navMarkup(string $name, ?string $label, array $items): string
    {
        if ($items === []) {
            return '';
        }
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $aria = $label !== null && $label !== '' ? sprintf(' aria-label="%s"', $e($label)) : '';

        return sprintf('<nav class="nav nav-%s"%s>%s</nav>', $e($name), $aria, self::navList($items));
    }

    /** @param  list<array<string, mixed>>  $items */
    private static function navList(array $items): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $html = '<ul>';
        foreach ($items as $item) {
            $classes = array_filter([($item['current'] ?? false) ? 'current' : null, ($item['active'] ?? false) ? 'active' : null]);
            $class = $classes === [] ? '' : sprintf(' class="%s"', implode(' ', $classes));
            $current = ($item['current'] ?? false) ? ' aria-current="page"' : '';
            $target = ($item['new_window'] ?? false) ? ' target="_blank" rel="noopener"' : '';
            $children = is_array($item['children'] ?? null) && $item['children'] !== [] ? self::navList(array_values($item['children'])) : '';
            $html .= sprintf('<li%s><a href="%s"%s%s>%s</a>%s</li>', $class, $e($item['href'] ?? '#'), $current, $target, $e($item['label'] ?? ''), $children);
        }

        return $html.'</ul>';
    }

    /**
     * Einträge aus «seed» (Theme ohne Anwendung, Konsole, Spike): Seiten nach Template gefiltert, dann «item».
     *
     * @return list<array<string, mixed>>
     */
    private function seedNavItems(Definition $nav, string $lang): array
    {
        $items = [];
        if ($nav->value('from') === 'pages') {
            $exclude = $nav->property('exclude')?->refs() ?? [];
            $only = $nav->property('only')?->refs() ?? [];
            foreach ($this->theme->seed()?->childrenOf('page') ?? [] as $page) {
                $tpl = $page->value('template') ?? 'default';
                if (in_array($tpl, $exclude, true) || ($only !== [] && ! in_array($tpl, $only, true))) {
                    continue;
                }
                $items[] = ['label' => $page->value('title', $lang) ?? $page->name, 'href' => '/'.$this->slugOf((string) $page->name), 'current' => false, 'active' => false, 'children' => []];
            }
        }
        foreach ($nav->childrenOf('item') as $item) {
            $target = $item->arrowTarget();
            $items[] = ['label' => (string) $item->name, 'href' => $target === null ? '#' : $this->linkTo($target, $lang)['href'], 'current' => false, 'active' => false, 'children' => []];
        }

        return $items;
    }

    /**
     * Ein Lava-Teil (M-28): «part main_nav: nav { source: main  depth: 1 }» — Art aus der Registry, Einstellungen
     * aus der Instanz. Navigationen übernehmen `depth`, `start`, `only`, `exclude`, `label` der Instanz.
     *
     * @param  array<string, mixed>  $vars
     */
    public function renderPart(string $name, string $lang, array $vars = []): string
    {
        $part = $this->theme->document->find('part', $name) ?? throw new LavaRuntimeException(sprintf('Teil «%s» fehlt', $name));
        $kind = (string) $part->typedFirst();
        $page = is_array($vars['page'] ?? null) ? $vars['page'] : [];
        $source = (string) $part->value('source');

        return match ($kind) {
            'nav' => $this->renderNavDefinition($source, $this->navWithOverrides($source, $part), $lang),
            'breadcrumbs' => Registry::breadcrumbsMarkup($page),
            'languages' => Registry::languagesMarkup($page),
            'global' => $this->renderGlobal($source, $lang),
            'form' => $this->renderForm($source, $lang),
            // TB 10 Scheibe 3 (M-28): HTML-Sitemap, Suche, Liste und HTML als Teile — Suche und Liste über die Tags der
            // Anwendung, dieselbe Ausgabe wie «{% search %}» und «entries()» im Markup
            'sitemap' => $this->renderSitemap($source, $part, $lang),
            'search' => $this->markup('{% search %}', $vars, sprintf('part %s', $part->name)),
            'list' => $this->markup(self::LIST_MARKUP, $vars + ['list' => ['type' => $source, 'per' => (int) ($part->value('per') ?? 10), 'label' => $part->value('label', $lang)]], sprintf('part %s', $part->name)),
            // HTML ist Markup des Themes («part copyright: html { markup ```…``` }») — wer Teile bearbeitet, schreibt auch Lava-Markup
            'html' => $this->markup((string) $part->markup('main'), $vars, sprintf('part %s', $part->name)),
            default => $this->renderModulePart($kind, $part, $vars, $lang),
        };
    }

    /** Ein Lava-Teil als Block einer Seite (`{type: "@part", data: {part: name}}`, M-28) — fehlt er im Theme, bleibt die Stelle leer. */
    public const PART_BLOCK = '@part';

    /** Fertiges Markup als Block (`{type: "@raw", data: {html: …}}`) — für Module unter einer Einhängeseite, CR-006. */
    public const RAW_BLOCK = '@raw';

    /** Die Liste eines Typs (Teil «list»): Titel mit Link, neueste zuerst — wie «entries()» im Markup. */
    private const LIST_MARKUP = '<section class="list list-{{ list.type }}">{% if list.label %}<h2>{{ list.label }}</h2>{% end %}<ul>{% each entries(list.type, list.per) as item %}<li><a href="{{ item.url }}">{{ item.title }}</a></li>{% end %}</ul></section>';

    /** Die HTML-Sitemap (Teil «sitemap»): die Navigation der Quelle mit allen Ebenen bis «depth», im Standard-Markup. */
    private function renderSitemap(string $source, Definition $part, string $lang): string
    {
        $nav = $this->navWithOverrides($source, $part);
        $items = $this->navigation !== null ? ($this->navigation)($source, $nav, $lang) : null;
        $items ??= $this->seedNavItems($nav, $lang);

        return sprintf('<div class="sitemap">%s</div>', self::navMarkup('sitemap', $part->value('label', $lang), $items));
    }

    /** @param  array<string, mixed>  $vars */
    private function renderModulePart(string $kind, Definition $part, array $vars, string $lang): string
    {
        $spec = $this->registry->parts()[$kind] ?? throw new LavaRuntimeException(sprintf('Teil «%s»: unbekannte Art «%s»', $part->name, $kind));
        if ($spec['render'] === null) {
            return '';
        }
        $settings = [];
        foreach ($part->properties as $p) {
            $settings[$p->key] = $p->text();
        }

        return (string) ($spec['render'])($settings, $vars, $lang);
    }

    /** Die Navigation mit den Einstellungen der Teil-Instanz (Kopie — das Theme bleibt unverändert). */
    private function navWithOverrides(string $name, Definition $part): Definition
    {
        $nav = $this->theme->nav($name) ?? throw new LavaRuntimeException(sprintf('Nav «%s» fehlt', $name));
        $copy = clone $nav;
        foreach ($part->properties as $p) {
            if (! in_array($p->key, ['depth', 'start', 'only', 'exclude', 'label'], true)) {
                continue;
            }
            $copy->properties = array_values(array_filter($copy->properties, static fn ($q): bool => ! ($q->key === $p->key && $q->lang === $p->lang)));
            $copy->properties[] = $p;
        }

        return $copy;
    }

    /** Die Vorgabe-Füllung einer Region aus dem Layout oder seinen Eltern. */
    private function layoutRegion(Definition $layout, string $region): ?Definition
    {
        $own = $layout->child('region', $region);
        if ($own !== null && $own->typed !== []) {
            return $own;
        }
        $parent = $layout->modifierAfter('extends');

        return $parent !== null && ($p = $this->theme->layout($parent)) !== null ? $this->layoutRegion($p, $region) : null;
    }

    private function renderGlobal(string $name, string $lang): string
    {
        $global = $this->theme->global($name) ?? throw new LavaRuntimeException(sprintf('Global «%s» fehlt', $name));
        $seedValues = $this->theme->seed()?->child('global', $name);
        $stored = $this->globals !== null ? ($this->globals)($name, $lang) : null;
        $html = sprintf('<div class="global global-%s">', htmlspecialchars($name, ENT_QUOTES));
        foreach ($global->childrenOf('field') as $field) {
            $value = $stored !== null ? (is_scalar($stored[(string) $field->name] ?? null) ? (string) $stored[(string) $field->name] : null) : $seedValues?->value((string) $field->name, $lang);
            if ($value !== null) {
                $html .= sprintf('<p class="%s">%s</p>', htmlspecialchars((string) $field->name, ENT_QUOTES), htmlspecialchars($value, ENT_QUOTES));
            }
        }

        return $html.'</div>';
    }

    /** Ein Formular aus «form name { field … }» als HTML-Gerüst — im Produkt übernimmt das Formular-Modul (Versand, Spam, Danke). */
    private function renderForm(string $name, string $lang = 'de'): string
    {
        $form = $this->theme->document->find('form', $name) ?? throw new LavaRuntimeException(sprintf('Formular «%s» fehlt', $name));
        if ($this->forms !== null) {
            return ($this->forms)($form, $lang);
        }
        $safe = htmlspecialchars($name, ENT_QUOTES);
        $html = sprintf('<form class="form form-%s" method="post" action="/forms/%s">', $safe, $safe);
        foreach ($form->childrenOf('field') as $field) {
            $fieldName = htmlspecialchars((string) $field->name, ENT_QUOTES);
            $label = htmlspecialchars($field->value('label') ?? ucfirst((string) $field->name), ENT_QUOTES);
            $required = $field->hasTypedFlag('required') ? ' required' : '';
            $html .= match ($field->typedFirst()) {
                'textarea' => sprintf('<label>%s<textarea name="%s"%s></textarea></label>', $label, $fieldName, $required),
                'checkbox', 'bool' => sprintf('<label><input type="checkbox" name="%s"%s> %s</label>', $fieldName, $required, $label),
                'email' => sprintf('<label>%s<input type="email" name="%s"%s></label>', $label, $fieldName, $required),
                default => sprintf('<label>%s<input type="text" name="%s"%s></label>', $label, $fieldName, $required),
            };
        }

        return $html.'<button type="submit">'.htmlspecialchars($form->value('label') ?? 'Senden', ENT_QUOTES).'</button></form>';
    }

    private function layoutMarkup(Definition $layout): ?string
    {
        $markup = $this->theme->markupOf($layout, 'layouts');
        if ($markup === null && ($parent = $layout->modifierAfter('extends')) !== null && ($p = $this->theme->layout($parent)) !== null) {
            return $this->layoutMarkup($p);
        }

        return $markup;
    }

    /**
     * Regionen mit «before x» / «after x» in der Layout-Kette.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function placedRegions(Definition $layout): array
    {
        $placed = [];
        if (($parent = $layout->modifierAfter('extends')) !== null && ($p = $this->theme->layout($parent)) !== null) {
            $placed = $this->placedRegions($p);
        }
        foreach ($layout->childrenOf('region') as $region) {
            foreach (['before', 'after'] as $where) {
                if (($target = $region->modifierAfter($where)) !== null) {
                    $placed[] = [(string) $region->name, $where, $target];
                }
            }
        }

        return $placed;
    }

    /** @return list<string> */
    private function regionsOf(Definition $layout): array
    {
        $names = array_map(static fn (Definition $r): string => (string) $r->name, $layout->childrenOf('region'));
        if (($parent = $layout->modifierAfter('extends')) !== null && ($p = $this->theme->layout($parent)) !== null) {
            $names = array_values(array_unique(array_merge($this->regionsOf($p), $names)));
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $vars
     * @param  array<string, array{path: string, kind: string}>  $marks
     */
    private function markup(string $markup, array $vars, string $file, array $marks = []): string
    {
        return $this->renderer->render($markup, $vars, $file, $this->limits, $marks);
    }
}
