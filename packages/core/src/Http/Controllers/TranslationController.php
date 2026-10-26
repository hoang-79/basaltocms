<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Content\RichText;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Content\Translations;
use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Übersetzung (M-06, Mockup 12): Übersicht aller Inhalte mit Stand je Sprache, Übersetzen nebeneinander
 * (Quelle lesen, Ziel bearbeiten, geänderte Felder mit altem Text) und Speichern als neue Fassung der Zielsprache.
 */
final class TranslationController
{
    public function __construct(private readonly CurrentSite $current, private readonly Translations $translations, private readonly ThemeLoader $themes) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $filters = ['locale' => (string) $request->query('locale', ''), 'state' => in_array($request->query('state'), [Translations::OUTDATED, Translations::MISSING], true) ? (string) $request->query('state') : ''];
        $entries = Entry::query()->with(['versions', 'site.locales'])->latest('updated_at')->limit(500)->get();
        $required = $site->requiredLocales();
        $labels = $this->typeLabels($site);
        $rows = [];
        $counts = ['outdated' => 0, 'missing' => 0, 'total' => 0];
        foreach ($entries as $entry) {
            if ($entry->pageType() !== null && ! $entry->pageType()->hasContent()) {
                continue;
            }
            $states = $this->translations->states($entry);
            $urls = [];
            foreach ($states as $locale => $state) {
                $urls[$locale] = $state === Translations::SOURCE ? route('basalto.entries.edit', [$entry, $locale]) : route('basalto.entries.translation', [$entry, $locale]);
                if ($state === Translations::OUTDATED || $state === Translations::MISSING) {
                    $counts[$state]++;
                }
            }
            $counts['total']++;
            if ($filters['state'] !== '' && ! in_array($filters['state'], $filters['locale'] !== '' ? [$states[$filters['locale']] ?? null] : array_values($states), true)) {
                continue;
            }
            $latest = $entry->versions->where('locale', $site->default_locale)->sortByDesc('number')->first();
            $rows[] = ['entry_id' => $entry->id, 'title' => $latest->title ?? __('(ohne Titel)'), 'type_label' => $entry->pageType()?->label() ?? ($labels[$entry->type] ?? $entry->type),
                'kind' => $entry->pageType() !== null ? 'page' : 'entry', 'states' => $states, 'urls' => $urls,
                'rank' => $this->rank($states, $required), 'changed' => (string) $entry->versions->max('updated_at')];
        }
        // TB 29: wie versprochen «Pflichtsprachen zuerst» — Lücken in Pflichtsprachen, dann in weiteren, dann der Rest;
        // je Stufe die zuletzt geänderte Fassung zuerst (vorher: Änderung des Eintrags, die neue Fassungen nicht berühren)
        usort($rows, static fn (array $a, array $b): int => [$a['rank'], $b['changed']] <=> [$b['rank'], $a['changed']]);
        $rows = array_map(static fn (array $row): array => array_diff_key($row, ['rank' => true, 'changed' => true]), $rows);

