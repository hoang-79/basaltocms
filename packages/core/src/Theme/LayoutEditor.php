<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Media\ImageVariant;
use Basalto\Core\Models\Site;
use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Printer;
use Basalto\Lava\Structure\Property;
use Basalto\Lava\Structure\SourceEditor;
use Basalto\Lava\Structure\Value;
use Basalto\Lava\Theme\Compiler;
use Basalto\Lava\Theme\PageRenderer;
use Basalto\Lava\Theme\Theme;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Theme-Verwaltung als Formular (M-28, Mockup 27): Layouts mit Regionen und Vererbung, Lava-Teile mit Einstellungen,
 * Templates je Typ und Bildvarianten — zwei Ansichten derselben Lava-Quelle. Das Formular wird zu einer Definition,
 * der Drucker schreibt sie, und `SourceEditor` setzt sie an ihre Stelle in der Datei; alles andere bleibt Byte für
 * Byte stehen. Gespeichert wird über `ThemeEditor::saveDraft` — damit gelten Prüfung vor dem Speichern, Vorschau,
 * Freigabe und Fassungen wie im Lava-Editor.
 *
 * Unveränderte Felder behalten ihre Lava-Schreibweise: Eine Eigenschaft oder Region, deren Formularwert gleich
 * bleibt, wird nicht neu gedruckt. Kommentare innerhalb einer geänderten Definition kennt der Drucker nicht.
 *
 * @phpstan-type FillItem array{word: string, name: string|null, off: bool}
 * @phpstan-type RegionForm array{name: string, optional: bool, where: string|null, target: string|null, fill: list<FillItem>}
 * @phpstan-type LayoutForm array{name: string, extends: string|null, regions: list<RegionForm>}
 * @phpstan-type TemplateRegion array{name: string, fill: list<FillItem>}
 * @phpstan-type TemplateForm array{name: string, for: string, layout: string, noindex: bool, regions: list<TemplateRegion>}
 * @phpstan-type PartForm array{name: string, kind: string, settings: array<string, string>, markup: string|null}
 * @phpstan-type VariantForm array{name: string, width: int, height: int|null, mode: string, densities: list<int>, sizes: string|null, lazy: bool}
 * @phpstan-type Finding array{file: string, line: int, column: int, severity: string, message: string}
 * @phpstan-type Change array{file: string, before: string, after: string, code: string}
 */
final class LayoutEditor
{
    /** Namen von Layouts und Teilen: wie Lava-Bezeichner, ohne Punkt (der gehört den Modulen). */
    public const NAME = '/^[a-z][a-z0-9_]*$/';

    public const REGION = '/^[a-z][a-z0-9_-]*$/';

    public const VARIANT = '/^[a-z][a-z0-9-]*$/';

    /** Was in einer Region stehen kann — mit Namen bei «part», «nav», «global». */
    public const WORDS = ['content', 'content.first', 'content.rest', 'part', 'nav', 'global', 'breadcrumbs', 'languages'];

    public const NAMED_WORDS = ['part', 'nav', 'global'];

    /** Einstellungen eines Teils als Zahl, als Liste von Templates, als Text in Anführungszeichen — der Rest als Wort. */
    public const NUMBER_SETTINGS = ['depth', 'start', 'per'];

    public const LIST_SETTINGS = ['only', 'exclude'];

    public const TEXT_SETTINGS = ['label'];

    public function __construct(private readonly ThemeEditor $editor, private readonly Registry $registry) {}

    /**
     * Das Theme der Site, wie es mit den Entwürfen aussieht (bei einem Ordner-Theme der Ordner).
     *
     * @throws ThemeException
     */
    public function theme(Site $site): Theme
    {
        $compiler = new Compiler($this->registry);
        $source = $this->editor->draftSource($site);
        $theme = $compiler->compile($source);
        if ($theme === null) {
            throw new ThemeException($source->name(), $compiler->diagnostics);
        }

        return $theme;
    }

    /** Eine Definition des Themes selbst (nicht aus den Lava-Ergänzungen der Site). */
    public static function find(Theme $theme, string $kind, string $name): ?Definition
    {
        foreach ($theme->document->definitions as $def) {
            if ($def->kind === $kind && $def->name === $name && ! str_starts_with($def->file, LayeredSource::PREFIX)) {
                return $def;
            }
        }

        return null;
    }

