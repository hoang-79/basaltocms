<?php

declare(strict_types=1);

namespace Basalto\Core\Transfer;

use Closure;

/**
 * Verweise in JSON-Spalten ohne interne Kennungen (M-18). Beim Export ersetzt der Weg über das Schema des Themes
 * (wie `Media\MediaUsages`) jede Kennung durch eine Marke: Medium `{{basalto:media:<schlüssel>}}`, Inhalt
 * `{{basalto:entry:<uuid>}}`, Benutzer `{{basalto:user:<e-mail>}}`, die Site in Pfaden `{{basalto:site}}`. Der Import
 * braucht kein Schema: Er ersetzt die Marken in allen Zeichenketten — ganze Werte werden wieder zur Zahl, Marken
 * in Pfaden (`/media/{{…}}`, `sites/{{basalto:site}}/`) zur neuen Kennung.
 *
 * Gefunden werden: Bild-, Datei- und Galeriefelder, Bilder im Fliesstext (`mediaId`, dazu `src`), Verweise auf Inhalte
 * (`relation`, ausser `relation form`), Repeater, verschachtelte Blöcke; ohne Schema (kaputtes Theme, unbekannter
 * Typ) alles, was wie ein Bildwert aussieht. Eine Kennung, die es in der Site nicht gibt, wird null — wie ein
 * Verweis ins Leere vorher auch.
 *
 * @phpstan-type Field array{name: string, type: string, relation?: string|null, fields?: list<mixed>}
 */
final class References
{
    public const PATTERN = '/\{\{basalto:(media|entry|user):([^{}]+)\}\}|\{\{basalto:site\}\}/';

    /**
     * @param  array<string, list<mixed>>|null  $types  Felder je Inhaltstyp und Global (null = kein Schema)
     * @param  array<string, list<mixed>>  $blocks  Felder je Block
     * @param  array<int, string>  $media  Kennung → Schlüssel im Export
     * @param  array<int, string>  $entries  Kennung → UUID
     * @param  array<int, string>  $users  Kennung → E-Mail
     */
    public function __construct(
        private readonly ?array $types,
        private readonly array $blocks,
        private readonly array $media,
        private readonly array $entries,
        private readonly array $users,
        private readonly int $siteId,
    ) {}

    public static function mediaMarker(string $key): string
    {
        return '{{basalto:media:'.$key.'}}';
    }

    public static function entryMarker(string $uuid): string
    {
        return '{{basalto:entry:'.$uuid.'}}';
    }

    public static function userMarker(string $email): string
    {
        return '{{basalto:user:'.$email.'}}';
    }

    /**
     * Felder einer Sprachfassung (`entry_versions.data`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function versionData(string $type, array $data): array
    {
        if ($this->types === null) {
            return $this->scan($data);
        }
        $fields = $this->types[$type] ?? null;
        if ($fields === null) {
            return $this->scan($data);
        }
        $data = $this->fields($fields, $data);
        if (! self::hasBlocksField($fields) && is_array($data['content'] ?? null)) {
            $data['content'] = $this->blockList($data['content']);
        }

        return $data;
    }

    /**
     * Sprachunabhängige Seiteneinstellungen: Seitenbild und Besitzer (Reiter Rechte).
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>|null
     */
    public function entrySettings(?array $settings): ?array
    {
        if ($settings === null) {
            return null;
        }
        if (is_array($settings['image'] ?? null) && array_key_exists('media_id', $settings['image'])) {
            $settings['image']['media_id'] = $this->media($settings['image']['media_id']);
        }
        if (is_array($settings['rights'] ?? null) && array_key_exists('owner_id', $settings['rights'])) {
            $settings['rights']['owner_id'] = $this->user($settings['rights']['owner_id']);
        }

        return $settings;
    }

    /**
     * Ziel einer Weiterleitung (`entries.redirect_to`).
     *
     * @param  array<string, mixed>|null  $target
     * @return array<string, mixed>|null
     */
    public function redirectTo(?array $target): ?array
    {
        if ($target !== null && array_key_exists('entry_id', $target)) {
            $target['entry_id'] = $this->entry($target['entry_id']);
        }

        return $target;
    }

    /**
     * Einstellungen der Site: Startseite je Sprache, Logo aus der Mediathek.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>|null
     */
    public function siteSettings(?array $settings): ?array
    {
        if ($settings === null) {
            return null;
        }
        if (is_array($settings['home'] ?? null)) {
            foreach ($settings['home'] as $locale => $id) {
                $settings['home'][$locale] = $this->entry($id);
            }
        }
        if (is_array($settings['design'] ?? null) && array_key_exists('logo_media_id', $settings['design'])) {
            $settings['design']['logo_media_id'] = $this->media($settings['design']['logo_media_id']);
        }

        return $settings;
    }

