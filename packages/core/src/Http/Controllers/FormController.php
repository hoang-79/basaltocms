<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Level;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Forms\FormBuilder;
use Basalto\Core\Forms\FormCatalog;
use Basalto\Core\Forms\FormDefinition;
use Basalto\Core\Forms\FormRenderer;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Form;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\JsonText;
use Basalto\Core\Theme\ThemeAssets;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Structure\Parser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Formulare im Backend (M-11, Mockup 16 und 29): Liste aus Theme- und Site-Formularen mit Eingängen, Spam und
 * «Eingebunden»; Baukasten mit vier Reitern (Felder, Aktionen, Spam und Aufbewahrung, Einbindung) über `forms.lava`,
 * Speichern selbständig (Autosave); Theme-Formulare nur zum Lesen mit Verweis auf die Lava-Zeile.
 *
 * @phpstan-import-type FormInput from FormBuilder
 */
final class FormController
{
    /** @return array<string, string> Feldtyp → Beschriftung im Baukasten, in der Sprache der Oberfläche */
    public static function fieldTypes(): array
    {
        return ['text' => __('Text'), 'email' => __('E-Mail'), 'number' => __('Zahl'), 'tel' => __('Telefon'), 'url' => __('URL'), 'date' => __('Datum'), 'textarea' => __('Mehrzeilig'),
            'select' => __('Auswahl'), 'radio' => __('Radio'), 'checkbox' => __('Checkbox'), 'file' => __('Datei'), 'range' => __('Bereich'), 'hidden' => __('Verstecktes Feld'), 'explanation' => __('Erklärung'), 'group' => __('Gruppe')];
    }

    public function __construct(private readonly CurrentSite $current, private readonly FormCatalog $catalog, private readonly FormBuilder $builder,
        private readonly Dates $dates, private readonly Recorder $recorder, private readonly ThemeLoader $themes) {}

    public function index(): Response
    {
        $site = $this->authorize();
        $records = Form::query()->get()->keyBy('handle');
        $counts = FormSubmission::query()->selectRaw('form_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as fresh, sum(case when mail_status = ? then 1 else 0 end) as failed', ['new', 'failed'])
            ->groupBy('form_id')->get()->keyBy('form_id');
        $forms = [];
        foreach ($this->catalog->all($site) as $form) {
            $record = $records->get($form->handle);
            $count = $record === null ? null : $counts->get($record->id);
            $forms[] = [
                'handle' => $form->handle, 'label' => $form->label($site->default_locale), 'source' => $form->source(), 'location' => $form->location(),
                'fields' => count($form->inputs()), 'actions' => $this->actionsOf($form),
                'submissions' => (int) ($count?->getAttribute('total') ?? 0), 'new' => (int) ($count?->getAttribute('fresh') ?? 0), 'mail_failed' => (int) ($count?->getAttribute('failed') ?? 0),
                'spam' => $record->spam_count ?? 0, 'last' => $this->dates->relative($record?->last_submission_at),
                'embedded' => array_map(static fn (array $e): string => $e['label'], $this->embedded($site, $form->handle)),
                'urls' => ['edit' => route('basalto.forms.edit', $form->handle), 'inbox' => route('basalto.forms.inbox', $form->handle)],
            ];
        }

        return Inertia::render('core::Forms/Index', ['forms' => $forms, 'urls' => ['create' => route('basalto.forms.create')]]);
    }

    public function create(): Response
    {
        $site = $this->authorize();

        return $this->builderPage($site, null, ['handle' => '', 'labels' => ['*' => ''], 'fields' => [
            ['name' => 'name', 'type' => 'text', 'required' => true, 'labels' => ['*' => 'Name']],
            ['name' => 'email', 'type' => 'email', 'required' => true, 'labels' => ['*' => 'E-Mail']],
            ['name' => 'nachricht', 'type' => 'textarea', 'required' => true, 'labels' => ['*' => 'Nachricht']],
        ], 'to' => '', 'subject' => '', 'thanks' => [], 'submit' => [], 'consent' => [], 'store' => true, 'redirect' => null, 'webhook' => null, 'retain' => null]);
    }

    public function edit(string $handle): Response
    {
        $site = $this->authorize();
        $form = $this->catalog->find($site, $handle) ?? abort(404);

        return $this->builderPage($site, $form, $this->builder->inputFor($form));
    }

    public function store(Request $request): RedirectResponse
    {
        $site = $this->authorize();
        $input = $this->input($request);
        $this->guardWebhook($site, null, $input);
        $this->check($site, $input, null);
        $this->builder->save($site, $input);
        $this->recorder->record('form.created', null, null, ['handle' => $input['handle']], $site);

        return redirect()->route('basalto.forms.edit', $input['handle'])->with('status', __('Formular «:handle» angelegt.', ['handle' => $input['handle']]));
    }

