<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Analytics\Statistics;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Forms\FormDefinition;
use Basalto\Core\Forms\FormRenderer;
use Basalto\Core\Help\LegalTexts;
use Basalto\Core\Legal\Cancellation\ContractCancellations;
use Basalto\Core\Media\ImageVariants;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Navigation\Navigation;
use Basalto\Core\Publish\LegalTemplates;
use Basalto\Core\Search\SiteSearch;
use Basalto\Core\Support\DevServer;
use Basalto\Core\Theme\ThemeAssets;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Runtime\Context;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Theme\PageRenderer;
use Basalto\Lava\Theme\Theme;

/**
 * Vorschau und Auslieferung einer Fassung: Felder aufbereiten (Fliesstext → HTML, Bild → URL, Link), Template aus den
 * Seiteneinstellungen (eigenes, vererbt oder das des Typs), `{% seo %}` aus Metadaten, Suffix des Themes, kanonischer
 * URL, Seitenbild und Alternativen je Sprache — dann über Lava rendern.
 */
final class EntryRenderer
{
    private ?PageRenderer $pageRenderer = null;

    private ?Site $site = null;

    /** @var array<string, mixed> */
    private array $pageVars = [];

    private string $locale = '';

    /** Das Schema der Seite in Arbeit — verschachtelte Blöcke brauchen es je Block, gebaut wird es einmal je Seite. */
    private ?ThemeSchema $schema = null;

    /**
     * Bearbeitbare Felder der Fassung in Arbeit (Frontend-Editor, M-19) — null, solange keine Feldmarken angefordert
     * sind: dann rendert die Seite genau wie für Gäste.
     *
     * @var array<string, array{kind: string, label: string}>|null
     */
    private ?array $editable = null;

    private bool $visitors = false;

    public function __construct(private readonly ThemeLoader $themes, private readonly Registry $registry, private readonly ImageVariants $variants, private readonly Navigation $navigation, private readonly FormRenderer $forms) {}

