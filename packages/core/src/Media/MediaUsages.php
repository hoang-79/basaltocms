<?php

declare(strict_types=1);

namespace Basalto\Core\Media;

use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\MediaUsage;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;

/**
 * Verwendungsnachweis (K-08) und Alt-Text-Prüfung (K-10): findet die Medien einer Sprachfassung über das Schema des
 * Themes — Bild-, Galerie- und Dateifelder, Bilder im Fliesstext, Repeater-Zeilen, verschachtelte Blöcke und das
 * Seitenbild aus den Einstellungen — und führt `media_usages` bei jedem Speichern nach.
 */
final class MediaUsages
{
    public function __construct(private readonly ThemeLoader $themes) {}

    /** Die Zeilen der Fassung neu schreiben (eine je Medium und Feld). */
    public function sync(Entry $entry, EntryVersion $version): void
    {
        $found = $this->collect($entry, $version);
        MediaUsage::query()->where('entry_id', $entry->id)->where('locale', $version->locale)->delete();
        $rows = [];
        $now = now();
        foreach ($found as $mediaId => $fields) {
            foreach ($fields as $field) {
                $rows[] = ['media_id' => $mediaId, 'entry_id' => $entry->id, 'locale' => $version->locale, 'field' => $field, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows !== []) {
            MediaUsage::query()->insert($rows);
        }
    }

    /**
     * Bilder der Fassung ohne Alt-Text in ihrer Sprache — Feld-Alt zählt, sonst der Alt-Text der Mediathek.
     *
     * @return list<string> Dateinamen
     */
    public function missingAltTexts(EntryVersion $version): array
    {
        $entry = $version->entry;
        $found = $this->collect($entry, $version);
        if ($found === []) {
            return [];
        }
        $covered = $this->fieldAltCovered($entry, $version);
        $missing = [];
        foreach (Media::query()->withoutGlobalScopes()->withTrashed()->whereKey(array_keys($found))->get() as $media) {
            if (! $media->isImage() || $media->altFor($version->locale) !== null) {
                continue;
            }
            $open = array_diff($found[$media->id] ?? [], $covered[$media->id] ?? []);
            if ($open !== []) {
                $missing[] = $media->name;
            }
        }
        sort($missing);

        return $missing;
    }

    /**
     * Medium → Felder, in denen es vorkommt («content.2.image», «fields.cover», «settings.image», «content.0.text»).
     *
     * @return array<int, list<string>>
     */
    public function collect(Entry $entry, EntryVersion $version): array
    {
        $found = [];
        $data = $version->data;
        try {
            $schema = ThemeSchema::fromTheme($this->themes->forSite($entry->site));
            $typeFields = $schema->types[$entry->type]['fields'] ?? [];
            $this->walk($typeFields, $data, 'fields', $schema, $found);
            if (($schema->types[$entry->type] ?? null) === null || ! self::hasBlocksField($typeFields)) {
                $this->blocks(is_array($data['content'] ?? null) ? array_values($data['content']) : [], 'content', $schema, $found);
            }
        } catch (ThemeException) {
            $this->scan($data, 'data', $found);
        }
        $pageImage = $entry->setting('image.media_id');
        if (is_numeric($pageImage) && (int) $pageImage > 0) {
            $found[(int) $pageImage][] = 'settings.image';
        }
        foreach ($found as $id => $fields) {
            $found[$id] = array_values(array_unique($fields));
        }

        return $found;
    }

    /**
     * @param  list<mixed>  $fields
     * @param  array<string, mixed>  $data
     * @param  array<int, list<string>>  $found
     */
    private function walk(array $fields, array $data, string $prefix, ThemeSchema $schema, array &$found): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = (string) $field['name'];
            $value = $data[$name] ?? null;
            $path = $prefix === 'fields' && in_array($name, ['content'], true) ? $name : $prefix.'.'.$name;
            switch ((string) $field['type']) {
                case 'image':
                case 'file':
                    $this->add(self::idOf($value), $path, $found);
                    break;
                case 'gallery':
                    foreach (is_array($value) ? $value : [] as $item) {
                        $this->add(self::idOf($item), $path, $found);
                    }
                    break;
                case 'richtext':
                    if (is_array($value)) {
                        $this->richtext($value, $path, $found);
                    }
                    break;
                case 'repeater':
                    foreach (is_array($value) ? array_values($value) : [] as $i => $row) {
                        $this->walk(is_array($field['fields'] ?? null) ? array_values($field['fields']) : [], is_array($row) ? $row : [], $path.'.'.$i, $schema, $found);
                    }
                    break;
                case 'blocks':
                    $this->blocks(is_array($value) ? array_values($value) : [], $path, $schema, $found);
                    break;
            }
        }
    }

