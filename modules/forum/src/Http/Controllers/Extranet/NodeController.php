<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumSearch;
use Basalto\Modules\Forum\Frontend\Markup;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Prefix;
use Basalto\Modules\Forum\NodeType;
use Basalto\Modules\Forum\ThreadFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Community › Foren (CR-006 FO-04, Vorbild ACP «Foren»): der Baum aus Kategorien, Foren und Links —
 * anlegen, ändern, verschieben (hoch, runter, unter ein anderes Element), löschen. Ein Forum mit Themen lässt sich
 * nicht löschen, solange es Themen hat (sie gingen sonst mit); Kinder rücken beim Löschen eine Ebene hoch.
 */
final class NodeController
{
    /** Symbole, die ein Forum tragen kann — Schlüssel aus {@see Markup::ICONS} → Bezeichnung */
    public const ICON_LABELS = ['chat' => 'Sprechblase', 'megaphone' => 'Megafon', 'question' => 'Frage', 'lightbulb' => 'Glühbirne', 'bug' => 'Käfer', 'lock' => 'Schloss',
        'wand' => 'Zauberstab', 'wrench' => 'Schraubenschlüssel', 'hash' => 'Raute', 'pencil' => 'Stift', 'book' => 'Buch', 'star' => 'Stern', 'folder' => 'Ordner'];

    public function __construct(private readonly CurrentSite $current, private readonly Forum $forum, private readonly Recorder $recorder) {}