    public function update(Request $request, string $handle): RedirectResponse
    {
        $site = $this->authorize();
        $form = $this->catalog->find($site, $handle) ?? abort(404);
        abort_if($form->source() === 'theme', 403, __('Theme-Formulare ändert man im Theme (Station H).'));
        $input = $this->input($request);
        $this->guardWebhook($site, $form->webhookUrl(), $input);
        $this->check($site, $input, $handle);
        // Blöcke und Layouts zeigen über die Kennung aufs Formular — umbenannt hingen sie ins Leere
        if ($input['handle'] !== $handle && ($where = $this->embedded($site, $handle)) !== []) {
            throw ValidationException::withMessages(['handle' => __('Die Kennung bleibt, solange das Formular eingebunden ist (:where). Dort zuerst ein anderes Formular wählen.', ['where' => implode(', ', array_column($where, 'label'))])]);
        }
        $this->builder->save($site, $input, $handle);
        if ($input['handle'] !== $handle) {
            Form::query()->where('handle', $handle)->update(['handle' => $input['handle']]);
        }
        $this->recorder->record('form.updated', null, ['handle' => $handle], ['handle' => $input['handle']], $site);

        return $input['handle'] !== $handle ? redirect()->route('basalto.forms.edit', $input['handle']) : back();
    }

    public function destroy(string $handle): RedirectResponse
    {
        $site = $this->authorize();
        $form = $this->catalog->find($site, $handle) ?? abort(404);
        abort_if($form->source() === 'theme', 403, __('Theme-Formulare entfernt man im Theme (Station H).'));
        $this->builder->delete($site, $handle);
        $this->recorder->record('form.deleted', null, ['handle' => $handle], null, $site);

        return redirect()->route('basalto.forms.index')->with('status', __('Formular «:handle» entfernt — die Eingänge bleiben bis zum Löschlauf.', ['handle' => $handle]));
    }

    /** Neues Webhook-Geheimnis — das alte gilt sofort nicht mehr. */
    public function secret(string $handle): RedirectResponse
    {
        $site = $this->authorize();
        $this->catalog->find($site, $handle) ?? abort(404);
        $this->catalog->record($site, $handle)->forceFill(['webhook_secret' => Str::random(40)])->save();
        $this->recorder->record('form.secret_renewed', null, null, ['handle' => $handle], $site);

        return back()->with('status', __('Neues Webhook-Geheimnis erzeugt — beim Empfänger nachtragen.'));
    }

    /** @param  FormInput  $input */
    private function builderPage(Site $site, ?FormDefinition $form, array $input): Response
    {
        $record = $form === null ? null : Form::query()->where('handle', $form->handle)->first();

        return Inertia::render('core::Forms/Form', [
            'form' => $input,
            'meta' => $form === null ? null : ['source' => $form->source(), 'location' => $form->location(), 'lava' => $this->builder->lavaOf($form),
                'submissions' => $record === null ? 0 : FormSubmission::query()->where('form_id', $record->id)->count(), 'spam' => $record->spam_count ?? 0,
                'secret' => Gate::allows('area', [$site, 'forms', Level::All]) ? $record?->webhook_secret : null, 'embedded' => $this->embedded($site, $form->handle)],
            'field_types' => array_map(static fn (string $type, string $label): array => ['value' => $type, 'label' => $label], array_keys(self::fieldTypes()), self::fieldTypes()),
            'locales' => array_map(static fn (string $code): array => ['code' => $code, 'default' => $code === $site->default_locale], $site->localeCodes()),
            'retention_default' => (int) app(Settings::class)->get('forms.retention_days', 90, $site),
            'urls' => ['save' => $form === null ? route('basalto.forms.store') : route('basalto.forms.update', $form->handle), 'index' => route('basalto.forms.index'),
                'delete' => $form === null || $form->source() === 'theme' ? null : route('basalto.forms.destroy', $form->handle),
                'secret' => $form === null ? null : route('basalto.forms.secret', $form->handle), 'inbox' => $form === null ? null : route('basalto.forms.inbox', $form->handle),
                'preview' => route('basalto.forms.preview')],
        ]);
    }

    /** @return list<string> */
    private function actionsOf(FormDefinition $form): array
    {
        return array_values(array_filter([$form->recipients() !== [] ? (string) __('Mail') : null, $form->stores() ? (string) __('Speichern') : null, $form->redirectTarget() !== null ? (string) __('Weiterleitung') : null, $form->webhookUrl() !== null ? 'Webhook' : null]));
    }