    /** @return list<Definition> */
    public static function all(Theme $theme, string $kind): array
    {
        return array_values(array_filter($theme->document->all($kind), static fn (Definition $d): bool => ! str_starts_with($d->file, LayeredSource::PREFIX)));
    }

    // ── Lesen: Definition → Formular ─────────────────────────────────────────────────────────────

    /** @return LayoutForm */
    public static function layoutForm(Definition $layout): array
    {
        $regions = [];
        foreach ($layout->childrenOf('region') as $region) {
            $regions[] = self::regionForm($region);
        }

        return ['name' => (string) $layout->name, 'extends' => $layout->modifierAfter('extends'), 'regions' => $regions];
    }

    /** @return RegionForm */
    private static function regionForm(Definition $region): array
    {
        $where = $region->modifierAfter('before') !== null ? 'before' : ($region->modifierAfter('after') !== null ? 'after' : null);

        return ['name' => (string) $region->name, 'optional' => $region->hasModifier('optional'), 'where' => $where,
            'target' => $where === null ? null : $region->modifierAfter($where), 'fill' => PageRenderer::fill($region->typed)];
    }

    /** @return TemplateForm */
    public static function templateForm(Definition $template): array
    {
        $regions = [];
        foreach ($template->childrenOf('region') as $region) {
            $regions[] = ['name' => (string) $region->name, 'fill' => PageRenderer::fill($region->typed)];
        }

        return ['name' => (string) $template->name, 'for' => (string) $template->value('for'), 'layout' => (string) $template->value('layout'),
            'noindex' => $template->value('noindex') === 'true', 'regions' => $regions];
    }

    /** @return PartForm */
    public static function partForm(Definition $part): array
    {
        $settings = [];
        foreach ($part->properties as $p) {
            $settings[self::settingKey($p)] = self::settingText($p);
        }

        return ['name' => (string) $part->name, 'kind' => (string) $part->typedFirst(), 'settings' => $settings, 'markup' => $part->markup('main')];
    }

    /** @return VariantForm */
    public static function variantForm(Definition $variant): array
    {
        $height = $variant->value('height') !== null ? (int) $variant->value('height') : null;
        $densities = [];
        $property = $variant->property('densities');
        foreach ($property === null ? [] : $property->values as $v) {
            $densities[] = (int) $v->value;
        }
        $sizes = $variant->value('sizes');

        return ['name' => (string) $variant->name, 'width' => (int) $variant->value('width'), 'height' => $height,
            'mode' => $variant->value('mode') ?? ($height !== null ? ImageVariant::MODE_EXACT : ImageVariant::MODE_SCALE),
            'densities' => $densities === [] ? [1] : $densities, 'sizes' => $sizes !== null && trim($sizes) !== '' ? $sizes : null, 'lazy' => $variant->value('lazy') !== 'false'];
    }

    /**
     * Die Bildvarianten des Themes als Definitionen (`design { images { variant … } }`).
     *
     * @return list<Definition>
     */
    public static function variants(Theme $theme): array
    {
        return self::images($theme)?->childrenOf('variant') ?? [];
    }

    private static function images(Theme $theme): ?Definition
    {
        return self::designOf($theme)?->childrenOf('images')[0] ?? null;
    }

    private static function designOf(Theme $theme): ?Definition
    {
        foreach (self::all($theme, 'design') as $design) {
            return $design;
        }

        return null;
    }

    // ── Schreiben: Formular → Definition → Quelle ────────────────────────────────────────────────