    public function index(): Response
    {
        $site = CommunityController::site($this->current);
        $locales = $site->localeCodes();
        $base = $this->forum->basePath($site, $site->default_locale);
        $front = ForumRoutes::front($site);
        $rows = [];
        $walk = function (?int $parent, int $depth) use (&$walk, &$rows, $site, $base, $front): void {
            foreach ($this->forum->children($site, $parent) as $node) {
                $rows[] = ['id' => $node->id, 'parent_id' => $node->parent_id, 'depth' => $depth, 'type' => $node->type->value, 'type_label' => $node->type->label(),
                    'slug' => $node->slug, 'title' => $node->title, 'description' => $node->description ?? (object) [], 'link_url' => $node->link_url,
                    'closed' => $node->isClosed(), 'threads' => $node->threads_count, 'posts' => $node->posts_count, 'icon' => (string) $node->setting('icon', 'chat'),
                    'prefixes' => array_map('intval', (array) $node->setting('prefixes', [])), 'prefix_required' => (bool) $node->setting('prefix_required', false),
                    'fields' => ThreadFields::of($node),
                    'url' => $base === null || $node->type === NodeType::Link || $node->type === NodeType::Category ? null : $front($base.'/'.$node->slug),
                    'update_url' => ForumRoutes::url('nodes.update', $node->id), 'destroy_url' => ForumRoutes::url('nodes.destroy', $node->id),
                    'move_url' => ForumRoutes::url('nodes.move', $node->id), 'permissions_url' => ForumRoutes::url('groups', ['forum' => $node->id])];
                $walk($node->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return Inertia::render('forum::Nodes', [
            'nodes' => $rows,
            'locales' => array_map(static fn (string $l): array => ['locale' => $l, 'is_default' => $l === $site->default_locale], $locales),
            'types' => array_map(static fn (NodeType $t): array => ['value' => $t->value, 'label' => $t->label()], NodeType::cases()),
            // Symbole der Foren wie bei WoltLab (Megafon, Frage, Glühbirne, Käfer …)
            'icons' => array_map(static fn (string $key): array => ['value' => $key, 'label' => (string) __(self::ICON_LABELS[$key]), 'svg' => Markup::icon($key)], array_keys(self::ICON_LABELS)),
            'prefixes' => array_values(Prefix::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('position')->orderBy('id')->get()->map(static fn (Prefix $p): array => [
                'id' => $p->id, 'label' => $p->label, 'color' => $p->color, 'update_url' => ForumRoutes::url('prefixes.update', $p->id), 'destroy_url' => ForumRoutes::url('prefixes.destroy', $p->id),
            ])->all()),
            'colors' => Prefix::COLORS,
            'field_types' => ThreadFields::TYPES,
            'base' => $base,
            'urls' => ['store' => ForumRoutes::url('nodes.store'), 'overview' => ForumRoutes::url('index'), 'prefix_store' => ForumRoutes::url('prefixes.store')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $data = $this->validated($request, $site, null);
        $position = (int) Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('parent_id', $data['parent_id'])->max('position') + 1;
        $node = new Node([...$data, 'site_id' => $site->id, 'position' => $position]);
        $node->save();
        $this->changed($site, 'forum.node_created', $node, null);

        return back()->with('status', __('«:title» ist angelegt.', ['title' => $node->titleIn($site->default_locale)]));
    }

    public function update(Request $request, int $node): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $node);
        $before = $model->only(['parent_id', 'type', 'slug', 'title', 'description', 'link_url', 'settings']);
        $data = $this->validated($request, $site, $model);
        if ($data['type'] !== NodeType::Board->value && $model->threads_count > 0) {
            throw ValidationException::withMessages(['type' => __('Ein Forum mit Themen bleibt ein Forum — verschieben Sie zuerst die Themen.')]);
        }
        if ($data['parent_id'] !== $model->parent_id) {
            $data['position'] = (int) Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('parent_id', $data['parent_id'])->max('position') + 1;
        }
        $model->fill($data)->save();
        $this->changed($site, 'forum.node_updated', $model, $before);

        app(ForumSearch::class)->rebuild($site);

        return back()->with('status', __('Gespeichert.'));
    }

    /** Hoch oder runter unter den Geschwistern. */
    public function move(Request $request, int $node): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $node);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $siblings = $this->forum->children($site, $model->parent_id)->values();
        $index = $siblings->search(static fn (Node $n): bool => $n->id === $model->id);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if (is_int($index) && $swap >= 0 && $swap < $siblings->count()) {
            $ordered = $siblings->all();
            [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
            foreach (array_values($ordered) as $i => $sibling) {
                Node::query()->withoutGlobalScopes()->whereKey($sibling->id)->update(['position' => $i + 1]);
            }
            $this->changed($site, 'forum.node_moved', $model, null);
        }

        return back();
    }

    public function destroy(int $node): RedirectResponse
    {
        $site = CommunityController::site($this->current);
        $model = $this->find($site, $node);
        if ($model->threads()->withoutGlobalScopes()->withTrashed()->exists()) {
            return back()->withErrors(['forum' => __('«:title» hat Themen — verschieben oder löschen Sie sie zuerst.', ['title' => $model->titleIn($site->default_locale)])]);
        }
        Node::query()->withoutGlobalScopes()->where('parent_id', $model->id)->update(['parent_id' => $model->parent_id]);
        $title = $model->titleIn($site->default_locale);
        $model->delete();
        $this->changed($site, 'forum.node_deleted', null, ['titel' => $title]);

        return back()->with('status', __('«:title» ist gelöscht.', ['title' => $title]));
    }

    private function find(Site $site, int $id): Node
    {
        $node = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($id);
        abort_if($node === null, 404);

        return $node;
    }

    /** @return array{parent_id: int|null, type: string, slug: string, title: array<string, string>, description: array<string, string>, link_url: string|null, settings: array<string, mixed>} */
    private function validated(Request $request, Site $site, ?Node $node): array
    {
        $locales = $site->localeCodes();
        $rules = [
            'type' => ['required', Rule::enum(NodeType::class)],
            'parent_id' => ['nullable', 'integer', Rule::exists('forum_nodes', 'id')->where('site_id', $site->id)],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(Node::RESERVED),
                Rule::unique('forum_nodes', 'slug')->where('site_id', $site->id)->ignore($node?->id)],
            'title' => ['required', 'array'],
            'title.'.$site->default_locale => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'array'],
            'link_url' => ['nullable', 'required_if:type,link', 'string', 'max:255', 'regex:#^(https?://|/)#'],
            'closed' => ['sometimes', 'boolean'],
            'icon' => ['sometimes', 'nullable', Rule::in(array_keys(self::ICON_LABELS))],
            'prefixes' => ['sometimes', 'array'],
            'prefixes.*' => ['integer', Rule::exists('forum_prefixes', 'id')->where('site_id', $site->id)],
            'prefix_required' => ['sometimes', 'boolean'],
            'fields' => ['sometimes', 'array', 'max:10'],
            'fields.*.label' => ['required', 'array'],
            'fields.*.label.'.$site->default_locale => ['required', 'string', 'max:60'],
            'fields.*.type' => ['required', Rule::in(ThreadFields::TYPES)],
            'fields.*.options' => ['nullable', 'array', 'max:30'],
            'fields.*.options.*' => ['nullable', 'string', 'max:60'],
            'fields.*.required' => ['sometimes', 'boolean'],
        ];
        foreach ($locales as $locale) {
            $rules['title.'.$locale] ??= ['nullable', 'string', 'max:120'];
            $rules['description.'.$locale] = ['nullable', 'string', 'max:500'];
        }
        $data = $request->validate($rules, [], ['title.'.$site->default_locale => __('Name'), 'slug' => __('Adresse'), 'link_url' => __('Ziel des Links'), 'parent_id' => __('Übergeordnet')]);
        $parent = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($node !== null && $parent !== null) {
            $chain = array_map(static fn (array $s): int => $s[1], $this->forum->chain($site, $this->find($site, $parent)));
            if (in_array($node->id, $chain, true)) {
                throw ValidationException::withMessages(['parent_id' => __('Ein Element kann nicht unter sich selbst liegen.')]);
            }
        }
        $title = array_filter(array_map(static fn (mixed $v): string => trim((string) $v), array_intersect_key((array) $data['title'], array_flip($locales))), static fn (string $v): bool => $v !== '');
        $description = array_filter(array_map(static fn (mixed $v): string => trim((string) $v), array_intersect_key((array) ($data['description'] ?? []), array_flip($locales))), static fn (string $v): bool => $v !== '');
        $slug = is_string($data['slug'] ?? null) && $data['slug'] !== '' ? $data['slug'] : $this->freeSlug($site, Str::slug($title[$site->default_locale] ?? 'forum', '-', 'de') ?: 'forum', $node?->id);
        $settings = $node->settings ?? [];
        $settings['closed'] = (bool) ($data['closed'] ?? false);
        if (array_key_exists('icon', $data)) {
            $settings['icon'] = is_string($data['icon']) ? $data['icon'] : 'chat';
        }
        $settings['prefixes'] = array_values(array_unique(array_map('intval', (array) ($data['prefixes'] ?? []))));
        $settings['prefix_required'] = $settings['prefixes'] !== [] && (bool) ($data['prefix_required'] ?? false);
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $i => $field) {
            $label = array_filter(array_map(static fn (mixed $v): string => trim((string) $v), array_intersect_key((array) $field['label'], array_flip($locales))), static fn (string $v): bool => $v !== '');
            $key = Str::limit((string) preg_replace('/[^a-z0-9_]/', '_', Str::slug($label[$site->default_locale] ?? 'feld', '_', 'de')), 24, '') ?: 'feld';
            while (in_array($key, array_column($fields, 'key'), true)) {
                $key .= '_'.$i;
            }
            $fields[] = ['key' => $node !== null ? $this->keepKey($node, $label, $key) : $key, 'label' => $label, 'type' => (string) $field['type'],
                'options' => array_values(array_filter(array_map(static fn (mixed $o): string => trim((string) $o), (array) ($field['options'] ?? [])), static fn (string $o): bool => $o !== '')),
                'required' => (bool) ($field['required'] ?? false)];
        }
        $settings['fields'] = $fields;

        return ['parent_id' => $parent, 'type' => (string) $data['type'], 'slug' => $slug, 'title' => $title, 'description' => $description,
            'link_url' => $data['type'] === NodeType::Link->value ? (string) $data['link_url'] : null, 'settings' => $settings];
    }

    /**
     * Ein Feld behält seinen Schlüssel, solange es gleich heisst — sonst wären die Werte bestehender Themen verwaist.
     *
     * @param  array<string, string>  $label
     */
    private function keepKey(Node $node, array $label, string $fallback): string
    {
        foreach (ThreadFields::of($node) as $existing) {
            if (array_intersect_assoc($existing['label'], $label) !== []) {
                return $existing['key'];
            }
        }

        return $fallback;
    }

    private function freeSlug(Site $site, string $wanted, ?int $except): string
    {
        $base = Str::limit($wanted, 70, '');
        $candidate = in_array($base, Node::RESERVED, true) ? $base.'-forum' : $base;
        for ($i = 2; Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('slug', $candidate)->when($except !== null, static fn ($q) => $q->whereKeyNot($except))->exists(); $i++) {
            $candidate = $base.'-'.$i;
        }

        return $candidate;
    }

    /** @param  array<string, mixed>|null  $before */
    private function changed(Site $site, string $action, ?Node $node, ?array $before): void
    {
        $this->forum->forget();
        app(PageCache::class)->invalidate($site->id);
        $this->recorder->record($action, null, $before, $node?->only(['id', 'type', 'slug', 'title', 'parent_id']), $site);
    }
}
