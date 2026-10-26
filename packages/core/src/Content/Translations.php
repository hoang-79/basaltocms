<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Support\Arr;

/**
 * Übersetzung (M-06): Stand je Sprachfassung und Feldpaare Quelle ↔ Ziel.
 *
 * Die Quelle ist die neueste Fassung der Standardsprache. Eine Übersetzung merkt sich, auf welcher Quellfassung sie
 * beruht (`source_version_id`); «veraltet» ist sie, sobald sich seither ein **übersetzbares** Feld der Quelle geändert
 * hat — ein neues Bild allein macht keine Arbeit. Übersetzbar sind Text, Textfeld und Fliesstext mit `translatable`
 * im Theme, dazu Titel, Einleitung und Metadaten; alles andere ist geteilt und folgt der Quelle.
 *
 * @phpstan-type Pair array{path: string, label: string, type: string, translatable: bool}
 * @phpstan-type State 'source'|'missing'|'outdated'|'current'
 */
final class Translations
{
    public const SOURCE = 'source';

    public const MISSING = 'missing';

    public const OUTDATED = 'outdated';

    public const CURRENT = 'current';

    /** Feldtypen, die Text tragen und übersetzt werden. */
    private const TEXT_TYPES = ['text', 'textarea', 'richtext', 'markdown'];

    /** @var array<string, array<string, string|null>> übersetzbare Werte je «Fassung:Stand» */
    private array $values = [];

    /** @var array<int, ThemeSchema|null> */
    private array $schemas = [];

    public function __construct(private readonly ThemeLoader $themes) {}

    /**
     * Stand einer Sprache — die Versionen des Inhalts dürfen schon geladen sein (Listen laden sie in einem Zug).
     *
     * @return State
     */
    public function state(Entry $entry, string $locale): string
    {
        return $this->stateDetail($entry, $locale)['state'];
    }

    /**
     * Stand mit Begründung: seit welcher Quellfassung veraltet, wie viele Felder geändert.
     *
     * @return array{state: State, since: EntryVersion|null, changed: int}
     */
    public function stateDetail(Entry $entry, string $locale): array
    {
        $site = $entry->site;
        if ($locale === $site->default_locale) {
            return ['state' => self::SOURCE, 'since' => null, 'changed' => 0];
        }
        $versions = $entry->versions;
        $target = $versions->where('locale', $locale)->sortByDesc('number')->first();
        if ($target === null) {
            return ['state' => self::MISSING, 'since' => null, 'changed' => 0];
        }
        $sources = $versions->where('locale', $site->default_locale)->sortBy('number')->values();
        $source = $sources->last();
        if ($source === null) {
            return ['state' => self::CURRENT, 'since' => null, 'changed' => 0];
        }
        $base = $target->source_version_id === null ? null : $versions->firstWhere('id', $target->source_version_id);
        if ($base === null) {
            // Ohne Quelle (vor TB 04 angelegt): veraltet, wenn die Standardsprache danach eine Fassung bekam
            $newer = $sources->first(static fn (EntryVersion $v): bool => ($v->created_at?->getTimestamp() ?? 0) > ($target->created_at?->getTimestamp() ?? 0));

            return $newer === null ? ['state' => self::CURRENT, 'since' => null, 'changed' => 0] : ['state' => self::OUTDATED, 'since' => $newer, 'changed' => 0];
        }
        $schema = $this->schema($site);
        $baseValues = $this->translatableValues($entry, $base, $schema);
        $sourceValues = $this->translatableValues($entry, $source, $schema);
        $changed = $this->changedPaths($baseValues, $sourceValues);
        if ($changed === []) {
            // Gleiche Quelle, aber Felder ohne Text in der Zielsprache: noch nicht fertig übersetzt
            $targetData = ['title' => $target->title] + $target->data;
            foreach ($sourceValues as $path => $value) {
                if ($value !== null && self::normalize(Arr::get($targetData, $path)) === null) {
                    return ['state' => self::OUTDATED, 'since' => null, 'changed' => 0];
                }
            }

            return ['state' => self::CURRENT, 'since' => null, 'changed' => 0];
        }
        $since = $sources->first(fn (EntryVersion $v): bool => $v->number > $base->number && $this->changedPaths($baseValues, $this->translatableValues($entry, $v, $schema)) !== []);

        return ['state' => self::OUTDATED, 'since' => $since ?? $source, 'changed' => count($changed)];
    }

