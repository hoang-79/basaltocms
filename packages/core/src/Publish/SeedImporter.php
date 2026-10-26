<?php

declare(strict_types=1);

namespace Basalto\Core\Publish;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\RichText;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Property;
use Basalto\Lava\Structure\Value;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Startinhalte aus `seed` (L-11, CR-002 W-16): Seiten mit Titel, Slug, Template und Blöcken je Sprache, globale Inhalte
 * (Fuss) und Inhalte eigener Typen (`person hoang { … }`) — veröffentlicht, damit die Site nach `basalto:publish` ohne
 * Handarbeit steht. **Idempotent:** Jeder Eintrag trägt eine feste UUID aus Site und Seed-Name; was schon da ist, bleibt
 * unangetastet (auch was die Redaktion geändert oder in den Papierkorb gelegt hat), fehlende Sprachen kommen dazu.
 */
final class SeedImporter
{
    private bool $refresh = false;

    /** Schema des Themes während eines Imports — für Blocklisten in Inhalten eigener Typen. */
    private ?ThemeSchema $schema = null;

    public function __construct(private readonly ThemeLoader $themes, private readonly Pages $pages) {}

    /**
     * @param  bool  $refresh  Einträge, die noch genau so sind, wie der Seed sie angelegt hat (alle Fassungen ohne
     *                         Autor), neu einspielen — so kommen Änderungen am Seed eines Themes auf bestehende Sites.
     *                         Was jemand im Backend bearbeitet hat, bleibt.
     * @return array{created: list<string>, kept: list<string>} lesbare Zeilen für Konsole und Protokoll
     */
    public function import(Site $site, bool $refresh = false): array
    {
        $this->refresh = $refresh;
        $theme = $this->themes->forSite($site);
        $seed = $theme->seed();
        $result = ['created' => [], 'kept' => []];
        if ($seed === null) {
            return $result;
        }
        $schema = $this->schema = ThemeSchema::fromTheme($theme);
        $locales = $site->localeCodes();
        $pages = $seed->childrenOf('page');
        $slugs = [];
        foreach ($pages as $page) {
            foreach ($locales as $locale) {
                $slugs[(string) $page->name][$locale] = $this->slugOf($page, $locale, $site->default_locale);
                $slugs[(string) $page->name]['title:'.$locale] = $this->text($page, 'title', $locale, $site->default_locale) ?? $this->text($page, 'title', $site->default_locale, $site->default_locale) ?? ucfirst((string) $page->name);
            }
        }
        // Pfad je Seite mit den Elternseiten davor («funktionen/seiten») — Ziel für Links auf Unterseiten
        $parents = [];
        foreach ($pages as $page) {
            $parents[(string) $page->name] = $page->value('parent') === null ? null : (string) $page->value('parent');
        }
        foreach ($pages as $page) {
            foreach ($locales as $locale) {
                $slugs[(string) $page->name]['path:'.$locale] = self::pathOf((string) $page->name, $locale, $slugs, $parents);
            }
        }
        foreach ($pages as $position => $page) {
            // Seitentyp (`type: feed`), Feed-Einstellungen (`feed: post`, `format: atom`) und Elternseite (`parent: blog`)
            $pageType = PageType::tryFrom((string) $page->value('type')) ?? PageType::Page;
            $settings = $page->value('template') !== null ? ['layout' => ['mode' => 'own', 'template' => $page->value('template'), 'children' => 'inherit']] : [];
            if ($pageType === PageType::Feed) {
                $settings['feed'] = ['type' => $page->value('feed') ?? 'page', 'limit' => 20, 'format' => $page->value('format') === 'atom' ? 'atom' : 'rss'];
            }
            // Weiterleitung nach aussen (`type: redirect  redirect: -> "https://…"`), Ziel je Sprache mit `redirect.en: -> "…"`
            $redirect = $pageType === PageType::Redirect ? self::redirectOf($page, $locales, $site->default_locale) : null;
            $entry = $this->entry($site, 'page', $pageType->value, (string) $page->name, $position, $settings, $redirect);
            // Beim Auffrischen folgt eine unbearbeitete Seite einem geänderten Typ oder Ziel des Seeds
            if ($entry !== null && $this->refresh && ($entry->type !== $pageType->value || $entry->redirect_to != $redirect)
                && ! $entry->versions()->whereNotNull('author_id')->exists()) {
                $entry->forceFill(['type' => $pageType->value, 'redirect_to' => $redirect])->save();
            }
            $parent = $page->value('parent');
            if ($entry !== null && $parent !== null && $entry->parent_id === null) {
                $parentId = Entry::query()->withoutGlobalScopes()->where('uuid', Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:page:%s', $site->id, $parent))->toString())->value('id');
                if ($parentId !== null) {
                    $entry->forceFill(['parent_id' => $parentId])->save();
                }
            }
            if ($entry === null) {
                $result['kept'][] = sprintf('Seite «%s» (im Papierkorb — bleibt dort)', $page->name);

                continue;
            }
            if ($page->value('slug') === '/' && ! is_numeric($site->setting('home.'.$site->default_locale))) {
                $settings = $site->settings ?? [];
                foreach ($locales as $locale) {
                    $settings['home'][$locale] = $entry->id;
                }
                $site->forceFill(['settings' => $settings])->save();
            }
            foreach ($locales as $locale) {
                $title = $this->text($page, 'title', $locale, $site->default_locale);
                if ($title === null) {
                    continue;
                }
                $label = sprintf('Seite «%s» (%s)', $page->name, strtoupper($locale));
                if (! $this->untouched($entry, $locale)) {
                    $result['kept'][] = $label;

                    continue;
                }
                $content = [];
                $contentBlock = $page->childrenOf('content')[0] ?? null;
                foreach ($contentBlock === null ? [] : $contentBlock->children as $use) {
                    $content[] = ['type' => $use->kind, 'data' => $this->values($use, $locale, $site->default_locale, $schema->blocks[$use->kind]['fields'] ?? [], $slugs, $site)];
                }
                $this->publish($entry, $locale, ['title' => $title, 'slug' => $slugs[(string) $page->name][$locale] ?? Str::slug($title), 'intro' => $this->text($page, 'intro', $locale, $site->default_locale), 'content' => $content]);
                $result['created'][] = $label;
            }
        }
        foreach ($seed->children as $item) {
            if ($item->kind === 'page' || $item->name === null) {
                continue;
            }
            $definition = $item->kind === 'global' ? $theme->global((string) $item->name) : $theme->document->find('type', $item->kind);
            if ($definition === null) {
                continue;
            }
            $type = $item->kind === 'global' ? (string) $item->name : $item->kind;
            $entry = $this->entry($site, $item->kind, $type, (string) $item->name, 0, []);
            if ($entry === null) {
                continue;
            }
            $fields = ThemeSchema::fieldsOf($definition);
            foreach ($locales as $locale) {
                $label = sprintf('%s «%s» (%s)', $item->kind === 'global' ? 'Global' : ucfirst($item->kind), $item->name, strtoupper($locale));
                if (! $this->untouched($entry, $locale)) {
                    $result['kept'][] = $label;

                    continue;
                }
                if ($locale !== $site->default_locale && ! $this->hasLanguage($item, $locale)) {
                    continue;
                }
                $data = $this->values($item, $locale, $site->default_locale, $fields, $slugs, $site);
                $title = (string) ($data['title'] ?? $data['name'] ?? $data['claim'] ?? $item->name);
                $this->publish($entry, $locale, ['title' => $title, 'slug' => Str::slug((string) $item->name)] + $data);
                $result['created'][] = $label;
            }
        }
        $this->pages->forget();

        return $result;
    }

    /**
     * Ob die Sprache des Eintrags frei für den Seed ist: ohne Fassung — oder beim Auffrischen nur mit Fassungen aus
     * dem Seed (ohne Autor); die werden dann entfernt und neu angelegt.
     */
    private function untouched(Entry $entry, string $locale): bool
    {
        $versions = $entry->versions()->where('locale', $locale);
        if (! (clone $versions)->exists()) {
            return true;
        }
        if (! $this->refresh || (clone $versions)->whereNotNull('author_id')->exists()) {
            return false;
        }
        $versions->delete();

        return true;
    }

    /**
     * Der Eintrag zum Seed-Namen — neu angelegt, vorhanden, oder null, wenn er im Papierkorb liegt.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $redirect
     */
    private function entry(Site $site, string $kind, string $type, string $name, int $position, array $settings, ?array $redirect = null): ?Entry
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:%s:%s', $site->id, $kind, $name))->toString();
        $entry = Entry::query()->withoutGlobalScopes()->withTrashed()->where('uuid', $uuid)->first();
        if ($entry?->trashed()) {
            return null;
        }

        return $entry ?? Entry::query()->create(['site_id' => $site->id, 'type' => $type, 'uuid' => $uuid, 'position' => $position, 'settings' => $settings === [] ? null : $settings, 'redirect_to' => $redirect]);
    }