    /**
     * Ein Layout anlegen oder ändern. Neue Layouts erweitern ein bestehendes (`extends`) — das Markup bleibt Code.
     *
     * @param  LayoutForm  $form
     * @return Change|null null = nichts geändert
     */
    public function layoutChange(Site $site, ?string $current, array $form): ?array
    {
        $theme = $this->theme($site);
        $old = $current === null ? null : (self::find($theme, 'layout', $current) ?? throw ValidationException::withMessages(['name' => __('Layout «:name» gibt es nicht (mehr).', ['name' => $current])]));
        $this->checkName($theme, 'layout', $form['name'], $current);
        $errors = [];
        if ($form['extends'] !== null) {
            if ($form['extends'] === $form['name'] || self::find($theme, 'layout', $form['extends']) === null || in_array($form['name'], $this->ancestors($theme, $form['extends']), true)) {
                $errors['extends'] = __('«:name» ist kein anderes Layout dieses Themes (ohne Kreis).', ['name' => $form['extends']]);
            }
        } elseif ($old === null || ($old->markups === [] && ! $theme->source->has('layouts/'.$old->name.'.lava.html'))) {
            $errors['extends'] = __('Ein neues Layout erweitert ein bestehendes — sein Markup bleibt Code (Lava-Editor).');
        }
        $chain = $this->markupRegions($theme, $form['extends'], $old);
        $known = $this->regionNames($theme, $form['extends']);
        $names = [];
        foreach ($form['regions'] as $i => $region) {
            if (in_array($region['name'], $names, true)) {
                $errors["regions.$i.name"] = __('Region «:name» gibt es zweimal.', ['name' => $region['name']]);
            }
            $names[] = $region['name'];
            if ($region['where'] !== null && ($region['target'] === null || $region['target'] === $region['name'])) {
                $errors["regions.$i.target"] = __('Neben welcher Region?');
            }
            if ($region['where'] === null && ! in_array($region['name'], $chain, true) && ! in_array($region['name'], $known, true)) {
                $errors["regions.$i.where"] = __('Region «:name» hat keinen Platz im Markup — «vor» oder «nach» einer Region wählen.', ['name' => $region['name']]);
            }
            $errors += $this->checkFill($theme, $region['fill'], "regions.$i.fill");
        }
        self::fail($errors);

        $def = new Definition('layout', $form['name'], [], $form['extends'] !== null ? [self::ref('extends'), self::ref($form['extends'])] : [], $old->file ?? 'theme.lava', $old->line ?? 0, $old->column ?? 1);
        $oldRegions = [];
        foreach ($old?->childrenOf('region') ?? [] as $r) {
            $oldRegions[(string) $r->name] = $r;
        }
        $children = [];
        foreach ($form['regions'] as $region) {
            $previous = $oldRegions[$region['name']] ?? null;
            $children[] = $previous !== null && self::regionForm($previous) === self::normalizeRegion($region) ? $previous : self::regionDefinition($region, $def->file);
        }
        foreach ($old->children ?? [] as $child) {
            if ($child->kind !== 'region') {
                $children[] = $child;
            }
        }
        $this->rebuild($def, $old, $old->properties ?? [], $children, $old->markups ?? []);

        return $this->change($site, $theme, 'layout', $old, $def, true);
    }

    /** @return Change|null */
    public function layoutRemoval(Site $site, string $name): ?array
    {
        $theme = $this->theme($site);
        $old = self::find($theme, 'layout', $name) ?? throw ValidationException::withMessages(['name' => __('Layout «:name» gibt es nicht (mehr).', ['name' => $name])]);

        return $this->change($site, $theme, 'layout', $old, null, true);
    }

    /**
     * Ein Template ändern: Layout, `noindex` und welche Regionen es selbst füllt (die übrigen füllt das Layout).
     *
     * @param  TemplateForm  $form
     * @return Change|null
     */
    public function templateChange(Site $site, string $current, array $form): ?array
    {
        $theme = $this->theme($site);
        $old = self::find($theme, 'template', $current) ?? throw ValidationException::withMessages(['name' => __('Template «:name» gibt es nicht (mehr).', ['name' => $current])]);
        $errors = [];
        if (self::find($theme, 'layout', $form['layout']) === null) {
            $errors['layout'] = __('Layout «:name» gibt es nicht.', ['name' => $form['layout']]);
        }
        $regions = $this->regionNames($theme, $form['layout']);
        $names = [];
        foreach ($form['regions'] as $i => $region) {
            if (! in_array($region['name'], $regions, true) || in_array($region['name'], $names, true)) {
                $errors["regions.$i.name"] = __('Layout «:layout» hat keine Region «:region» (oder sie steht doppelt).', ['layout' => $form['layout'], 'region' => $region['name']]);
            }
            $names[] = $region['name'];
            $errors += $this->checkFill($theme, $region['fill'], "regions.$i.fill");
        }
        self::fail($errors);

        $def = new Definition('template', $current, $old->typed, $old->modifiers, $old->file, $old->line, $old->column);
        $before = self::templateForm($old);
        $properties = [];
        foreach ($old->properties as $p) {
            $properties[] = match (true) {
                $p->key === 'layout' && $p->lang === null && $before['layout'] !== $form['layout'] => self::property('layout', [self::ref($form['layout'])]),
                $p->key === 'noindex' && $p->lang === null && $before['noindex'] !== $form['noindex'] => null,
                default => $p,
            };
        }
        $properties = array_values(array_filter($properties));
        if ($form['noindex'] && ! $before['noindex']) {
            $properties[] = self::property('noindex', [new Value(Value::BOOL, true, 0, 0)]);
        }
        $oldRegions = [];
        foreach ($old->childrenOf('region') as $r) {
            $oldRegions[(string) $r->name] = $r;
        }
        $children = [];
        foreach ($form['regions'] as $region) {
            $previous = $oldRegions[$region['name']] ?? null;
            $normalized = ['name' => $region['name'], 'fill' => self::normalizeFill($region['fill'])];
            $children[] = $previous !== null && ['name' => (string) $previous->name, 'fill' => PageRenderer::fill($previous->typed)] === $normalized
                ? $previous
                : new Definition('region', $region['name'], self::fillValues($region['fill']), $region['fill'] === [] ? [self::ref('off')] : [], $def->file, 0, 0);
        }
        foreach ($old->children as $child) {
            if ($child->kind !== 'region') {
                $children[] = $child;
            }
        }
        $this->rebuild($def, $old, $properties, $children, $old->markups);

        return $this->change($site, $theme, 'template', $old, $def, true);
    }