    /**
     * Stand je Sprache der Site.
     *
     * @return array<string, State>
     */
    public function states(Entry $entry): array
    {
        $out = [];
        foreach ($entry->site->localeCodes() as $locale) {
            $out[$locale] = $this->state($entry, $locale);
        }

        return $out;
    }

    /**
     * Die Felder eines Inhalts in Abschnitten — Seite, Metadaten, je Block einer — mit Pfad in die Fassungsdaten.
     *
     * @return list<array{key: string, title: string, subtitle: string|null, fields: list<Pair>}>
     */
    public function layout(Entry $entry, EntryVersion $source): array
    {
        $schema = $this->schema($entry->site);
        $sections = [];
        $page = [['path' => 'title', 'label' => (string) __('Titel'), 'type' => 'text', 'translatable' => true]];
        $typeFields = $schema?->types[$entry->type]['fields'] ?? [];
        $hasIntro = false;
        foreach ($typeFields as $field) {
            if (in_array($field['name'], ['title', 'slug'], true) || in_array($field['type'], ['blocks', 'seo', 'slug'], true)) {
                continue;
            }
            $hasIntro = $hasIntro || $field['name'] === 'intro';
            $page = array_merge($page, $this->fieldPairs($field, $source->data, $field['name'], $field['label'], $schema));
        }
        if (! $hasIntro && (PageType::isPageType($entry->type) || array_key_exists('intro', $source->data))) {
            $page[] = ['path' => 'intro', 'label' => (string) __('Einleitung'), 'type' => 'richtext', 'translatable' => true];
        }
        $sections[] = ['key' => 'page', 'title' => PageType::isPageType($entry->type) ? (string) __('Seite') : ($schema?->types[$entry->type]['label'] ?? (string) __('Inhalt')), 'subtitle' => null, 'fields' => $page];
        if (PageType::isPageType($entry->type)) {
            $sections[] = ['key' => 'meta', 'title' => (string) __('Metadaten'), 'subtitle' => (string) __('Suchergebnis'), 'fields' => [
                ['path' => 'settings.meta.title', 'label' => (string) __('Seitentitel'), 'type' => 'text', 'translatable' => true],
                ['path' => 'settings.meta.description', 'label' => (string) __('Beschreibung'), 'type' => 'textarea', 'translatable' => true],
            ]];
        }
        $blocks = is_array($source->data['content'] ?? null) ? array_values($source->data['content']) : [];
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $def = $schema?->blocks[$type] ?? null;
            $fields = [];
            foreach ($def['fields'] ?? [] as $field) {
                $fields = array_merge($fields, $this->fieldPairs($field, is_array($block['data'] ?? null) ? $block['data'] : [], "content.$i.data.".$field['name'], $field['label'], $schema));
            }
            $sections[] = ['key' => "content.$i", 'title' => (string) __('Block :number · :type', ['number' => $i + 1, 'type' => $def['label'] ?? $type]), 'subtitle' => $type, 'fields' => $fields];
        }