    /** TB 54: Die nächste Ausgabe ist für Besucher der Website — mit Cookie-Einstellungen (nicht in Vorschauen). */
    public function forVisitors(): self
    {
        $this->visitors = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $page  zusätzliche Seitenvariablen aus der Auslieferung (Pfad, URL, kanonisch, Alternativen …)
     * @param  bool  $markFields  Feldmarken für den Frontend-Editor (M-19) — nur für die angemeldete Redaktion mit Recht
     * @param  list<string>  $leadingHtml  fertiges Markup als erste Blöcke (CR-006: das Modul einer Einhängeseite)
     */
    public function html(EntryVersion $version, array $page = [], bool $markFields = false, array $leadingHtml = []): string
    {
        // Gilt genau für diese Ausgabe — auch wenn sie mit einer Ausnahme endet
        $visitor = $this->visitors;
        $this->visitors = false;
        $entry = $version->entry;
        $this->site = $entry->site;
        $this->locale = $version->locale;
        $theme = $this->themes->forSite($entry->site);
        $schema = $this->schema = ThemeSchema::fromTheme($theme);
        $this->editable = $markFields ? FrontendEditor::fieldsFrom($schema, $entry, $version) : null;
        $template = $this->templateFor($entry, $schema);
        $settings = is_array($version->data['settings'] ?? null) ? $version->data['settings'] : [];
        $meta = is_array($settings['meta'] ?? null) ? $settings['meta'] : [];
        $pageVars = [
            'name' => $version->slug, 'title' => $version->title, 'slug' => $version->slug, 'intro' => RichText::toHtml($version->data['intro'] ?? null),
            'type' => $entry->type, 'locale' => $version->locale,
            'meta' => ['title' => $meta['title'] ?? null, 'description' => is_string($meta['description'] ?? null) && trim($meta['description']) !== '' ? $meta['description'] : (RichText::toText($version->data['intro'] ?? null) ?: null), 'robots' => $entry->setting('meta.robots')],
            'css_class' => (string) $entry->setting('expert.css_class', ''),
            'shortcut' => $entry->setting('keyboard.shortcut'),
        ];
        foreach ($schema->types[$entry->type]['fields'] ?? [] as $field) {
            if ($field['type'] !== 'blocks' && ! array_key_exists($field['name'], $pageVars)) {
                $pageVars[$field['name']] = $this->prepare([$field], [$field['name'] => $version->data[$field['name']] ?? null])[$field['name']] ?? null;
            }
        }
        $pageVars = array_replace_recursive($pageVars, $page);
        // CR-006 (FO-K1): Einhängeseiten bringen ihre Brotkrumen mit (Seite + Ansicht des Moduls)
        $pageVars['breadcrumbs'] = is_array($page['breadcrumbs'] ?? null) ? $page['breadcrumbs'] : $this->navigation->breadcrumbs($entry, $version->locale);
        $registry = clone $this->registry;
        $registry->tag('seo', fn (array $args, array $named, Context $ctx): string => $this->seo($theme, $template, is_array($ctx->get('page')) ? $ctx->get('page') : [], (string) $entry->site->name));
        // «{% assets %}» (L-10): Zeichensatz und Viewport, Design-Tokens mit den Überschreibungen der Site, Stylesheets des Themes
        $themeAssets = app(ThemeAssets::class);
        $registry->tag('assets', static fn (): string => self::assets().$themeAssets->head($entry->site));
        // «{% appearance %}» (TB 21): Umschalter hell/dunkel, nur mit dunklen Farben im Theme
        $registry->tag('appearance', static fn (array $args, array $named, Context $ctx): string => $themeAssets->appearanceToggle($entry->site, (string) ($ctx->get('site')['language'] ?? $version->locale)));
        // «asset("demo/editor.svg")»: Adresse einer Datei unter assets/ des Themes, mit Prüfsumme (TB 16 Nachtrag)
        $assetSite = $entry->site;
        // TB 30: ein Bild aus der Mediathek (Feld mit «pick: image») kommt als volle Adresse und bleibt, wie es ist
        $registry->function('asset', static fn (mixed $path = null): string => ! is_string($path) || $path === '' ? '' : (preg_match('#^https?://#', $path) === 1 ? $path : (string) $themeAssets->url($assetSite, 'assets/'.ltrim($path, '/'))));
        // Listen (M-13): «entries("post", 10)», «{% pagination %}», «{% categories "post" %}», «{% archive "post" %}»
        $listings = new Listings($entry->site, $version->locale, request(), app(Pages::class), fn (string $type, array $data): array => $this->prepare($schema->types[$type]['fields'] ?? [], $data));
        $registry->function('entries', static fn (mixed $type = null, mixed $per = 10): array => $listings->entries((string) $type, is_numeric($per) ? (int) $per : 10));
        // TB 56: «legal("agb")» — ein Rechtstext aus docs/recht in der Sprache der Seite als HTML (Fallback Deutsch);
        // Verweise auf andere Rechtstexte zeigen auf deren öffentliche Fassung unter /_recht
        $legalLocale = $version->locale;
        $registry->function('legal', static function (mixed $slug = null) use ($legalLocale): string {
            $locale = LegalTexts::exists(LegalTexts::locale($legalLocale), (string) $slug) ? LegalTexts::locale($legalLocale) : 'de';

            return is_string($slug) && LegalTexts::exists($locale, $slug)
                ? LegalTexts::render($locale, $slug, static fn (string $text, string $lang): string => LegalTexts::publicUrl($text, $lang))['html']
                : '';
        });
        $registry->tag('pagination', static fn (): string => $listings->pagination());
        $registry->tag('categories', static fn (array $args): string => $listings->categories((string) ($args[0] ?? 'post')));
        $registry->tag('archive', static fn (array $args): string => $listings->archive((string) ($args[0] ?? 'post')));
        // «{% search %}» (M-12): Suchformular und Treffer dieser Site in der Sprache der Seite
        $registry->tag('search', static fn (array $args, array $named, Context $ctx): string => app(SiteSearch::class)->render($entry->site, (string) ($ctx->get('site')['language'] ?? $version->locale), request()));
        // «{% blocks inhalt %}» gibt die schon gerenderten verschachtelten Blöcke aus (M-04)
        $registry->tag('blocks', static fn (array $args): string => is_string($args[0] ?? null) ? $args[0] : '');
        $this->pageRenderer = new PageRenderer($theme, $registry);
        // TB 13 Scheibe 2: «site.modules.<modul>» — eingeschaltete Module der Site, damit ein Theme Tags eines Moduls nur
        // ausgibt, wenn es installiert und an ist («{% if site.modules.members %}{% members.status %}{% end %}»)
        // CR-004: der Name aus dem Backend (Installer, Einstellungen) statt des Vorgabewerts im Theme
        // CR-011 TB 59: «site.cancellation» — Adresse von «Verträge hier kündigen», leer, wo sich nichts kündigen lässt
        $this->pageRenderer->withSite(['name' => $entry->site->name, 'logo' => $themeAssets->logoUrl($entry->site), 'modules' => self::modules($entry->site),
            'cancellation' => app(ContractCancellations::class)->url($entry->site, $version->locale)]);
        $site = $entry->site;
        $currentPath = is_string($page['url'] ?? null) ? (string) parse_url($page['url'], PHP_URL_PATH) : null;
        $this->pageRenderer->withNavigation(fn (string $name, Definition $nav, string $lang): array => $this->navigation->items($site, $theme, $nav, $lang, $entry, $currentPath));
        // Globale Inhalte (L-11): aus der Datenbank, solange es eine veröffentlichte Fassung gibt — sonst aus «seed»
        $this->pageRenderer->withGlobals(static function (string $name, string $lang) use ($site): ?array {
            $global = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('type', $name)->whereNull('deleted_at')->orderBy('id')->first();
            $version = $global?->resolve($lang);

            return $version === null ? null : $version->data;
        });
        // Formulare (M-11): Versand, Fehler, Danke und Spam-Schutz aus dem Kern statt des blossen Gerüsts
        $this->pageRenderer->withForms(fn (Definition $form, string $lang): string => $this->forms->render(FormDefinition::fromDefinition($form), $lang, $currentPath ?? '/'));
        $this->pageVars = $pageVars;
        $blocks = array_map(static fn (string $html): array => ['type' => PageRenderer::RAW_BLOCK, 'data' => ['html' => $html], 'marks' => []], $leadingHtml);
        foreach (is_array($version->data['content'] ?? null) ? array_values($version->data['content']) : [] as $i => $block) {
            // TB 10 Scheibe 3 (M-28): Baustein und Lava-Teil als Block — ohne Feldmarken, sie gehören nicht der Seite
            if (Snippets::isReference($block) || (is_array($block) && ($block['type'] ?? null) === PageRenderer::PART_BLOCK)) {
                array_push($blocks, ...$this->references($entry->site, $schema, $block));

                continue;
            }
            if (! is_array($block) || ! isset($schema->blocks[(string) ($block['type'] ?? '')])) {
                continue;
            }
            $type = (string) $block['type'];
            $prefix = $this->editable === null ? null : 'content.'.$i;
            $blocks[] = ['type' => $type, 'data' => $this->prepare($schema->blocks[$type]['fields'], is_array($block['data'] ?? null) ? $block['data'] : [], $prefix),
                'marks' => $prefix === null ? [] : FrontendEditor::marks($this->editable ?? [], $prefix)];
        }

        $html = $this->pageRenderer->renderDocument($template, $pageVars, $blocks, $version->locale, $this->editable === null ? [] : FrontendEditor::marks($this->editable, ''));
        $this->editable = null;

        $html = FormRenderer::inject($html, $version->locale);

        // TB 54: Cookie-Einstellungen auch, wenn die Site Besuche zählt — mit Verweis auf die Datenschutzerklärung. Nur für
        // Besucher der Website ({@see self::forVisitors()}), nicht in Vorschau und Editor des Backends
        $privacy = LegalTemplates::meta('datenschutz', $version->locale)['slug'];

        return Consent::inject($html, $visitor ? (string) ($theme->document->find('publish')?->value('cookie') ?? 'essential-only') : 'essential-only', $version->locale,
            $visitor && app(Statistics::class)->enabled($entry->site), ($version->locale === $entry->site->default_locale ? '' : '/'.$version->locale).'/'.$privacy);
    }

    /** @return array<string, true> eingeschaltete Module der Site (Kern und nicht schaltbare immer) */
    private static function modules(Site $site): array
    {
        $backend = app(Backend::class);
        $out = [];
        foreach ($backend->modules() as $module) {
            if ($backend->moduleEnabled($module['handle'], $site)) {
                $out[$module['handle']] = true;
            }
        }

        return $out;
    }

    /** Das Template einer Seite: eigenes (Reiter Layout), sonst das für Unterseiten eines Vorfahren, sonst das des Typs. */
    public function templateFor(Entry $entry, ThemeSchema $schema): string
    {
        $layout = $entry->setting('layout', []);
        if (is_array($layout) && ($layout['mode'] ?? 'inherit') === 'own' && is_string($layout['template'] ?? null) && $layout['template'] !== '') {
            return $layout['template'];
        }
        for ($node = $entry->parent; $node !== null; $node = $node->parent) {
            $children = $node->setting('layout.children');
            if (is_string($children) && $children !== '' && $children !== 'inherit') {
                return $children;
            }
        }

        return $schema->types[$entry->type]['template'] ?? 'default';
    }

    /**
     * `{% assets %}`: Zeichensatz und Viewport — und in der Entwicklung der Vite-Client, damit der Watcher (L-13) den
     * Browser bei jeder Änderung an `themes/**` neu lädt (die Hot-Datei von `npm run dev` nennt den Dev-Server).
     */
    public static function assets(): string
    {
        $html = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        $server = DevServer::url();
        if ($server !== null) {
            $html .= sprintf('<script type="module" src="%s/@vite/client"></script>', htmlspecialchars($server, ENT_QUOTES));
        }

        return $html;
    }

    /**
     * Der Kopf einer Seite aus Metadaten: Titel (eigener oder Name plus Suffix aus `publish.seo`), Beschreibung,
     * Robots (Template «noindex» zählt), kanonische URL, Open Graph, hreflang je Sprache.
     *
     * @param  array<string, mixed>  $page
     */
    private function seo(Theme $theme, string $templateName, array $page, string $siteName): string
    {
        $publish = $theme->document->find('publish');
        $seoDef = $publish?->childrenOf('seo')[0] ?? $publish?->property('seo')?->block;
        // «{site}» im Titel-Zusatz wird der Name der Site («Über uns · Bäckerei Muster»)
        $suffix = str_replace('{site}', $siteName, (string) ($seoDef?->value('title_suffix') ?? ''));
        $meta = is_array($page['meta'] ?? null) ? $page['meta'] : [];
        $ownTitle = is_string($meta['title'] ?? null) && trim($meta['title']) !== '';
        $title = $ownTitle ? (string) $meta['title'] : (string) ($page['title'] ?? '').$suffix;
        $description = is_string($meta['description'] ?? null) ? trim($meta['description']) : '';
        $template = $theme->template($templateName);
        $robots = is_string($meta['robots'] ?? null) && $meta['robots'] !== '' ? $meta['robots'] : ($template?->value('noindex') === 'true' ? 'noindex, follow' : null);
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $html = sprintf('<title>%s</title>', $e($title));
        if ($description !== '') {
            $html .= sprintf('<meta name="description" content="%s">', $e($description));
        }
        if ($robots !== null) {
            $html .= sprintf('<meta name="robots" content="%s">', $e($robots));
        }
        if (is_string($page['canonical'] ?? null) && $page['canonical'] !== '') {
            $html .= sprintf('<link rel="canonical" href="%s">', $e($page['canonical']));
        }
        $site = $this->site;
        $url = is_string($page['canonical'] ?? null) && $page['canonical'] !== '' ? $page['canonical'] : (is_string($page['url'] ?? null) ? $page['url'] : null);
        $locale = is_string($page['locale'] ?? null) ? $page['locale'] : null;
        $image = is_array($page['image'] ?? null) && is_string($page['image']['src'] ?? null) ? $page['image']['src'] : null;
        $html .= sprintf('<meta property="og:type" content="%s">', ($page['is_home'] ?? false) === true ? 'website' : 'article');
        $html .= sprintf('<meta property="og:title" content="%s">', $e($title));
        if ($description !== '') {
            $html .= sprintf('<meta property="og:description" content="%s">', $e($description));
        }
        if ($url !== null) {
            $html .= sprintf('<meta property="og:url" content="%s">', $e($url));
        }
        if ($site !== null) {
            $html .= sprintf('<meta property="og:site_name" content="%s">', $e($site->name));
        }
        if ($locale !== null) {
            $html .= sprintf('<meta property="og:locale" content="%s">', $e(str_replace('-', '_', $locale)));
        }
        if ($image !== null) {
            $html .= sprintf('<meta property="og:image" content="%s">', $e($image));
        }
        $html .= sprintf('<meta name="twitter:card" content="%s">', $image !== null ? 'summary_large_image' : 'summary');
        $alternates = is_array($page['hreflang'] ?? null) ? $page['hreflang'] : (is_array($page['alternates'] ?? null) ? $page['alternates'] : []);
        foreach ($alternates as $alt) {
            if (is_array($alt) && isset($alt['locale'], $alt['url'])) {
                $html .= sprintf('<link rel="alternate" hreflang="%s" href="%s">', $e($alt['locale']), $e($alt['url']));
                if ($site !== null && $alt['locale'] === $site->default_locale && count($alternates) > 1) {
                    $html .= sprintf('<link rel="alternate" hreflang="x-default" href="%s">', $e($alt['url']));
                }
            }
        }

        return $html.$this->structuredData($page, $title, $description, $url, $locale);
    }

    /**
     * Schema.org als JSON-LD (M-10): `WebSite` auf der Startseite, sonst `WebPage`, dazu `BreadcrumbList` ab zwei
     * Stufen. `</` wird maskiert, damit kein Inhalt das Skript beenden kann.
     *
     * @param  array<string, mixed>  $page
     */
    private function structuredData(array $page, string $title, string $description, ?string $url, ?string $locale): string
    {
        if ($url === null) {
            return '';
        }
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $url);
        $graph = [];
        if (($page['is_home'] ?? false) === true) {
            $graph[] = array_filter(['@type' => 'WebSite', 'name' => $this->site?->name, 'url' => $url, 'inLanguage' => $locale, 'description' => $description ?: null]);
        } else {
            $graph[] = array_filter(['@type' => 'WebPage', 'name' => $title, 'url' => $url, 'inLanguage' => $locale, 'description' => $description ?: null,
                'isPartOf' => $this->site !== null ? ['@type' => 'WebSite', 'name' => $this->site->name, 'url' => $origin.'/'] : null]);
        }
        $crumbs = is_array($page['breadcrumbs'] ?? null) ? array_values($page['breadcrumbs']) : [];
        if (count($crumbs) >= 2) {
            $items = [];
            foreach ($crumbs as $i => $crumb) {
                if (is_array($crumb) && is_string($crumb['label'] ?? null)) {
                    $href = is_string($crumb['href'] ?? null) ? $crumb['href'] : '';
                    $items[] = array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['label'], 'item' => $href === '' ? null : (str_starts_with($href, 'http') ? $href : $origin.$href)]);
                }
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }
        $json = (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return '<script type="application/ld+json">'.$json.'</script>';
    }

