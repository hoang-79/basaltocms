<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Form;
use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteTheme;
use Basalto\Core\Sites\SiteScope;
use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Theme\Compiler;
use Basalto\Lava\Theme\DatabaseSource;
use Basalto\Lava\Theme\Design;
use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\LavaSource;
use Basalto\Lava\Theme\Source;
use Basalto\Lava\Theme\Theme;

/**
 * Installierte Themes (M-15, Mockup 27): je Ordner unter `themes/` Name, Angaben aus `theme.json`, Umfang und Prüfstand —
 * und vor einem Wechsel der Bericht, was die Site dabei nicht mehr zeigen würde. Gelöscht wird nichts: Inhalte, Blöcke,
 * Menüeinträge und Formulareingänge bleiben in der Datenbank und erscheinen wieder, sobald ein Theme sie kennt.
 *
 * @phpstan-type Meta array{name: string, label: string, author: string|null, version: string|null, license: string|null, description: string|null, preview: string|null, types: int, blocks: int, templates: int, errors: int, warnings: int, diagnostics: list<string>, updated_at: int}
 * @phpstan-type Impact array{ok: bool, diagnostics: list<string>, types: array<string, int>, blocks: array<string, int>, templates: array<string, int>, navs: array<string, int>, forms: list<string>, design: list<string>, empty: bool}
 */
final class ThemeCatalog
{
    /** Vorschaubild im Theme-Ordner, in dieser Reihenfolge gesucht. */
    public const PREVIEWS = ['assets/preview.webp', 'assets/preview.png', 'assets/preview.jpg', 'assets/preview.svg'];

    public function __construct(private readonly Registry $registry) {}

    public static function path(): string
    {
        return rtrim((string) (config('basalto.themes_path') ?: base_path('themes')), '/');
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = [];
        foreach (glob(self::path().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir.'/theme.lava')) {
                $names[] = basename($dir);
            }
        }
        sort($names);

