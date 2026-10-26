<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Frontend-Editor (M-19): Texte von Blöcken direkt auf der Seite bearbeiten — eine Vue-Insel für die angemeldete
 * Redaktion; die Freigabe bleibt im Backend. Gespeichert wird in den offenen Entwurf der Sprachfassung, genau wie
 * der Autosave des Editors (DraftInput, `Entry::saveDraft`, Ereignis `EntrySaved`) — live ändert sich nichts.
 *
 * Für Gäste gibt es keine Spur: Feldmarken entstehen im Lava-Renderer nur, wenn die Auslieferung sie für eine
 * berechtigte, angemeldete Person anfordert; Insel und Konfiguration hängt erst `inject()` an. Angemeldete umgehen
 * den Seiten-Cache, und solche Antworten tragen zusätzlich `no-store` und keine Cache-Lebensdauer.
 *
 * Bearbeitbar sind Titel, Einleitung und Felder des Typs sowie Felder der Blöcke (auch verschachtelter) vom Typ
 * `text`, `textarea` und `richtext` — Fliesstext nur, solange er schlicht ist (Absätze, Überschriften, Listen,
 * Zitat, Fett, Kursiv, Link …). Tabellen, Bilder und Einbettungen bleiben dem Backend.
 *
 * @phpstan-type EditableField array{kind: string, label: string}
 */
final class FrontendEditor
{
    /** Sitzungsschlüssel: «Auf der Seite bearbeiten» ist an. */
    public const SESSION_KEY = 'basalto.frontend_edit';

    public const KINDS = ['text', 'textarea', 'richtext'];

    private const TEXT_MAX = 300;

    private const TEXTAREA_MAX = 5000;

    private const RICHTEXT_MAX = 20000;

    private const BLOCK_NODES = ['paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'horizontalRule'];

    private const INLINE_NODES = ['text', 'hardBreak'];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'subscript', 'superscript', 'link'];

    private const ALIGNS = ['center', 'right', 'justify'];

    public function __construct(private readonly ThemeLoader $themes) {}