    /**
     * Ziel einer Weiterleitung aus dem Seed: die Adresse der Hauptsprache und eigene Adressen weiterer Sprachen.
     *
     * @param  list<string>  $locales
     * @return array{kind: string, entry_id: null, url: string, urls: array<string, string>, status: int}
     */
    private static function redirectOf(Definition $page, array $locales, string $default): array
    {
        $target = static function (?Property $property): ?string {
            $values = $property === null ? [] : $property->values;
            $value = ($values[0] ?? null)?->type === Value::ARROW ? ($values[1] ?? null) : ($values[0] ?? null);

            return $value === null ? null : $value->text();
        };
        $url = $target($page->property('redirect', $default)) ?? $target($page->property('redirect')) ?? '';
        $urls = [];
        foreach ($locales as $locale) {
            $own = $locale === $default ? null : $target($page->property('redirect', $locale));
            if ($own !== null && $own !== '') {
                $urls[$locale] = $own;
            }
        }

        return ['kind' => 'external', 'entry_id' => null, 'url' => $url, 'urls' => $urls, 'status' => 302];
    }

    /** @param  array<string, mixed>  $data */
    private function publish(Entry $entry, string $locale, array $data): EntryVersion
    {
        $version = $entry->newDraft($locale, $data);
        $version->forceFill(['status' => EntryStatus::Published, 'published_at' => now()])->save();

        return $version;
    }