    /**
     * Eine Teil-Instanz anlegen oder ändern: Art aus der Registry, Einstellungen der Art, bei «html» das Markup.
     *
     * @param  PartForm  $form
     * @return Change|null
     */
    public function partChange(Site $site, ?string $current, array $form): ?array
    {
        $theme = $this->theme($site);
        $old = $current === null ? null : (self::find($theme, 'part', $current) ?? throw ValidationException::withMessages(['name' => __('Teil «:name» gibt es nicht (mehr).', ['name' => $current])]));
        $this->checkName($theme, 'part', $form['name'], $current);
        $kinds = $this->registry->parts();
        if (! isset($kinds[$form['kind']])) {
            self::fail(['kind' => __('Die Art «:kind» ist nicht registriert (Kern oder eingeschaltetes Modul).', ['kind' => $form['kind']])]);
        }
        $errors = [];
        foreach ($form['settings'] as $key => $value) {
            $base = explode('.', $key)[0];
            if ($value !== '' && in_array($base, self::NUMBER_SETTINGS, true) && preg_match('/^\d{1,4}$/', $value) !== 1) {
                $errors["settings.$key"] = __('Eine ganze Zahl.');
            }
            if ($value !== '' && (in_array($base, self::LIST_SETTINGS, true) || $base === 'source') && preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*(\s*,\s*[A-Za-z_][A-Za-z0-9_.-]*)*$/', $value) !== 1) {
                $errors["settings.$key"] = __('Namen aus dem Theme, mit Komma getrennt.');
            }
        }
        self::fail($errors);

        $typed = [self::ref($form['kind'])];
        $def = new Definition('part', $form['name'], $typed, [], $old->file ?? 'theme.lava', $old->line ?? 0, $old->column ?? 1);
        $before = $old === null ? [] : self::partForm($old)['settings'];
        $properties = [];
        $written = [];
        foreach ($old->properties ?? [] as $p) {
            $key = self::settingKey($p);
            $written[] = $key;
            $value = trim($form['settings'][$key] ?? '');
            if ($value === '') {
                continue;
            }
            $properties[] = ($before[$key] ?? null) === $value ? $p : self::setting($key, $value);
        }
        foreach ($form['settings'] as $key => $value) {
            if (! in_array($key, $written, true) && trim($value) !== '') {
                $properties[] = self::setting($key, trim($value));
            }
        }
        $markups = $old->markups ?? [];
        if ($form['markup'] !== null && trim($form['markup']) !== '') {
            $markups['main'] = rtrim(str_replace("\r\n", "\n", $form['markup']));
        } else {
            unset($markups['main']);
        }
        $this->rebuild($def, $old, $properties, $old->children ?? [], $markups);

        return $this->change($site, $theme, 'part', $old, $def, false);
    }

    /** @return Change|null */
    public function partRemoval(Site $site, string $name): ?array
    {
        $theme = $this->theme($site);
        $old = self::find($theme, 'part', $name) ?? throw ValidationException::withMessages(['name' => __('Teil «:name» gibt es nicht (mehr).', ['name' => $name])]);

        return $this->change($site, $theme, 'part', $old, null, false);
    }