    /**
     * Der Zustand für diese Anfrage — null, wenn niemand angemeldet ist oder die Person den Inhalt nicht auf der Seite
     * bearbeiten darf (dann rendert die Auslieferung wie für Gäste). Im Modus «bearbeiten» zeigt die Seite die neueste
     * Fassung der Sprache (den Entwurf), sonst die veröffentlichte.
     *
     * @return array{version: EntryVersion, marks: bool, config: array<string, mixed>}|null
     */
    public function state(Request $request, Entry $entry, EntryVersion $live): ?array
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('editOnPage', $entry)) {
            return null;
        }
        $editing = $request->hasSession() && $request->session()->get(self::SESSION_KEY) === true;
        $locale = $live->locale;
        $latest = $entry->latestVersion($locale) ?? $live;
        $schema = ThemeSchema::fromTheme($this->themes->forSite($entry->site));

        return ['version' => $editing ? $latest : $live, 'marks' => $editing, 'config' => [
            'entry' => $entry->id,
            'locale' => $locale,
            'ui_locale' => is_string($user->getAttribute('locale')) ? $user->getAttribute('locale') : 'de',
            'editing' => $editing,
            'live' => ['number' => $live->number],
            'version' => self::describe($latest),
            'fields' => $editing ? self::fieldsFrom($schema, $entry, $latest) : [],
            'urls' => [
                'mode' => route('basalto.frontend-editor.mode', absolute: false),
                'save' => route('basalto.frontend-editor.save', ['entry' => $entry->id, 'locale' => $locale], false),
                'backend' => route('basalto.frontend-editor.open', ['entry' => $entry->id, 'locale' => $locale], false),
            ],
        ]];
    }

    /**
     * Was die Insel über eine Fassung wissen muss — samt Stempel, an dem das Speichern einen veralteten Stand erkennt.
     *
     * @return array{id: int, number: int, status: string, status_label: string, stamp: string}
     */
    public static function describe(EntryVersion $version): array
    {
        return ['id' => $version->id, 'number' => $version->number, 'status' => $version->status->value, 'status_label' => $version->status->label(), 'stamp' => self::stamp($version)];
    }

    /** Fingerabdruck einer Fassung: Status, Titel und Daten mit sortierten Schlüsseln (MySQL ordnet JSON-Schlüssel um). */
    public static function stamp(EntryVersion $version): string
    {
        $data = ['id' => $version->id, 'status' => $version->status->value, 'title' => $version->title, 'data' => $version->data];

        return hash('xxh128', (string) json_encode(self::sorted($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Die auf der Seite bearbeitbaren Felder einer Fassung: Pfad in die Fassungsdaten → Art und Beschriftung.
     * Seite: `title`, `intro`, Felder des Typs; Blöcke: `content.0.data.title`, verschachtelt `content.0.data.blocks.1.data.body`.
     *
     * @return array<string, EditableField>
     */
    public static function fieldsFrom(ThemeSchema $schema, Entry $entry, EntryVersion $version): array
    {
        $data = $version->data;
        $fields = ['title' => ['kind' => 'text', 'label' => __('Titel')]];
        if (self::isSimpleRichText($data['intro'] ?? null)) {
            $fields['intro'] = ['kind' => 'richtext', 'label' => __('Einleitung')];
        }
        foreach ($schema->types[$entry->type]['fields'] ?? [] as $field) {
            if (! in_array($field['name'], ['title', 'slug', 'intro', 'content'], true) && self::editable($field['type'], $data[$field['name']] ?? null)) {
                $fields[$field['name']] = ['kind' => $field['type'], 'label' => $field['label']];
            }
        }
        $blocks = is_array($data['content'] ?? null) ? array_values($data['content']) : [];

        return $fields + self::blockFields($schema, $blocks, 'content', __('Block').' ');
    }

    /**
     * @param  list<mixed>  $blocks
     * @return array<string, EditableField>
     */
    private static function blockFields(ThemeSchema $schema, array $blocks, string $prefix, string $labelPrefix, int $depth = 0): array
    {
        $out = [];
        if ($depth > 8) {
            return $out;
        }
        foreach ($blocks as $i => $block) {
            $def = is_array($block) ? ($schema->blocks[(string) ($block['type'] ?? '')] ?? null) : null;
            if ($def === null) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $label = sprintf('%s%d · %s', $labelPrefix, $i + 1, $def['label']);
            foreach ($def['fields'] as $field) {
                $path = sprintf('%s.%d.data.%s', $prefix, $i, $field['name']);
                if ($field['type'] === 'blocks' && is_array($data[$field['name']] ?? null)) {
                    $out += self::blockFields($schema, array_values($data[$field['name']]), $path, $label.' · '.$field['label'].' ', $depth + 1);
                } elseif (self::editable($field['type'], $data[$field['name']] ?? null)) {
                    $out[$path] = ['kind' => $field['type'], 'label' => $label.' · '.$field['label']];
                }
            }
        }

        return $out;
    }

    private static function editable(string $type, mixed $value): bool
    {
        return in_array($type, self::KINDS, true) && ($type !== 'richtext' || self::isSimpleRichText($value));
    }

    /**
     * Feldmarken für einen Bereich des Renderers: `$prefix` leer = Seite («page.title» → `title`), sonst ein Block
     * («title» → `content.0.data.title`). Nur Felder genau eine Stufe unter dem Block — Verschachtelte haben ihren eigenen Lauf.
     *
     * @param  array<string, EditableField>  $fields
     * @return array<string, array{path: string, kind: string}>
     */
    public static function marks(array $fields, string $prefix): array
    {
        $marks = [];
        foreach ($fields as $path => $field) {
            if ($prefix === '' && ! str_contains($path, '.')) {
                $marks['page.'.$path] = ['path' => $path, 'kind' => $field['kind']];
            } elseif ($prefix !== '' && str_starts_with($path, $prefix.'.data.') && ! str_contains($name = substr($path, strlen($prefix) + 6), '.')) {
                $marks[$name] = ['path' => $path, 'kind' => $field['kind']];
            }
        }

        return $marks;
    }

    /**
     * Änderungen von der Seite auf die Daten der neuesten Fassung legen — nur bearbeitbare Felder, Werte je Art geprüft
     * und gesäubert. Liefert die Eingabe für DraftInput (wie der Autosave des Editors).
     *
     * @param  list<mixed>  $changes  `[{field, value}]`
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function input(Entry $entry, EntryVersion $latest, array $changes): array
    {
        $schema = ThemeSchema::fromTheme($this->themes->forSite($entry->site));
        $fields = self::fieldsFrom($schema, $entry, $latest);
        $data = ['title' => $latest->title] + $latest->data;
        $errors = [];
        foreach ($changes as $i => $change) {
            $path = is_array($change) && is_string($change['field'] ?? null) ? $change['field'] : '';
            $field = $fields[$path] ?? null;
            if ($field === null) {
                $errors["changes.$i.field"] = __('Das Feld «:field» lässt sich auf der Seite nicht bearbeiten — bitte im Backend.', ['field' => $path]);

                continue;
            }
            $value = is_array($change) ? ($change['value'] ?? null) : null;
            $clean = self::clean($field['kind'], $value);
            if ($clean instanceof \Stringable) {
                $errors[$path] = sprintf('%s: %s', $field['label'], (string) $clean);

                continue;
            }
            $data = self::put($data, explode('.', $path), $clean);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $typeFields = [];
        foreach ($schema->types[$entry->type]['fields'] ?? [] as $field) {
            if (! in_array($field['name'], ['title', 'slug', 'intro', 'content'], true) && $field['type'] !== 'blocks' && array_key_exists($field['name'], $data)) {
                $typeFields[$field['name']] = $data[$field['name']];
            }
        }

        return ['title' => (string) $data['title'], 'slug' => $latest->slug, 'intro' => $data['intro'] ?? null,
            'content' => is_array($data['content'] ?? null) ? array_values($data['content']) : [], 'fields' => $typeFields];
    }

    /**
     * Ein Wert je Art: Text einzeilig (höchstens 300 Zeichen), Textfeld mehrzeilig (5000), Fliesstext als gesäubertes
     * ProseMirror-Dokument. Ein Fehler kommt als Stringable zurück.
     */
    private static function clean(string $kind, mixed $value): mixed
    {
        $error = static fn (string $message): \Stringable => str($message);
        if ($kind === 'richtext') {
            if ($value === null) {
                return null;
            }
            if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
                return $error(__('Das ist kein Fliesstext.'));
            }
            $doc = self::cleanRichText($value);
            if ($doc !== null && mb_strlen(RichText::toText($doc)) > self::RICHTEXT_MAX) {
                return $error(__('Der Text ist zu lang (höchstens :max Zeichen).', ['max' => self::RICHTEXT_MAX]));
            }

            return $doc;
        }
        if ($value !== null && ! is_string($value)) {
            return $error(__('Das ist kein Text.'));
        }
        $text = str_replace("\r\n", "\n", (string) $value);
        $text = $kind === 'text' ? trim((string) preg_replace('/\s+/u', ' ', $text)) : trim($text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $max = $kind === 'text' ? self::TEXT_MAX : self::TEXTAREA_MAX;
        if (mb_strlen($text) > $max) {
            return $error(__('Der Text ist zu lang (höchstens :max Zeichen).', ['max' => $max]));
        }

        return $text;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @param  list<string>  $segments
     * @return array<int|string, mixed>
     */
    private static function put(array $data, array $segments, mixed $value): array
    {
        $key = array_shift($segments);
        if ($key === null) {
            return $data;
        }
        if (ctype_digit($key)) {
            $data = array_values($data);
            $key = (int) $key;
        }
        $data[$key] = $segments === [] ? $value : self::put(is_array($data[$key] ?? null) ? $data[$key] : [], $segments, $value);

        return $data;
    }

    /** Schlichter Fliesstext — nur Knoten und Marken, die die Seite ohne Werkzeugleiste verlustfrei zurückgeben kann. */
    public static function isSimpleRichText(mixed $value): bool
    {
        if ($value === null || is_string($value)) {
            return true;
        }
        if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
            return false;
        }

        return self::simpleNodes(is_array($value['content'] ?? null) ? $value['content'] : [], 0);
    }

    /** @param  array<mixed>  $nodes */
    private static function simpleNodes(array $nodes, int $depth): bool
    {
        if ($depth > 10) {
            return false;
        }
        foreach ($nodes as $node) {
            if (! is_array($node) || ! in_array($node['type'] ?? null, [...self::BLOCK_NODES, ...self::INLINE_NODES], true)) {
                return false;
            }
            foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
                if (! is_array($mark) || ! in_array($mark['type'] ?? null, self::MARKS, true)) {
                    return false;
                }
            }
            if (is_array($node['content'] ?? null) && ! self::simpleNodes($node['content'], $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fliesstext von der Seite säubern: nur bekannte Knoten (Absatz, Überschrift, Listen, Zitat, Linie, Umbruch) und
     * Marken (fett, kursiv, unterstrichen, durchgestrichen, Code, hoch/tief, Link mit sicherem Ziel); alles andere
     * fällt weg, Text bleibt Text. Leer → null. Die Ausgabe escaped danach ohnehin RichText::toHtml().
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}|null
     */
    public static function cleanRichText(mixed $value): ?array
    {
        if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
            return null;
        }
        $content = self::cleanBlocks(is_array($value['content'] ?? null) ? $value['content'] : [], 0);
        $doc = ['type' => 'doc', 'content' => $content];

        return RichText::toText($doc) === '' && ! self::hasRule($content) ? null : $doc;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private static function hasRule(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'horizontalRule') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function cleanBlocks(array $nodes, int $depth): array
    {
        $out = [];
        $inline = [];
        if ($depth > 10) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? null;
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $children = is_array($node['content'] ?? null) ? $node['content'] : [];
            if (in_array($type, self::INLINE_NODES, true)) {
                $inline = [...$inline, ...self::cleanInline([$node])];

                continue;
            }
            // Lose Inline-Knoten vor einem Block werden ein Absatz
            if ($inline !== []) {
                $out[] = ['type' => 'paragraph', 'content' => $inline];
                $inline = [];
            }
            $clean = match ($type) {
                'paragraph' => ['type' => 'paragraph'] + self::align($attrs) + self::contentOf(self::cleanInline($children)),
                'heading' => ['type' => 'heading', 'attrs' => ['level' => max(1, min(6, is_numeric($attrs['level'] ?? null) ? (int) $attrs['level'] : 2))] + (self::align($attrs)['attrs'] ?? [])] + self::contentOf(self::cleanInline($children)),
                'bulletList', 'orderedList' => self::cleanList((string) $type, $attrs, $children, $depth),
                'blockquote' => ['type' => 'blockquote', 'content' => self::cleanBlocks($children, $depth + 1) ?: [['type' => 'paragraph']]],
                'horizontalRule' => ['type' => 'horizontalRule'],
                default => null,
            };
            if ($clean !== null) {
                $out[] = $clean;
            }
        }
        if ($inline !== []) {
            $out[] = ['type' => 'paragraph', 'content' => $inline];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $attrs
     * @param  array<mixed>  $children
     * @return array<string, mixed>|null
     */
    private static function cleanList(string $type, array $attrs, array $children, int $depth): ?array
    {
        $items = [];
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'listItem') {
                $items[] = ['type' => 'listItem', 'content' => self::cleanBlocks(is_array($child['content'] ?? null) ? $child['content'] : [], $depth + 1) ?: [['type' => 'paragraph']]];
            }
        }
        if ($items === []) {
            return null;
        }
        $start = is_numeric($attrs['start'] ?? null) ? (int) $attrs['start'] : 1;

        return ['type' => $type] + ($type === 'orderedList' && $start > 1 ? ['attrs' => ['start' => min($start, 100000)]] : []) + ['content' => $items];
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function cleanInline(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'hardBreak') {
                $out[] = ['type' => 'hardBreak'];

                continue;
            }
            if (($node['type'] ?? null) !== 'text' || ! is_string($node['text'] ?? null)) {
                continue;
            }
            $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $node['text']);
            if ($text === '') {
                continue;
            }
            $marks = self::cleanMarks(is_array($node['marks'] ?? null) ? $node['marks'] : []);
            $out[] = ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks]);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $marks
     * @return list<array<string, mixed>>
     */
    private static function cleanMarks(array $marks): array
    {
        $out = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::MARKS, true) || isset($out[$type])) {
                continue;
            }
            if ($type !== 'link') {
                $out[$type] = ['type' => $type];

                continue;
            }
            $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
            $href = is_string($attrs['href'] ?? null) ? trim($attrs['href']) : '';
            if ($href === '' || mb_strlen($href) > 2000 || preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $href) !== 1) {
                continue;
            }
            $link = ['href' => $href];
            if (($attrs['target'] ?? null) === '_blank') {
                $link['target'] = '_blank';
            }
            if (is_string($attrs['title'] ?? null) && trim($attrs['title']) !== '') {
                $link['title'] = mb_substr(trim($attrs['title']), 0, 200);
            }
            $out[$type] = ['type' => 'link', 'attrs' => $link];
        }

        return array_values($out);
    }

    /**
     * @param  array<mixed>  $attrs
     * @return array{attrs?: array{textAlign: string}}
     */
    private static function align(array $attrs): array
    {
        $align = $attrs['textAlign'] ?? null;

        return is_string($align) && in_array($align, self::ALIGNS, true) ? ['attrs' => ['textAlign' => $align]] : [];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array{content?: list<array<string, mixed>>}
     */
    private static function contentOf(array $content): array
    {
        return $content === [] ? [] : ['content' => $content];
    }

    /**
     * Insel und Konfiguration vor `</body>` — nur für die berechtigte, angemeldete Person. Das Skript kommt aus dem
     * Build des Kerns (eigener Vite-Eintrag, keine Fremdressource); die Konfiguration als JSON, `<` maskiert.
     *
     * @param  array<string, mixed>  $config
     */
    public function inject(string $html, array $config): string
    {
        $json = (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $island = '<script type="application/json" id="basalto-frontend-editor">'.$json.'</script>'
            .app(Backend::class)->vite()->withEntryPoints(['resources/js/frontend-editor.ts'])->toHtml();
        $at = strripos($html, '</body>');

        return $at === false ? $html.$island : substr($html, 0, $at).$island.substr($html, $at);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