    /**
     * @param  list<mixed>  $blocks
     * @param  array<int, list<string>>  $found
     */
    private function blocks(array $blocks, string $prefix, ThemeSchema $schema, array &$found): void
    {
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            if (isset($schema->blocks[$type])) {
                $this->walk($schema->blocks[$type]['fields'], $data, $prefix.'.'.$i, $schema, $found);
            } else {
                $this->scan($data, $prefix.'.'.$i, $found);
            }
        }
    }

    /**
     * Bildknoten des Fliesstexts (`mediaId`), rekursiv.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, list<string>>  $found
     */
    private function richtext(array $node, string $path, array &$found): void
    {
        if (($node['type'] ?? null) === 'image' && is_array($node['attrs'] ?? null)) {
            $this->add(self::idOf($node['attrs']['mediaId'] ?? null), $path, $found);
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $this->richtext($child, $path, $found);
            }
        }
    }

    /**
     * Ohne Schema (kaputtes Theme): alles, was wie ein Bildwert aussieht — `mediaId` im Fliesstext, `{id, alt}`-Paare.
     *
     * @param  array<mixed>  $data
     * @param  array<int, list<string>>  $found
     */
    private function scan(array $data, string $path, array &$found): void
    {
        if (isset($data['mediaId'])) {
            $this->add(self::idOf($data['mediaId']), $path, $found);
        }
        if (isset($data['id'], $data['alt']) && count($data) <= 4) {
            $this->add(self::idOf($data['id']), $path, $found);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->scan($value, $path.'.'.$key, $found);
            }
        }
    }

    /** @param  array<int, list<string>>  $found */
    private function add(?int $id, string $path, array &$found): void
    {
        if ($id !== null && $id > 0) {
            $found[$id][] = $path;
        }
    }

    private static function idOf(mixed $value): ?int
    {
        $id = is_array($value) ? ($value['id'] ?? $value['media_id'] ?? null) : $value;

        return is_numeric($id) ? (int) $id : null;
    }

    /** @param  list<mixed>  $fields */
    private static function hasBlocksField(array $fields): bool
    {
        foreach ($fields as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'blocks') {
                return true;
            }
        }

        return false;
    }

    /**
     * Felder, in denen ein eigener Alt-Text im Feldwert steht — die gelten als versorgt.
     *
     * @return array<int, list<string>>
     */
    private function fieldAltCovered(Entry $entry, EntryVersion $version): array
    {
        $covered = [];
        $walker = function (mixed $value, string $path) use (&$walker, &$covered): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['id']) && is_numeric($value['id']) && is_string($value['alt'] ?? null) && trim($value['alt']) !== '') {
                $covered[(int) $value['id']][] = $path;
            }
            if (($value['type'] ?? null) === 'image' && is_array($value['attrs'] ?? null) && is_numeric($value['attrs']['mediaId'] ?? null) && trim((string) ($value['attrs']['alt'] ?? '')) !== '') {
                $covered[(int) $value['attrs']['mediaId']][] = $path;
            }
            foreach ($value as $key => $child) {
                $walker($child, $path.'.'.$key);
            }
        };
        foreach ($version->data as $key => $value) {
            $walker($value, (string) $key);
        }
        $pageImage = $entry->setting('image');
        if (is_array($pageImage) && is_numeric($pageImage['media_id'] ?? null) && trim((string) ($pageImage['alt'] ?? '')) !== '') {
            $covered[(int) $pageImage['media_id']][] = 'settings.image';
        }

        return $covered;
    }
}