    /**
     * Eine Bildvariante anlegen oder ändern (K-09, `design.images`).
     *
     * @param  VariantForm  $form
     * @return Change|null
     */
    public function variantChange(Site $site, ?string $current, array $form): ?array
    {
        $theme = $this->theme($site);
        $design = self::designOf($theme) ?? throw ValidationException::withMessages(['name' => __('Das Theme hat keinen «design»-Block (Station B) — im Lava-Editor anlegen.')]);
        $images = self::images($theme);
        $variants = [];
        foreach ($images?->childrenOf('variant') ?? [] as $v) {
            $variants[(string) $v->name] = $v;
        }
        $old = $current === null ? null : ($variants[$current] ?? throw ValidationException::withMessages(['name' => __('Variante «:name» gibt es nicht (mehr).', ['name' => $current])]));
        $errors = [];
        if (preg_match(self::VARIANT, $form['name']) !== 1) {
            $errors['name'] = __('Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben vorn.');
        } elseif ($form['name'] !== $current && isset($variants[$form['name']])) {
            $errors['name'] = __('Variante «:name» gibt es schon.', ['name' => $form['name']]);
        }
        if ($form['mode'] !== ImageVariant::MODE_SCALE && $form['height'] === null) {
            $errors['height'] = __('«Rahmen» und «exakt» brauchen eine Höhe.');
        }
        self::fail($errors);

        $form['densities'] = array_values(array_unique($form['densities'] ?: [1]));
        sort($form['densities']);
        $before = $old === null ? null : self::variantForm($old);
        $wanted = [
            'width' => [self::number($form['width'])],
            'height' => $form['height'] === null ? null : [self::number($form['height'])],
            // Ohne Angabe gilt: mit Höhe «exact», sonst «scale» — die Vorgabe schreibt das Formular nicht hin
            'mode' => $form['mode'] === ($form['height'] !== null ? ImageVariant::MODE_EXACT : ImageVariant::MODE_SCALE) ? null : [self::ref($form['mode'])],
            'sizes' => $form['sizes'] === null || trim($form['sizes']) === '' ? null : [self::str(trim($form['sizes']))],
            'densities' => $form['densities'] === [1] ? null : array_map(static fn (int $d): Value => self::number($d), $form['densities']),
            'lazy' => $form['lazy'] ? null : [new Value(Value::BOOL, false, 0, 0)],
        ];
        $unchanged = static function (string $key) use ($before, $form): bool {
            if ($before === null) {
                return false;
            }

            return match ($key) {
                'mode' => $before['mode'] === $form['mode'] && $before['height'] === $form['height'],
                'sizes' => $before['sizes'] === ($form['sizes'] === null || trim($form['sizes']) === '' ? null : trim($form['sizes'])),
                default => $before[$key] === $form[$key],
            };
        };
        $file = $old->file ?? $design->file;
        $def = new Definition('variant', $form['name'], [], [], $file, $old->line ?? 0, $old->column ?? 1);
        $properties = [];
        $seen = [];
        foreach ($old->properties ?? [] as $p) {
            $seen[] = $p->key;
            if (array_key_exists($p->key, $wanted) && ! $unchanged($p->key)) {
                if ($wanted[$p->key] !== null) {
                    $properties[] = self::property($p->key, $wanted[$p->key]);
                }

                continue;
            }
            $properties[] = $p;
        }
        foreach ($wanted as $key => $values) {
            if (! in_array($key, $seen, true) && $values !== null && ! ($unchanged($key) && $old !== null)) {
                $properties[] = self::property($key, $values);
            }
        }
        $this->rebuild($def, $old, $properties, [], []);
        $printer = new Printer;
        $printed = $printer->printDefinition($def, 0, true);
        if ($old !== null && $printed === $printer->printDefinition($old, 0, true)) {
            return null;
        }
        $text = (string) $this->editor->currentText($site, $file);
        if ($old !== null) {
            $after = SourceEditor::replace($text, $old, $printed);
        } elseif ($images !== null && $images->childrenOf('variant') !== []) {
            $siblings = $images->childrenOf('variant');
            $after = SourceEditor::insertAfter($text, $siblings[count($siblings) - 1], $printed, false);
        } else {
            // Ohne «images» bekommt «design» den Block neu — Kommentare innerhalb von «design» gehen dabei verloren
            $images = new Definition('images', null, [], [], $design->file, 0, 0);
            $images->children[] = $def;
            $copy = clone $design;
            $copy->children[] = $images;
            $copy->order = $design->order === [] ? [] : [...$design->order, ['c', count($copy->children) - 1]];
            $after = SourceEditor::replace((string) $this->editor->currentText($site, $design->file), $design, $printer->printDefinition($copy, 0, true));
            $file = $design->file;
            $printed = $printer->printDefinition($images, 1);
        }

        return ['file' => $file, 'before' => $text, 'after' => $after, 'code' => $printed];
    }