    /**
     * Wo das Formular steht: Seiten mit dem Block «Formular» (neueste Fassung je Sprache) und Lava-Teile in Layouts.
     *
     * @return list<array{label: string, url: string|null}>
     */
    private function embedded(Site $site, string $handle): array
    {
        $column = JsonText::column('entry_versions.data');
        $versions = EntryVersion::query()->whereHas('entry', static fn ($q) => $q->withoutGlobalScopes()->where('site_id', $site->id))
            ->where(static fn ($q) => $q->where($column, 'like', '%"form":"'.$handle.'"%')->orWhere($column, 'like', '%"form": "'.$handle.'"%'))
            ->orderByDesc('number')->get(['id', 'entry_id', 'locale', 'title', 'number', 'status']);
        $out = [];
        foreach ($versions->unique(static fn (EntryVersion $v): string => $v->entry_id.':'.$v->locale) as $version) {
            $out[] = ['label' => __(':title (:locale) · Block «Formular»', ['title' => $version->title, 'locale' => strtoupper($version->locale)]), 'url' => route('basalto.entries.edit', [$version->entry_id, $version->locale])];
        }
        try {
            foreach ($this->themes->forSite($site)->document->all('part') as $part) {
                if ($part->typedFirst() === 'form' && $part->value('source') === $handle) {
                    $out[] = ['label' => __('Lava-Teil «:name» (:location)', ['name' => (string) $part->name, 'location' => $part->file.':'.$part->line]), 'url' => null];
                }
            }
        } catch (Throwable) {
            // ohne lesbares Theme keine Teile
        }

        return $out;
    }

    /**
     * Live-Vorschau des Baukastens (TB 31): das Formular, wie Besucher es sehen — mit den Stilen des Themes und
     * Feldmarken, damit Baukasten und Vorschau einander zeigen, wo ein Feld steht. Gespeichert wird nichts.
     */
    public function preview(Request $request, FormRenderer $renderer, ThemeAssets $assets): HttpResponse
    {
        $site = $this->authorize();
        $locale = in_array($request->input('locale'), $site->localeCodes(), true) ? (string) $request->input('locale') : $site->default_locale;
        $handle = trim((string) $request->input('handle'));
        /** @var FormInput $input */
        $input = ['handle' => preg_match('/^[a-z][a-z0-9_]{1,59}$/', $handle) === 1 ? $handle : 'vorschau', 'labels' => (array) $request->input('labels', []),
            'fields' => array_values((array) $request->input('fields', [])), 'thanks' => (array) $request->input('thanks', []), 'submit' => (array) $request->input('submit', []),
            'consent' => (array) $request->input('consent', [])];
        try {
            $definition = Parser::parse($this->builder->lavaFor($input), 'vorschau.lava')->definitions[0] ?? null;
            $body = $definition === null ? '' : $renderer->withMarks()->render(FormDefinition::fromDefinition($definition), $locale, '/', Request::create('/'));
        } catch (Throwable) {
            $body = sprintf('<p class="form-explanation">%s</p>', e(__('Die Vorschau erscheint, sobald jedes Feld einen gültigen Namen hat.')));
        }
        $title = e(trim((string) ($input['labels']['*'] ?? '')) ?: __('Vorschau'));
        $html = sprintf('<!doctype html><html lang="%s"><head>%s%s<title>%s</title></head><body><main id="inhalt" class="form-preview" style="padding:2rem 1.25rem">'
            .'<div class="wrap" style="max-width:42rem;margin:0 auto"><h2>%s</h2>%s</div></main></body></html>', e($locale), EntryRenderer::assets(), $assets->head($site), $title, $title, $body);

        return response($html)->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    /** @return FormInput */
    private function input(Request $request): array
    {
        $request->validate(['handle' => ['required', 'string', 'max:60'], 'fields' => ['array'], 'labels' => ['array'], 'to' => ['nullable', 'string', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:200'], 'thanks' => ['array'], 'submit' => ['array'], 'consent' => ['array'], 'store' => ['boolean'],
            'redirect' => ['nullable', 'string', 'max:250'], 'webhook' => ['nullable', 'string', 'max:500'], 'retain' => ['nullable']], [], ['handle' => __('Kennung')]);

        /** @var FormInput $input */
        $input = ['handle' => trim((string) $request->input('handle')), 'labels' => (array) $request->input('labels', []), 'fields' => array_values((array) $request->input('fields', [])),
            'to' => $request->input('to'), 'subject' => $request->input('subject'), 'thanks' => (array) $request->input('thanks', []), 'submit' => (array) $request->input('submit', []),
            'consent' => (array) $request->input('consent', []), 'store' => $request->boolean('store', true), 'redirect' => $request->input('redirect'),
            'webhook' => $request->input('webhook'), 'retain' => $request->input('retain')];

        return $input;
    }

    /**
     * Der Webhook schickt Eingänge an fremde Server — ändern nur mit «Formulare · alles» (CR-005, R-07).
     *
     * @param  FormInput  $input
     */
    private function guardWebhook(Site $site, ?string $current, array $input): void
    {
        $wanted = is_string($input['webhook'] ?? null) && trim($input['webhook']) !== '' ? trim($input['webhook']) : null;
        abort_if($wanted !== $current && ! Gate::allows('area', [$site, 'forms', Level::All]), 403, __('Den Webhook eines Formulars ändert nur, wer im Bereich Formulare alles darf.'));
    }

    /** @param  FormInput  $input */
    private function check(Site $site, array $input, ?string $current): void
    {
        $errors = $this->builder->validate($site, $input, $current);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function authorize(): Site
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));

        return $site;
    }
}