    /**
     * Werte eines Seed-Elements für eine Sprache: Sprachfassung vor Grundwert, Pfeile als Links auf die Seed-Seite in
     * derselben Sprache, Zeilen (`items { { … } { … } }`) als Liste, HTML in Fliesstextfeldern als Absätze.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, array<string, string>>  $slugs  Seed-Seite → Slug je Sprache und «title:<sprache>»
     * @return array<string, mixed>
     */
    private function values(Definition $def, string $locale, string $default, array $fields, array $slugs, Site $site): array
    {
        $types = array_column($fields, 'type', 'name');
        $data = [];
        foreach ([null, $locale] as $lang) {
            foreach ($def->properties as $property) {
                if ($property->lang !== $lang || ($lang === null && $property->lang !== null)) {
                    continue;
                }
                $data[$property->key] = $this->value($property, $types[$property->key] ?? null, $locale, $slugs, $site);
            }
        }
        foreach ($def->children as $child) {
            $blocksField = array_values(array_filter($fields, static fn (array $f): bool => ($f['name'] ?? null) === $child->kind && ($f['type'] ?? null) === 'blocks'));
            if ($blocksField !== [] && $this->schema !== null) {
                $data[$child->kind] = array_map(fn (Definition $use): array => ['type' => $use->kind, 'data' => $this->values($use, $locale, $default, $this->schema->blocks[$use->kind]['fields'] ?? [], $slugs, $site)], $child->children);

                continue;
            }
            $rowFields = [];
            foreach ($fields as $field) {
                if (($field['name'] ?? null) === $child->kind && is_array($field['fields'] ?? null)) {
                    $rowFields = array_values(array_filter($field['fields'], 'is_array'));
                }
            }
            $data[$child->kind] = array_map(fn (Definition $row): array => $this->values($row, $locale, $default, $rowFields, $slugs, $site), $child->children);
        }

        return $data;
    }

    /** @param  array<string, array<string, string>>  $slugs */
    private function value(Property $property, ?string $type, string $locale, array $slugs, Site $site): mixed
    {
        $values = $property->values;
        if (($values[0] ?? null)?->type === Value::ARROW && isset($values[1])) {
            $target = $values[1];
            if ($target->type !== Value::REF) {
                return ['href' => $target->text(), 'label' => $target->text()];
            }
            $slug = $slugs[$target->text()]['path:'.$locale] ?? $slugs[$target->text()][$locale] ?? $target->text();
            $path = $this->pages->urlPath($site, $locale, $slug === '/' ? '/' : '/'.$slug);

            return ['href' => $path, 'label' => $slugs[$target->text()]['title:'.$locale] ?? ucfirst($target->text())];
        }
        if (count($values) === 1) {
            $raw = $values[0]->value;
            if ($type === 'richtext' && is_string($raw) && str_contains($raw, '<')) {
                return RichText::fromHtml($raw);
            }

            return $raw;
        }

        return $values === [] ? null : array_map(static fn (Value $v): string => $v->text(), $values);
    }

    /**
     * @param  array<string, array<string, string>>  $slugs
     * @param  array<string, string|null>  $parents
     * @param  list<string>  $seen
     */
    private static function pathOf(string $name, string $locale, array $slugs, array $parents, array $seen = []): string
    {
        $slug = $slugs[$name][$locale] ?? $name;
        $parent = $parents[$name] ?? null;
        if ($parent === null || ! isset($slugs[$parent]) || in_array($parent, $seen, true)) {
            return $slug;
        }
        $base = self::pathOf($parent, $locale, $slugs, $parents, [...$seen, $name]);

        return $base === '/' ? $slug : $base.'/'.$slug;
    }

    private function slugOf(Definition $page, string $locale, string $default): string
    {
        $slug = $page->property('slug', $locale)?->text() ?? ($locale === $default ? $page->value('slug') : null);
        if ($slug === '/') {
            return '/';
        }
        if ($slug !== null && $slug !== '') {
            return trim($slug, '/');
        }

        return $locale === $default ? (string) $page->name : Str::slug($this->text($page, 'title', $locale, $default) ?? (string) $page->name);
    }

    private function text(Definition $def, string $key, string $locale, string $default): ?string
    {
        $value = $def->property($key, $locale)?->text() ?? ($locale === $default ? $def->value($key) : null);

        return $value === null || $value === '' ? null : $value;
    }

    private function hasLanguage(Definition $def, string $locale): bool
    {
        foreach ($def->properties as $property) {
            if ($property->lang === $locale) {
                return true;
            }
        }

        return false;
    }
}