        return $names;
    }

    public function exists(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    /** @return list<Meta> */
    public function all(): array
    {
        return array_map(fn (string $name): array => $this->meta($name), $this->names());
    }

    /** @return Meta */
    public function meta(string $name): array
    {
        $dir = self::path().'/'.$name;
        $json = is_file($dir.'/theme.json') ? json_decode((string) file_get_contents($dir.'/theme.json'), true) : null;
        $preview = null;
        foreach (self::PREVIEWS as $path) {
            if (is_file($dir.'/'.$path)) {
                $preview = $path;

                break;
            }
        }

        return $this->describe(new FileSource($dir), $name, is_array($json) ? $json : [], $preview, (int) @filemtime($dir.'/theme.lava'));
    }

    /**
     * Angaben zu einem eigenen Theme der Site (TB 22) — wie bei einem Ordner, dazu Herkunft und Signatur.
     *
     * @return Meta
     */
    public function ownMeta(SiteTheme $theme): array
    {
        $source = new DatabaseSource($theme->site_id, $theme->scope(), $theme->name);
        $json = $source->has('theme.json') ? json_decode($source->read('theme.json'), true) : null;
        $json = is_array($json) ? $json : [];
        $json['label'] = $theme->label ?? ($json['label'] ?? null);
        $json['description'] = $theme->description ?? ($json['description'] ?? null);
        $json['version'] = $theme->version ?? ($json['version'] ?? null);
        $preview = null;
        $assets = new AssetStore($theme);
        foreach (self::PREVIEWS as $path) {
            if ($assets->has($path) || $source->has($path) || (ThemeLibrary::hasFolder($theme->name) && is_file(self::path().'/'.$theme->name.'/'.$path))) {
                $preview = $path;

                break;
            }
        }

        return $this->describe($source, $theme->name, $json, $preview, (int) $theme->updated_at?->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $json
     * @return Meta
     */
    private function describe(Source $source, string $name, array $json, ?string $preview, int $updatedAt): array
    {
        $compiler = new Compiler($this->registry);
        $theme = $compiler->compile($source);
        $string = static fn (string $key): ?string => is_string($json[$key] ?? null) && $json[$key] !== '' ? $json[$key] : null;

        return [
            'name' => $name,
            'label' => $string('label') ?? (string) ($theme?->site()?->value('name') ?? $name),
            'author' => $string('author'),
            'version' => $string('version'),
            'license' => $string('license'),
            'description' => $string('description'),
            'preview' => $preview,
            'types' => $theme === null ? 0 : count(array_filter($theme->document->names('type'), static fn (string $t): bool => ! PageType::isPageType($t))),
            'blocks' => $theme === null ? 0 : count($theme->document->names('block')),
            'templates' => $theme === null ? 0 : count($theme->document->names('template')),
            'errors' => count(array_filter($compiler->diagnostics, static fn (Diagnostic $d): bool => $d->isError())),
            'warnings' => count(array_filter($compiler->diagnostics, static fn (Diagnostic $d): bool => ! $d->isError())),
            'diagnostics' => array_map(static fn (Diagnostic $d): string => $d->format(), array_slice($compiler->diagnostics, 0, 20)),
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Vorschaubild eines Themes der Site: eigenes (Bilder, Text, Ordner gleichen Namens) oder Ordner.
     *
     * @return array{content: string, type: string}|null
     */
    public function previewImage(Site $site, string $name): ?array
    {
        $own = SiteTheme::query()->where('site_id', $site->id)->where('name', $name)->first();
        foreach (self::PREVIEWS as $path) {
            $type = ThemeAssets::TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
            if ($own !== null) {
                $assets = new AssetStore($own);
                if ($assets->has($path)) {
                    return ['content' => $assets->read($path), 'type' => $type];
                }
                $text = new DatabaseSource($site->id, $own->scope(), $name);
                if ($text->has($path)) {
                    return ['content' => $text->read($path), 'type' => $type];
                }
            }
            if (($own === null || ThemeLibrary::hasFolder($name)) && is_file(self::path().'/'.$name.'/'.$path)) {
                return ['content' => (string) file_get_contents(self::path().'/'.$name.'/'.$path), 'type' => $type];
            }
        }

        return null;
    }

    /**
     * Was beim Wechsel der Site auf `$target` nicht mehr gezeigt würde — gezählt, nicht gelöscht.
     *
     * @return Impact
     */
    public function impact(Site $site, string $target): array
    {
        // TB 22: ein eigenes Theme gleichen Namens geht dem Ordner vor
        $own = SiteTheme::query()->where('site_id', $site->id)->where('name', $target)->first();
        $source = $own !== null ? new DatabaseSource($site->id, $own->scope(), $target) : new FileSource(self::path().'/'.$target);
        $siteLayer = new DatabaseSource($site->id, LavaSource::SCOPE_SITE, $site->handle.'/site');
        $compiler = new Compiler($this->registry);
        $theme = $compiler->compile($siteLayer->paths() === [] ? $source : new LayeredSource($source, $siteLayer));
        $errors = array_values(array_filter($compiler->diagnostics, static fn (Diagnostic $d): bool => $d->isError()));
        $report = [
            'ok' => $theme !== null && $errors === [],
            'diagnostics' => array_map(static fn (Diagnostic $d): string => $d->format(), array_slice($errors, 0, 20)),
            'types' => [], 'blocks' => [], 'templates' => [], 'navs' => [], 'forms' => [], 'design' => [],
            'empty' => true,
        ];
        if ($theme === null) {
            return ['empty' => false] + $report;
        }
        $schema = ThemeSchema::fromTheme($theme);
        $entries = Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['id', 'type', 'settings']);
        foreach ($entries->countBy('type') as $type => $n) {
            if (! PageType::isPageType((string) $type) && ! isset($schema->types[$type]) && $theme->document->find('global', (string) $type) === null) {
                $report['types'][(string) $type] = (int) $n;
            }
        }
        $templates = $theme->document->names('template');
        foreach ($entries as $entry) {
            foreach ([$entry->setting('layout.mode') === 'own' ? $entry->setting('layout.template') : null, $entry->setting('layout.children')] as $template) {
                if (is_string($template) && $template !== '' && $template !== 'inherit' && ! in_array($template, $templates, true)) {
                    $report['templates'][$template] = ($report['templates'][$template] ?? 0) + 1;
                }
            }
        }
        $blocks = [];
        EntryVersion::query()->whereIn('entry_id', $entries->modelKeys())->where('status', '!=', EntryStatus::Archived->value)
            ->select(['id', 'entry_id', 'data'])->chunkById(200, function ($versions) use ($schema, &$blocks): void {
                foreach ($versions as $version) {
                    foreach (self::blockTypes($version->data) as $type) {
                        if (! isset($schema->blocks[$type])) {
                            $blocks[$type][$version->entry_id] = true;
                        }
                    }
                }
            });
        $report['blocks'] = array_map('count', $blocks);
        foreach (NavigationItem::query()->withoutGlobalScopes()->where('site_id', $site->id)->get(['nav'])->countBy('nav') as $nav => $n) {
            if ($theme->document->find('nav', (string) $nav) === null) {
                $report['navs'][(string) $nav] = (int) $n;
            }
        }
        foreach (Form::query()->withoutGlobalScopes()->where('site_id', $site->id)->pluck('handle') as $handle) {
            if ($theme->document->find('form', (string) $handle) === null) {
                $report['forms'][] = (string) $handle;
            }
        }
        $design = Design::of($theme);
        $overrides = ThemeAssets::overrides($site);
        foreach (array_keys($overrides['colors'] ?? []) as $name) {
            if (! isset($design->colors[$name])) {
                $report['design'][] = __('Farbe :name', ['name' => $name]);
            }
        }
        foreach (array_keys($overrides['fonts'] ?? []) as $name) {
            if (! isset($design->fonts[$name])) {
                $report['design'][] = __('Schrift :name', ['name' => $name]);
            }
        }
        $report['empty'] = $report['types'] === [] && $report['blocks'] === [] && $report['templates'] === [] && $report['navs'] === [] && $report['forms'] === [] && $report['design'] === [];

        return $report;
    }

    /** @return list<string> Blocktypen einer Fassung, auch verschachtelte */
    public static function blockTypes(mixed $data): array
    {
        $types = [];
        $walk = static function (mixed $value) use (&$walk, &$types): void {
            if (! is_array($value)) {
                return;
            }
            if (is_string($value['type'] ?? null) && array_key_exists('data', $value) && $value['type'] !== 'doc') {
                $types[] = $value['type'];
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk(is_array($data) ? ($data['content'] ?? null) : null);

        return array_values(array_unique($types));
    }

    /** Ein Theme kompilieren, ohne die Site umzustellen (Vorschau, Prüfung). */
    public function compile(string $name): ?Theme
    {
        return (new Compiler($this->registry))->compile(new FileSource(self::path().'/'.$name));
    }
}