        return $sections;
    }

    /**
     * Eine Übersetzung speichern: Struktur und geteilte Felder aus der Quelle, übersetzbare Felder aus den Werten
     * (fehlt ein Wert, bleibt der bisherige der Zielsprache); Slug der Zielsprache bleibt, sonst aus dem Titel.
     *
     * @param  array<string, mixed>  $values  Pfad → Wert
     * @return array<string, mixed>
     */
    public function compose(Entry $entry, EntryVersion $source, ?EntryVersion $target, array $values): array
    {
        $data = $source->data;
        $data['title'] = $source->title;
        $previous = $target === null ? [] : ['title' => $target->title] + $target->data;
        foreach ($this->pairs($entry, $source) as $pair) {
            if (! $pair['translatable']) {
                continue;
            }
            $value = array_key_exists($pair['path'], $values) ? $values[$pair['path']] : Arr::get($previous, $pair['path']);
            Arr::set($data, $pair['path'], self::clean($pair['type'], $value));
        }
        // Der Platzhalter-Slug des leeren Entwurfs («leistungen-en») weicht dem übersetzten Titel; ein eigener bleibt
        $placeholder = $target === null || $target->slug === $source->slug.'-'.$target->locale || $target->slug === $source->slug;
        $data['slug'] = $placeholder ? str((string) $data['title'])->slug()->toString() : $target->slug;

        return $data;
    }

    /**
     * Die Daten einer neuen, leeren Übersetzung: Struktur der Quelle, übersetzbare Felder leer — nur der Titel bleibt,
     * damit Liste und Baum etwas zeigen.
     *
     * @return array<string, mixed>
     */
    public function scaffold(Entry $entry, EntryVersion $source, string $locale): array
    {
        $data = $source->data;
        foreach ($this->pairs($entry, $source) as $pair) {
            if ($pair['translatable'] && $pair['path'] !== 'title' && Arr::has($data, $pair['path'])) {
                Arr::set($data, $pair['path'], $pair['type'] === 'richtext' ? null : '');
            }
        }

        return ['title' => $source->title, 'slug' => $source->slug.'-'.$locale] + $data;
    }

    /**
     * Alle Feldpaare flach.
     *
     * @return list<Pair>
     */
    public function pairs(Entry $entry, EntryVersion $source): array
    {
        $out = [];
        foreach ($this->layout($entry, $source) as $section) {
            foreach ($section['fields'] as $field) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * Werte der übersetzbaren Felder einer Fassung (normalisiert: Fliesstext als Text).
     *
     * @return array<string, string|null>
     */
    public function translatableValues(Entry $entry, EntryVersion $version, ?ThemeSchema $schema = null): array
    {
        // Je Fassung einmal — Quelle und Basis sind meist dieselbe Fassung (N-03: Seitenbaum mit 1'000 Übersetzungen)
        $key = $version->id.':'.($version->updated_at?->getTimestamp() ?? 0);
        if ($version->exists && isset($this->values[$key])) {
            return $this->values[$key];
        }
        $data = ['title' => $version->title] + $version->data;
        $out = [];
        foreach ($this->pairs($entry, $version) as $pair) {
            if ($pair['translatable']) {
                $out[$pair['path']] = self::normalize(Arr::get($data, $pair['path']));
            }
        }

        return $version->exists ? $this->values[$key] = $out : $out;
    }

    /**
     * Pfade, deren Wert in der neuen Quelle anders ist als in der alten (neu hinzugekommene zählen mit).
     *
     * @param  array<string, string|null>  $old
     * @param  array<string, string|null>  $new
     * @return list<string>
     */
    public function changedPaths(array $old, array $new): array
    {
        $changed = [];
        foreach ($new as $path => $value) {
            if (($old[$path] ?? null) !== $value && ! ($value === null && ! array_key_exists($path, $old))) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    public static function normalize(mixed $value): ?string
    {
        if (is_array($value) && ($value['type'] ?? null) === 'doc') {
            $text = RichText::toText($value);

            return $text === '' ? null : $text;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $data
     * @return list<Pair>
     */
    private function fieldPairs(array $field, array $data, string $path, string $label, ?ThemeSchema $schema): array
    {
        $type = (string) $field['type'];
        if ($type === 'repeater') {
            $out = [];
            $rows = is_array($data[$field['name']] ?? null) ? array_values($data[$field['name']]) : [];
            foreach ($rows as $r => $row) {
                foreach (is_array($field['fields'] ?? null) ? $field['fields'] : [] as $sub) {
                    if (is_array($sub)) {
                        $out = array_merge($out, $this->fieldPairs($sub, is_array($row) ? $row : [], "$path.$r.".$sub['name'], sprintf('%s %d · %s', $label, $r + 1, $sub['label']), $schema));
                    }
                }
            }

            return $out;
        }
        if ($type === 'blocks') {
            $out = [];
            $blocks = is_array($data[$field['name']] ?? null) ? array_values($data[$field['name']]) : [];
            foreach ($blocks as $b => $block) {
                if (! is_array($block)) {
                    continue;
                }
                $def = $schema?->blocks[(string) ($block['type'] ?? '')] ?? null;
                foreach ($def['fields'] ?? [] as $sub) {
                    $out = array_merge($out, $this->fieldPairs($sub, is_array($block['data'] ?? null) ? $block['data'] : [], "$path.$b.data.".$sub['name'], sprintf('%s · %s · %s', $label, $def['label'], $sub['label']), $schema));
                }
            }

            return $out;
        }

        return [['path' => $path, 'label' => $label, 'type' => $type, 'translatable' => (bool) $field['translatable'] && in_array($type, self::TEXT_TYPES, true)]];
    }

    private static function clean(string $type, mixed $value): mixed
    {
        if ($type === 'richtext') {
            $doc = RichText::doc($value);

            return $doc !== null && RichText::toText($doc) !== '' ? $doc : null;
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function schema(Site $site): ?ThemeSchema
    {
        if (! array_key_exists($site->id, $this->schemas)) {
            try {
                $this->schemas[$site->id] = ThemeSchema::fromTheme($this->themes->forSite($site));
            } catch (ThemeException) {
                $this->schemas[$site->id] = null;
            }
        }

        return $this->schemas[$site->id];
    }
}