    /**
     * @param  list<mixed>  $fields
     * @param  array<string, mixed>  $data
     * @param  string|null  $markPrefix  Pfad des Blocks für Feldmarken verschachtelter Blöcke («content.0»), sonst null
     * @return array<string, mixed>
     */
    private function prepare(array $fields, array $data, ?string $markPrefix = null): array
    {
        $out = $data;
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = (string) $field['name'];
            $value = $data[$name] ?? null;
            $out[$name] = match ((string) $field['type']) {
                'richtext' => RichText::toHtml($value),
                'image' => $this->image($value),
                'link' => is_array($value) && ($value['href'] ?? '') !== '' ? ['href' => (string) $value['href'], 'label' => $this->linkLabel((string) $value['href'], $value['label'] ?? null)] : null,
                'repeater' => array_map(fn (mixed $row): array => $this->prepare(is_array($field['fields']) ? array_values($field['fields']) : [], is_array($row) ? $row : []), is_array($value) ? array_values($value) : []),
                'blocks' => $this->nested(is_array($value) ? array_values($value) : [], $markPrefix === null ? null : $markPrefix.'.data.'.$name),
                'number' => is_numeric($value) ? $value + 0 : ($field['default'] ?? null),
                default => is_string($value) && preg_match('/^media:(\d+)$/', $value, $m) === 1 ? $this->mediaUrl((int) $m[1]) : ($value ?? ($field['default'] ?? null)),
            };
        }