    /**
     * Import: Marken in allen Zeichenketten eines Werts auflösen. Ein Wert, der nur aus einer Marke besteht, wird zur
     * Kennung (oder null bei unbekanntem Benutzer); eingebettete Marken werden durch die Kennung ersetzt.
     *
     * @param  Closure(string, string): ?int  $lookup  Art (media, entry, user, site) und Schlüssel → neue Kennung
     */
    public static function resolve(mixed $value, Closure $lookup): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::resolve($child, $lookup);
            }

            return $value;
        }
        if (! is_string($value) || ! str_contains($value, '{{basalto:')) {
            return $value;
        }
        if (preg_match('/^(?:\{\{basalto:(media|entry|user):([^{}]+)\}\}|\{\{basalto:site\}\})$/', $value, $match) === 1) {
            return $lookup($match[1] ?? 'site', $match[2] ?? '');
        }

        return (string) preg_replace_callback(self::PATTERN, static fn (array $m): string => (string) ($lookup(($m[1] ?? '') === '' ? 'site' : $m[1], $m[2] ?? '') ?? 0), $value);
    }

    /**
     * @param  list<mixed>  $fields
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function fields(array $fields, array $data): array
    {
        foreach ($fields as $field) {
            if (! is_array($field) || ! array_key_exists((string) ($field['name'] ?? ''), $data)) {
                continue;
            }
            $name = (string) $field['name'];
            $value = $data[$name];
            $data[$name] = match ((string) ($field['type'] ?? '')) {
                'image', 'file' => $this->mediaValue($value),
                'gallery' => is_array($value) ? array_map(fn (mixed $item): mixed => $this->mediaValue($item), $value) : $value,
                'richtext' => is_array($value) ? $this->richtext($value) : $value,
                'repeater' => is_array($value) ? array_map(fn (mixed $row): mixed => is_array($row) ? $this->fields(is_array($field['fields'] ?? null) ? array_values($field['fields']) : [], $row) : $row, $value) : $value,
                'blocks' => is_array($value) ? $this->blockList($value) : $value,
                'relation' => ($field['relation'] ?? null) === 'form' ? $value : $this->relationValue($value),
                default => $value,
            };
        }

        return $data;
    }

    /**
     * Die Blöcke eines Bausteins (`snippets.blocks`, TB 10 Scheibe 3) — wie die Blöcke einer Seite über das Schema.
     *
     * @param  array<mixed>  $blocks
     * @return array<mixed>
     */
    public function snippetBlocks(array $blocks): array
    {
        return $this->blockList($blocks);
    }

    /**
     * @param  array<mixed>  $blocks
     * @return array<mixed>
     */
    private function blockList(array $blocks): array
    {
        foreach ($blocks as $i => $block) {
            if (! is_array($block) || ! is_array($block['data'] ?? null)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $block['data'] = isset($this->blocks[$type]) ? $this->fields($this->blocks[$type], $block['data']) : $this->scan($block['data']);
            $blocks[$i] = $block;
        }

        return $blocks;
    }

    /**
     * Bildknoten im Fliesstext (ProseMirror-JSON): `mediaId` und die Quelle, rekursiv.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function richtext(array $node): array
    {
        if (($node['type'] ?? null) === 'image' && is_array($node['attrs'] ?? null) && is_numeric($node['attrs']['mediaId'] ?? null)) {
            $node['attrs'] = $this->imageAttrs($node['attrs']);
        }
        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $i => $child) {
                if (is_array($child)) {
                    $node['content'][$i] = $this->richtext($child);
                }
            }
        }

        return $node;
    }

    /**
     * @param  array<mixed>  $attrs
     * @return array<mixed>
     */
    private function imageAttrs(array $attrs): array
    {
        $id = (int) $attrs['mediaId'];
        $attrs['mediaId'] = $this->media($id);
        if (is_string($attrs['src'] ?? null) && isset($this->media[$id])) {
            // Auslieferung über die Route (/media/<id>/…) oder den Pfad im Speicher (sites/<id>/…)
            $src = (string) preg_replace('#/media/'.$id.'(?=[/?\#]|$)#', '/media/'.self::mediaMarker($this->media[$id]), $attrs['src']);
            $attrs['src'] = (string) preg_replace('#(^|/)sites/'.$this->siteId.'/#', '$1sites/{{basalto:site}}/', $src);
        }

        return $attrs;
    }

    /**
     * Ohne Schema: `mediaId` im Fliesstext und `{id, alt}`-Paare (Bildwert) — wie die Verwendungssuche.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function scan(array $data): array
    {
        if (($data['type'] ?? null) === 'image' && is_array($data['attrs'] ?? null) && is_numeric($data['attrs']['mediaId'] ?? null)) {
            $data['attrs'] = $this->imageAttrs($data['attrs']);
        }
        if (isset($data['id'], $data['alt']) && is_numeric($data['id']) && count($data) <= 4) {
            $data['id'] = $this->media($data['id']);
        }
        foreach ($data as $key => $value) {
            if (is_array($value) && $key !== 'attrs') {
                $data[$key] = $this->scan($value);
            }
        }

        return $data;
    }

    private function mediaValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach (['id', 'media_id'] as $key) {
                if (array_key_exists($key, $value) && is_numeric($value[$key])) {
                    $value[$key] = $this->media($value[$key]);
                }
            }

            return $value;
        }

        return is_numeric($value) ? $this->media($value) : $value;
    }

    private function relationValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => is_numeric($item) ? $this->entry($item) : $item, $value);
        }

        return is_numeric($value) ? $this->entry($value) : $value;
    }

    private function media(mixed $id): ?string
    {
        return is_numeric($id) && isset($this->media[(int) $id]) ? self::mediaMarker($this->media[(int) $id]) : null;
    }

    private function entry(mixed $id): ?string
    {
        return is_numeric($id) && isset($this->entries[(int) $id]) ? self::entryMarker($this->entries[(int) $id]) : null;
    }

    private function user(mixed $id): ?string
    {
        return is_numeric($id) && isset($this->users[(int) $id]) ? self::userMarker($this->users[(int) $id]) : null;
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
}