        return Inertia::render('core::Translations/Index', [
            'rows' => $rows,
            'locales' => $site->locales->map(static fn ($l): array => ['locale' => $l->locale, 'is_default' => (bool) $l->is_default, 'is_required' => (bool) $l->is_required])->values()->all(),
            'filters' => $filters,
            'counts' => $counts,
            'urls' => ['index' => route('basalto.translations.index')],
        ]);
    }

    /**
     * 0: in einer Pflichtsprache veraltet oder fehlend · 1: in einer weiteren Sprache · 2: alles aktuell.
     *
     * @param  array<string, string>  $states
     * @param  list<string>  $required
     */
    private function rank(array $states, array $required): int
    {
        $rank = 2;
        foreach ($states as $locale => $state) {
            if ($state === Translations::OUTDATED || $state === Translations::MISSING) {
                $rank = min($rank, in_array($locale, $required, true) ? 0 : 1);
            }
        }

        return $rank;
    }

    public function edit(Entry $entry, string $locale): Response|RedirectResponse
    {
        Gate::authorize('view', $entry);
        $site = $entry->site;
        abort_unless(in_array($locale, $site->localeCodes(), true), 404);
        if ($locale === $site->default_locale) {
            return redirect()->route('basalto.entries.edit', [$entry, $locale]);
        }
        $entry->load('versions');
        $source = $entry->latestVersion($site->default_locale);
        $target = $entry->latestVersion($locale);
        $detail = $this->translations->stateDetail($entry, $locale);
        $base = $target?->source_version_id !== null ? $entry->versions->firstWhere('id', $target->source_version_id) : null;
        $sections = [];
        $progress = ['translated' => 0, 'total' => 0, 'changed' => 0];
        if ($source !== null) {
            $sourceData = ['title' => $source->title] + $source->data;
            $targetData = $target === null ? [] : ['title' => $target->title] + $target->data;
            $baseData = $base === null ? null : ['title' => $base->title] + $base->data;
            foreach ($this->translations->layout($entry, $source) as $section) {
                $fields = [];
                foreach ($section['fields'] as $pair) {
                    $sourceValue = Arr::get($sourceData, $pair['path']);
                    $previous = $baseData === null ? null : Arr::get($baseData, $pair['path']);
                    $changed = $pair['translatable'] && $baseData !== null && Translations::normalize($previous) !== Translations::normalize($sourceValue);
                    $targetValue = $pair['translatable'] ? Arr::get($targetData, $pair['path']) : $sourceValue;
                    $isRich = $pair['type'] === 'richtext';
                    $fields[] = [...$pair, 'source' => $sourceValue, 'target' => $targetValue,
                        'source_html' => $isRich ? RichText::toHtml($sourceValue) : null,
                        'previous' => $changed ? $previous : null, 'previous_html' => $changed && $isRich ? RichText::toHtml($previous) : null,
                        'changed' => $changed];
                    if ($pair['translatable'] && Translations::normalize($sourceValue) !== null) {
                        $progress['total']++;
                        $progress['translated'] += Translations::normalize($targetValue) !== null ? 1 : 0;
                        $progress['changed'] += $changed ? 1 : 0;
                    }
                }
                $sections[] = [...$section, 'fields' => $fields];
            }
        }
        $chain = $site->fallbackChain($locale);
        $fallback = null;
        foreach (array_slice($chain, 1) as $code) {
            if ($entry->latestVersion($code) !== null) {
                $fallback = $code;
                break;
            }
        }
        $siteLocales = $site->locales;
        $targetLocale = $siteLocales->firstWhere('locale', $locale);
        $isRequired = $targetLocale !== null && (bool) $targetLocale->is_required;

        return Inertia::render('core::Translate', [
            'entry' => ['id' => $entry->id, 'type' => $entry->type, 'type_label' => $entry->pageType()?->label() ?? ($this->typeLabels($site)[$entry->type] ?? $entry->type), 'title' => $source->title ?? __('(ohne Titel)')],
            'site' => ['name' => $site->name, 'default_locale' => $site->default_locale],
            'source' => $source === null ? null : ['id' => $source->id, 'locale' => $source->locale, 'number' => $source->number, 'status' => $source->status->value, 'status_label' => $source->status->label()],
            'target' => ['locale' => $locale, 'number' => $target?->number, 'status' => $target?->status->value, 'status_label' => $target?->status->label(), 'is_required' => $isRequired, 'can_edit' => Gate::allows('update', $entry)],
            'state' => $detail['state'],
            'outdated_since' => $detail['since'] === null ? null : ['number' => $detail['since']->number, 'at' => $detail['since']->created_at?->timezone($site->timezone)->format('d.m.Y')],
            'fallback' => $fallback,
            'locales' => $siteLocales->map(fn ($l): array => ['locale' => $l->locale, 'is_default' => (bool) $l->is_default, 'is_required' => (bool) $l->is_required,
                'state' => $this->translations->state($entry, $l->locale),
                'url' => $l->is_default ? route('basalto.entries.edit', [$entry, $l->locale]) : route('basalto.entries.translation', [$entry, $l->locale])])->values()->all(),
            'sections' => $sections,
            'progress' => $progress,
            'urls' => [
                'save' => route('basalto.entries.translation.save', [$entry, $locale]),
                'create' => route('basalto.entries.translate', [$entry, $locale]),
                'editor' => route('basalto.entries.edit', [$entry, $locale]),
                'source_editor' => route('basalto.entries.edit', [$entry, $site->default_locale]),
                'paste' => route('basalto.media.paste'),
                'back' => $entry->pageType() !== null ? route('basalto.pages.index') : route('basalto.entries.index', ['type' => $entry->type]),
            ],
            'status' => session('status'),
        ]);
    }

    /** Speichern: neue Fassung der Zielsprache auf der angezeigten Quellfassung — damit ist sie «aktuell». */
    public function save(Request $request, Entry $entry, string $locale): RedirectResponse
    {
        Gate::authorize('update', $entry);
        $site = $entry->site;
        abort_unless(in_array($locale, $site->localeCodes(), true) && $locale !== $site->default_locale, 404);
        app(Access::class)->authorizeLocale($request->user(), $site, $locale);
        $source = EntryVersion::query()->whereKey((int) $request->input('source_version_id'))->where('entry_id', $entry->id)->where('locale', $site->default_locale)->first()
            ?? $entry->latestVersion($site->default_locale);
        abort_if($source === null, 404, __('Keine Fassung in der Standardsprache'));
        $values = $request->input('values', []);
        $values = is_array($values) ? $values : [];
        $rules = ['values' => ['array']];
        foreach ($this->translations->pairs($entry, $source) as $pair) {
            if (! $pair['translatable']) {
                continue;
            }
            $key = 'values.'.str_replace('.', '\\.', $pair['path']);
            $rules[$key] = $pair['type'] === 'richtext' ? ['nullable', static function (string $attribute, mixed $value, \Closure $fail): void {
                // Klartext gilt — das Feld kann vorher `textarea` gewesen sein
                if (! is_string($value) && RichText::doc($value) === null) {
                    $fail(__('Das ist kein Fliesstext.'));
                }
            }] : ['nullable', 'string', 'max:'.($pair['type'] === 'text' ? 300 : 5000)];
        }
        $validator = Validator::make(['values' => $values], $rules, [], ['values.title' => __('Titel')]);
        $validator->after(static function ($validator) use ($values): void {
            if (trim((string) ($values['title'] ?? '')) === '') {
                $validator->errors()->add('values.title', __('Der Titel der Übersetzung fehlt.'));
            }
        });
        $validator->validate();
        $target = $entry->latestVersion($locale);
        $data = $this->translations->compose($entry, $source, $target, $values);
        $author = $request->user()?->getAuthIdentifier();
        $version = $entry->saveDraft($locale, $data, is_numeric($author) ? (int) $author : null, $source->id);
        event(new EntrySaved($entry, $version));

        return redirect()->route('basalto.entries.translation', [$entry, $locale])->with('status', __('Übersetzung :locale gespeichert — Fassung :number auf Fassung :source (:source_locale).', ['locale' => strtoupper($locale), 'number' => $version->number, 'source' => $source->number, 'source_locale' => strtoupper($site->default_locale)]));
    }

    /** @return array<string, string> */
    private function typeLabels(Site $site): array
    {
        try {
            $schema = ThemeSchema::fromTheme($this->themes->forSite($site));
        } catch (ThemeException) {
            return [];
        }

        return array_map(static fn (array $t): string => $t['label'], $schema->types);
    }
}
