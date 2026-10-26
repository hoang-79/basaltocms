<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\EntryVersion;

/**
 * Unterschiede zwischen zwei Fassungen als Liste von Änderungen je Pfad — Titel, Slug, Typfelder, Metadaten, Blöcke (Typ, Felder, Zeilen).
 * Fliesstext wird als Text verglichen, alles andere als normalisierte Werte.
 */
final class VersionDiff
{
    /** @return list<array{path: string, kind: string, old: mixed, new: mixed}> */
    public static function between(?EntryVersion $old, EntryVersion $new): array
    {
        $changes = [];
        $oldData = $old === null ? [] : ['title' => $old->title, 'slug' => $old->slug] + $old->data;
        $newData = ['title' => $new->title, 'slug' => $new->slug] + $new->data;
        $skip = ['content', 'settings'];
        $keys = array_values(array_unique(array_merge(['title', 'slug', 'intro'], array_keys($oldData), array_keys($newData))));
        foreach ($keys as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            self::compare($changes, $key, $oldData[$key] ?? null, $newData[$key] ?? null);
        }
        $oldMeta = is_array($oldData['settings']['meta'] ?? null) ? $oldData['settings']['meta'] : [];
        $newMeta = is_array($newData['settings']['meta'] ?? null) ? $newData['settings']['meta'] : [];
        foreach (array_unique(array_merge(array_keys($oldMeta), array_keys($newMeta))) as $key) {
            self::compare($changes, 'settings.meta.'.$key, $oldMeta[$key] ?? null, $newMeta[$key] ?? null);
        }
        $oldBlocks = is_array($oldData['content'] ?? null) ? array_values($oldData['content']) : [];
        $newBlocks = is_array($newData['content'] ?? null) ? array_values($newData['content']) : [];
        $count = max(count($oldBlocks), count($newBlocks));
        for ($i = 0; $i < $count; $i++) {
            $o = $oldBlocks[$i] ?? null;
            $n = $newBlocks[$i] ?? null;
            if ($o === null) {
                $changes[] = ['path' => "content.$i", 'kind' => 'added', 'old' => null, 'new' => is_array($n) ? ($n['type'] ?? null) : null];

                continue;
            }
            if ($n === null) {
                $changes[] = ['path' => "content.$i", 'kind' => 'removed', 'old' => is_array($o) ? ($o['type'] ?? null) : null, 'new' => null];

                continue;
            }
            if (! is_array($o) || ! is_array($n)) {
                continue;
            }
            if (($o['type'] ?? null) !== ($n['type'] ?? null)) {
                $changes[] = ['path' => "content.$i.type", 'kind' => 'changed', 'old' => $o['type'] ?? null, 'new' => $n['type'] ?? null];

                continue;
            }
            $od = is_array($o['data'] ?? null) ? $o['data'] : [];
            $nd = is_array($n['data'] ?? null) ? $n['data'] : [];
            foreach (array_unique(array_merge(array_keys($od), array_keys($nd))) as $field) {
                self::compare($changes, "content.$i.$field", $od[$field] ?? null, $nd[$field] ?? null);
            }
        }

        return $changes;
    }

    /** @param  list<array{path: string, kind: string, old: mixed, new: mixed}>  $changes */
    private static function compare(array &$changes, string $path, mixed $old, mixed $new): void
    {
        $o = self::normalize($old);
        $n = self::normalize($new);
        if ($o === $n) {
            return;
        }
        $changes[] = ['path' => $path, 'kind' => $old === null ? 'added' : ($new === null ? 'removed' : 'changed'), 'old' => $o, 'new' => $n];
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value) && ($value['type'] ?? null) === 'doc') {
            return RichText::toText($value);
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value === '' ? null : $value;
    }
}