    /** @return Change */
    public function variantRemoval(Site $site, string $name): array
    {
        $theme = $this->theme($site);
        foreach (self::variants($theme) as $variant) {
            if ($variant->name === $name) {
                $text = (string) $this->editor->currentText($site, $variant->file);

                return ['file' => $variant->file, 'before' => $text, 'after' => SourceEditor::remove($text, $variant), 'code' => ''];
            }
        }
        throw ValidationException::withMessages(['name' => __('Variante «:name» gibt es nicht (mehr).', ['name' => $name])]);
    }

    /**
     * Schreibt eine Änderung als Entwurf — nur, wenn das Theme damit fehlerfrei kompiliert (sonst ThemeEditorException
     * mit den Befunden). Der Stand der Datei beim Lesen schützt vor stillem Überschreiben.
     *
     * @param  Change  $change
     * @return list<Finding> verbleibende Warnungen
     */
    public function save(Site $site, array $change, Authenticatable $user): array
    {
        return $this->editor->saveDraft($site, $change['file'], $change['after'], $user, hash('sha256', $change['before']));
    }

    /**
     * Befunde für eine Änderung, ohne zu speichern (Probe neben dem Formular).
     *
     * @param  Change  $change
     * @return list<Finding>
     */
    public function probe(Site $site, array $change): array
    {
        return $this->editor->check($site, [$change['file'] => $change['after']]);
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Die Regionen, die ein Layout samt Eltern hat.
     *
     * @return list<string>
     */
    public function regionNames(Theme $theme, ?string $layout): array
    {
        $names = [];
        foreach (array_reverse($this->chain($theme, $layout)) as $def) {
            foreach ($def->childrenOf('region') as $region) {
                $names[] = (string) $region->name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Regionen, die das Markup der Layout-Kette mit `{% region "…" %}` einsetzt — sie brauchen keinen Platz.
     *
     * @return list<string>
     */
    public function markupRegions(Theme $theme, ?string $parent, ?Definition $own = null): array
    {
        $markups = [];
        foreach ([...($own !== null ? [$own] : []), ...$this->chain($theme, $parent)] as $def) {
            $markups[] = (string) $theme->markupOf($def, 'layouts');
        }
        preg_match_all('/\{%-?\s*region\s+"([^"]+)"\s*-?%\}/', implode("\n", $markups), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Für jede Region eines Layouts: wo sie steht (Markup, vor/nach) und was sie füllt — samt geerbten.
     *
     * @return list<array{name: string, inherited: bool, from: string, optional: bool, where: string|null, target: string|null, fill: list<FillItem>}>
     */
    public function resolvedRegions(Theme $theme, Definition $layout): array
    {
        $out = [];
        foreach (array_reverse($this->chain($theme, (string) $layout->name)) as $def) {
            foreach ($def->childrenOf('region') as $region) {
                $row = ['inherited' => $def !== $layout, 'from' => (string) $def->name] + self::regionForm($region);
                $out[$row['name']] = $row;
            }
        }

        return array_values($out);
    }

    /** @return list<Definition> das Layout zuerst, dann seine Eltern */
    private function chain(Theme $theme, ?string $layout): array
    {
        $chain = [];
        // TB 35: Ersetzt eine Site-Schicht ein Layout (z. B. die Demo), zählt deren Fassung — sie ist es, die rendert
        while ($layout !== null && ($def = self::find($theme, 'layout', $layout) ?? $theme->document->find('layout', $layout)) !== null && ! in_array($def, $chain, true)) {
            $chain[] = $def;
            $layout = $def->modifierAfter('extends');
        }

        return $chain;
    }

    /** @return list<string> */
    private function ancestors(Theme $theme, string $layout): array
    {
        return array_map(static fn (Definition $d): string => (string) $d->name, $this->chain($theme, $layout));
    }

    private function checkName(Theme $theme, string $kind, string $name, ?string $current): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            self::fail(['name' => __('Kleinbuchstaben, Ziffern und Unterstrich, mit einem Buchstaben vorn.')]);
        }
        if ($name !== $current && $theme->document->find($kind, $name) !== null) {
            self::fail(['name' => __('«:name» gibt es schon.', ['name' => $name])]);
        }
    }

    /**
     * @param  list<FillItem>  $fill
     * @return array<string, string>
     */
    private function checkFill(Theme $theme, array $fill, string $path): array
    {
        $errors = [];
        foreach ($fill as $i => $item) {
            if (! in_array($item['word'], self::WORDS, true)) {
                $errors["$path.$i"] = __('«:word» kann nicht in einer Region stehen.', ['word' => $item['word']]);
            } elseif (in_array($item['word'], self::NAMED_WORDS, true) && ($item['name'] === null || $theme->document->find($item['word'], $item['name']) === null)) {
                $errors["$path.$i"] = __(':kind «:name» gibt es im Theme nicht.', ['kind' => ['part' => __('Teil'), 'nav' => __('Navigation'), 'global' => __('Globaler Inhalt')][$item['word']], 'name' => (string) $item['name']]);
            }
        }

        return $errors;
    }

    /**
     * Setzt Eigenschaften, Unterelemente und Markup — in der Reihenfolge der alten Definition, Neues dahinter; neue
     * Unterelemente folgen auf das letzte alte.
     *
     * @param  list<Property|null>  $properties
     * @param  list<Definition>  $children
     * @param  array<string, string>  $markups
     */
    private function rebuild(Definition $def, ?Definition $old, array $properties, array $children, array $markups): void
    {
        $def->properties = array_values(array_filter($properties));
        $def->children = $children;
        $def->markups = $markups;
        if ($old === null || $old->order === []) {
            return;
        }
        $order = [];
        $usedP = [];
        $nextChild = 0;
        $lastChildSlot = null;
        foreach ($old->order as [$kind, $ref]) {
            if ($kind === 'p') {
                $previous = $old->properties[(int) $ref] ?? null;
                foreach ($def->properties as $i => $p) {
                    if ($previous !== null && ! isset($usedP[$i]) && $p->key === $previous->key && $p->lang === $previous->lang) {
                        $order[] = ['p', $i];
                        $usedP[$i] = true;
                        break;
                    }
                }
            } elseif ($kind === 'c') {
                if ($nextChild < count($def->children)) {
                    $order[] = ['c', $nextChild++];
                    $lastChildSlot = count($order);
                }
            } elseif (isset($def->markups[(string) $ref])) {
                $order[] = ['m', (string) $ref];
            }
        }
        $rest = [];
        while ($nextChild < count($def->children)) {
            $rest[] = ['c', $nextChild++];
        }
        if ($lastChildSlot !== null) {
            array_splice($order, $lastChildSlot, 0, $rest);
        } else {
            $order = [...$order, ...$rest];
        }
        foreach (array_keys($def->properties) as $i) {
            if (! isset($usedP[$i])) {
                $order[] = ['p', $i];
            }
        }
        foreach (array_keys($def->markups) as $name) {
            if (! in_array(['m', $name], $order, true)) {
                $order[] = ['m', $name];
            }
        }
        $def->order = $order;
    }

    /**
     * Die Änderung an der Datei: neue Definition an die Stelle der alten, neu hinter die letzte ihrer Art (sonst ans
     * Ende von theme.lava), `null` entfernt die alte.
     *
     * @return Change|null
     */
    private function change(Site $site, Theme $theme, string $kind, ?Definition $old, ?Definition $new, bool $block): ?array
    {
        $printer = new Printer;
        $printed = $new === null ? '' : $printer->printDefinition($new, 0, $block);
        if ($old !== null && $new !== null && $printed === $printer->printDefinition($old, 0, $block)) {
            return null;
        }
        if ($old !== null) {
            $text = (string) $this->editor->currentText($site, $old->file);
            $after = $new === null ? SourceEditor::remove($text, $old) : SourceEditor::replace($text, $old, $printed);

            return ['file' => $old->file, 'before' => $text, 'after' => $after, 'code' => $printed];
        }
        $siblings = self::all($theme, $kind);
        if ($siblings === [] && $kind === 'part') {
            $siblings = self::all($theme, 'layout');
        }
        $anchor = $siblings === [] ? null : $siblings[count($siblings) - 1];
        $file = $anchor->file ?? 'theme.lava';
        $text = (string) $this->editor->currentText($site, $file);

        return ['file' => $file, 'before' => $text, 'after' => SourceEditor::insertAfter($text, $anchor, (string) $printed), 'code' => $printed];
    }

    /** @param  RegionForm  $region */
    private static function regionDefinition(array $region, string $file): Definition
    {
        $modifiers = [];
        if ($region['optional']) {
            $modifiers[] = self::ref('optional');
        }
        if ($region['where'] !== null && $region['target'] !== null) {
            $modifiers[] = self::ref($region['where']);
            $modifiers[] = self::ref($region['target']);
        }

        return new Definition('region', $region['name'], self::fillValues($region['fill']), $modifiers, $file, 0, 0);
    }

    /**
     * @param  RegionForm  $region
     * @return RegionForm
     */
    private static function normalizeRegion(array $region): array
    {
        return ['name' => $region['name'], 'optional' => $region['optional'], 'where' => $region['where'], 'target' => $region['where'] === null ? null : $region['target'],
            'fill' => self::normalizeFill($region['fill'])];
    }

    /**
     * @param  list<FillItem>  $fill
     * @return list<FillItem>
     */
    private static function normalizeFill(array $fill): array
    {
        return array_map(static fn (array $i): array => ['word' => $i['word'], 'name' => in_array($i['word'], self::NAMED_WORDS, true) ? $i['name'] : null, 'off' => $i['off']], $fill);
    }

    /**
     * @param  list<FillItem>  $fill
     * @return list<Value>
     */
    private static function fillValues(array $fill): array
    {
        $values = [];
        foreach (self::normalizeFill($fill) as $item) {
            $values[] = self::ref($item['word']);
            if ($item['name'] !== null) {
                $values[] = self::ref($item['name']);
            }
            if ($item['off']) {
                $values[] = self::ref('off');
            }
        }

        return $values;
    }

    private static function settingKey(Property $p): string
    {
        return $p->key.($p->lang !== null ? '.'.$p->lang : '');
    }

    private static function settingText(Property $p): string
    {
        return in_array($p->key, self::LIST_SETTINGS, true) ? implode(', ', $p->refs()) : $p->text();
    }

    /** Eine Einstellung aus dem Formular: Zahl, Liste von Namen, Text in Anführungszeichen oder ein Wort. */
    private static function setting(string $key, string $value): Property
    {
        [$name, $lang] = str_contains($key, '.') ? explode('.', $key, 2) : [$key, null];
        $values = match (true) {
            in_array($name, self::NUMBER_SETTINGS, true) => [self::number((int) $value)],
            in_array($name, self::LIST_SETTINGS, true) => array_map(static fn (string $v): Value => self::ref(trim($v)), array_values(array_filter(explode(',', $value), static fn (string $v): bool => trim($v) !== ''))),
            in_array($name, self::TEXT_SETTINGS, true) => [self::str($value)],
            preg_match('/^\d+$/', $value) === 1 => [self::number((int) $value)],
            preg_match('/^[\pL_][\pL\pN_.\-]*$/u', $value) === 1 => [in_array($value, ['true', 'false'], true) ? new Value(Value::BOOL, $value === 'true', 0, 0) : self::ref($value)],
            default => [self::str($value)],
        };

        return new Property($name, $lang, $values, null, 0, 0);
    }

    /** @param  list<Value>  $values */
    private static function property(string $key, array $values): Property
    {
        return new Property($key, null, $values, null, 0, 0);
    }

    private static function ref(string $value): Value
    {
        return new Value(Value::REF, $value, 0, 0);
    }

    private static function str(string $value): Value
    {
        return new Value(Value::STRING, str_replace(["\r", "\n"], ' ', $value), 0, 0);
    }

    private static function number(int $value): Value
    {
        return new Value(Value::NUMBER, $value, 0, 0);
    }

    /** @param  array<string, string>  $errors */
    private static function fail(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Fehler des Compilers aus einer Liste von Befunden.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public static function errors(array $findings): array
    {
        return array_values(array_filter($findings, static fn (array $f): bool => $f['severity'] === Diagnostic::ERROR));
    }
}
