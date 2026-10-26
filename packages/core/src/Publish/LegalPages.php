<?php

declare(strict_types=1);

namespace Basalto\Core\Publish;

use Basalto\Core\Content\Pages;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Forms\FormCatalog;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Rechtsseite aus Vorlage (M-14): findet die Seite aus `publish.legal` (Seed) oder legt sie an, setzt ein, was Basalto
 * weiss — Firma (Name der Site), Website, E-Mail (erster Formular-Empfänger), Adresse (Fuss), Aufbewahrung, Datum —
 * und schreibt je Sprache der Site einen **Entwurf**. Offene Platzhalter `[[…]]` füllt die Redaktion; erst dann geht
 * die Seite durch `basalto:publish`.
 */
final class LegalPages
{
    public function __construct(private readonly ThemeLoader $themes, private readonly Pages $pages, private readonly FormCatalog $forms, private readonly Settings $settings) {}

    public function apply(Site $site, string $kind, ?int $authorId = null): Entry
    {
        $theme = $this->themes->forSite($site);
        $name = $theme->document->find('publish')?->childrenOf('legal')[0]?->value($kind) ?? $kind;
        $entry = $this->target($site, $name);
        if ($entry === null) {
            $position = (int) Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('parent_id')->max('position') + 1;
            $template = $theme->template('legal') !== null ? ['layout' => ['mode' => 'own', 'template' => 'legal', 'children' => 'inherit']] : null;
            $entry = Entry::query()->create(['site_id' => $site->id, 'type' => 'page', 'position' => $position, 'settings' => $template,
                'uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:page:%s', $site->id, $name))->toString(), 'created_by' => $authorId]);
        }
        $schema = ThemeSchema::fromTheme($theme);
        $blockType = $this->richtextBlock($schema);
        foreach ($site->localeCodes() as $locale) {
            $meta = LegalTemplates::meta($kind, $locale);
            $latest = $entry->latestVersion($locale);
            $doc = $this->doc(LegalTemplates::sections($kind, $locale), $this->known($site, $locale));
            $content = $blockType === null ? [] : [['type' => $blockType[0], 'data' => [$blockType[1] => $doc]]];
            $entry->saveDraft($locale, ['title' => $latest !== null ? $latest->title : $meta['title'], 'slug' => $latest !== null ? $latest->slug : ($locale === $site->default_locale ? Str::slug($name) : $meta['slug']),
                'intro' => null, 'content' => $content, 'settings' => is_array($latest?->data['settings'] ?? null) ? $latest->data['settings'] : []], $authorId);
        }
        $this->pages->forget();

        return $entry;
    }

    /**
     * Ist die Rechtsseite leer (ohne Blöcke und Einleitung) — für die Warnung in publish.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isEmpty(array $data): bool
    {
        return ($data['content'] ?? []) === [] && trim((string) (is_string($data['intro'] ?? null) ? $data['intro'] : '')) === '' && ! is_array($data['intro'] ?? null);
    }

    private function target(Site $site, string $name): ?Entry
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:page:%s', $site->id, $name))->toString();
        $entry = Entry::query()->withoutGlobalScopes()->where('uuid', $uuid)->whereNull('deleted_at')->first();
        if ($entry !== null) {
            return $entry;
        }
        $id = $this->pages->entryIdAt($site, $site->default_locale, '/'.trim($name, '/'), false);

        return $id === null ? null : Entry::query()->withoutGlobalScopes()->find($id);
    }

    /** @return array{0: string, 1: string}|null Block und Fliesstextfeld, in das die Vorlage kommt */
    private function richtextBlock(ThemeSchema $schema): ?array
    {
        foreach (['text', ...array_keys($schema->blocks)] as $name) {
            foreach ($schema->blocks[$name]['fields'] ?? [] as $field) {
                if ($field['type'] === 'richtext') {
                    return [$name, $field['name']];
                }
            }
        }

        return null;
    }

    /** @return array<string, string> Platzhalter → Wert, nur was Basalto sicher weiss */
    private function known(Site $site, string $locale): array
    {
        $host = SiteDomain::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderByDesc('is_primary')->value('host');
        $email = null;
        $retain = (int) $this->settings->get('forms.retention_days', 90, $site);
        foreach ($this->forms->all($site) as $form) {
            $email ??= $form->recipients()[0] ?? null;
            if ($form->handle === 'contact') {
                $retain = $form->retainDays($retain);
            }
        }
        $address = null;
        $footer = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('type', 'footer')->first()?->resolve($locale);
        if (is_string($footer?->data['address'] ?? null) && $footer->data['address'] !== '') {
            $address = $footer->data['address'];
        }

        return array_filter(['Firma' => $site->name, 'Website' => is_string($host) ? $host : null, 'E-Mail' => $email, 'Adresse' => $address, 'Aufbewahrung' => (string) $retain,
            'Datum' => now($site->timezone)->format($locale === 'en' ? 'j F Y' : 'd.m.Y')], static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /**
     * @param  list<array{0: string|null, 1: list<string>}>  $sections
     * @param  array<string, string>  $known
     * @return array<string, mixed>
     */
    private function doc(array $sections, array $known): array
    {
        $fill = static fn (string $text): string => (string) preg_replace_callback('/\[\[([^\]]+)\]\]/u', static fn (array $m): string => $known[$m[1]] ?? $m[0], $text);
        $content = [];
        foreach ($sections as [$heading, $paragraphs]) {
            if ($heading !== null) {
                $content[] = ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $heading]]];
            }
            foreach ($paragraphs as $paragraph) {
                $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $fill($paragraph)]]];
            }
        }

        return ['type' => 'doc', 'content' => $content];
    }
}