        return $out;
    }

    /**
     * Ein Bild aus der Mediathek als Adresse (TB 30, Text-Feld mit «pick: image»): die Inhaltsvariante, sonst das
     * Original — leer, wenn es das Bild nicht mehr gibt.
     */
    private function mediaUrl(int $id): ?string
    {
        $media = Media::query()->withTrashed()->find($id);

        return $media === null ? null : $media->url(isset($media->variants['content']) ? 'content' : null);
    }

    /**
     * Beschriftung eines Links (TB 30): die eigene, sonst der Titel der Zielseite, wenn die Adresse eine Seite der Site
     * ist (veröffentlicht, in der Sprache der Seite) — sonst die Adresse. So stimmt «leer = Titel der Zielseite».
     */
    private function linkLabel(string $href, mixed $label): string
    {
        if (is_string($label) && trim($label) !== '') {
            return $label;
        }
        $path = (string) parse_url($href, PHP_URL_PATH);
        if ($this->site === null || ! str_starts_with($href, '/') || str_starts_with($href, '//') || $path === '') {
            return $href;
        }
        $pages = app(Pages::class);
        [$locale, $rest] = $pages->splitLocale($this->site, $path);
        $id = $pages->entryIdAt($this->site, $locale, $rest);
        $entry = $id === null ? null : Entry::query()->withoutGlobalScopes()->find($id);
        $title = $entry === null ? null : $pages->versionOf($entry, $locale)?->title;

        return is_string($title) && $title !== '' ? $title : $href;
    }

    /**
     * Verschachtelte Blöcke als fertiges HTML — jeder mit den vorbereiteten Feldern über das Markup des Themes.
     *
     * @param  list<mixed>  $blocks
     * @param  string|null  $markPrefix  Pfad der Blockliste («content.0.data.blocks») für Feldmarken, sonst null
     */
    private function nested(array $blocks, ?string $markPrefix = null): string
    {
        $renderer = $this->pageRenderer;
        if ($renderer === null) {
            return '';
        }
        $schema = $this->schema ??= ThemeSchema::fromTheme($this->themes->forSite($this->currentSite()));
        $html = '';
        foreach ($blocks as $i => $block) {
            if (! is_array($block) || ! isset($schema->blocks[(string) ($block['type'] ?? '')])) {
                continue;
            }
            $type = (string) $block['type'];
            $prefix = $markPrefix === null || $this->editable === null ? null : $markPrefix.'.'.$i;
            $html .= $renderer->renderBlockData($type, $this->prepare($schema->blocks[$type]['fields'], is_array($block['data'] ?? null) ? $block['data'] : [], $prefix), $this->pageVars, $i,
                $prefix === null ? [] : FrontendEditor::marks($this->editable ?? [], $prefix));
        }

        return $html;
    }

    private function currentSite(): Site
    {
        return $this->site ?? throw new \LogicException('Kein Inhalt in Arbeit');
    }

    /**
     * Ein Bildfeld für das Theme (K-09): Original, Alt-Text (Feld, sonst Mediathek je Sprache), Masse und je Variante
     * des Themes Quelle, `srcset` mit Dichten, AVIF, `sizes` und Lazy — `{% image %}` baut daraus `<picture>`.
     * Dateien im Papierkorb liefern bis zum endgültigen Löschen weiter (Mockup 14/2).
     *
     * @return array<string, mixed>|null
     */
    private function image(mixed $value): ?array
    {
        $id = is_array($value) ? ($value['id'] ?? null) : $value;
        if (! is_numeric($id)) {
            return null;
        }
        $media = Media::query()->withTrashed()->find((int) $id);
        if ($media === null) {
            return null;
        }
        $fieldAlt = is_array($value) && is_string($value['alt'] ?? null) ? trim($value['alt']) : '';
        $alt = $fieldAlt !== '' ? $fieldAlt : ($media->altFor($this->locale) ?? $media->altFor($this->currentSite()->default_locale) ?? '');
        $variants = [];
        foreach ($this->variants->forSite($this->currentSite()) as $name => $spec) {
            $file = $media->variants[$name] ?? null;
            if ($file === null) {
                continue;
            }
            $srcset = [];
            $avifset = [];
            foreach ([1 => $file] + array_combine(array_map('intval', array_keys($file['densities'] ?? [])), array_values($file['densities'] ?? [])) as $density => $f) {
                $srcset[] = sprintf('%s %dx', $media->url($name, 'webp', $density), $density);
                if (($f['avif'] ?? null) !== null) {
                    $avifset[] = sprintf('%s %dx', $media->url($name, 'avif', $density), $density);
                }
            }
            $variants[$name] = ['src' => $media->url($name), 'srcset' => count($srcset) > 1 ? implode(', ', $srcset) : null, 'avif' => $avifset !== [] ? implode(', ', $avifset) : null,
                'width' => $file['width'], 'height' => $file['height'], 'sizes' => $spec->sizes, 'lazy' => $spec->lazy];
        }

        return ['id' => $media->id, 'src' => $media->url(isset($media->variants['content']) ? 'content' : null), 'alt' => $alt, 'title' => $media->title, 'credit' => $media->credit,
            'width' => $media->width, 'height' => $media->height, 'variants' => $variants];
    }

    /**
     * Ein Verweis in der Blockliste (TB 10 Scheibe 3, M-28): ein Baustein wird zu seinen Blöcken, ein Lava-Teil bleibt
     * ein Block `@part`, den der Renderer über den Teil ausgibt.
     *
     * @param  array<mixed>  $block
     * @return list<array{type: string, data: array<string, mixed>, marks: array<string, array{path: string, kind: string}>}>
     */
    private function references(Site $site, ThemeSchema $schema, array $block): array
    {
        if (($block['type'] ?? null) === PageRenderer::PART_BLOCK) {
            return [['type' => PageRenderer::PART_BLOCK, 'data' => ['part' => is_array($block['data'] ?? null) ? ($block['data']['part'] ?? '') : ''], 'marks' => []]];
        }
        $out = [];
        foreach (app(Snippets::class)->expand($site, [$block]) as $inner) {
            if (is_array($inner) && ($inner['type'] ?? null) === PageRenderer::PART_BLOCK) {
                $out[] = ['type' => PageRenderer::PART_BLOCK, 'data' => ['part' => is_array($inner['data'] ?? null) ? ($inner['data']['part'] ?? '') : ''], 'marks' => []];
            } elseif (is_array($inner) && isset($schema->blocks[(string) ($inner['type'] ?? '')])) {
                $type = (string) $inner['type'];
                $out[] = ['type' => $type, 'data' => $this->prepare($schema->blocks[$type]['fields'], is_array($inner['data'] ?? null) ? $inner['data'] : []), 'marks' => []];
            }
        }

        return $out;
    }
}
