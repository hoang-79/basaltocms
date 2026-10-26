<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Forms\FormCatalog;
use Basalto\Core\Forms\FormDefinition;
use Basalto\Core\Forms\FormField;
use Basalto\Core\Jobs\DeliverFormSubmission;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Form;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\JsonText;
use Basalto\Core\Support\Preferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Eingänge eines Formulars (M-11, N-05, Mockup 16): Liste mit Status, Suche und Spam-Zähler, Eingang mit allen
 * Feldern und Dateien, «Mail erneut senden», als gelesen/beantwortet markieren, endgültig löschen, Export als CSV
 * (BOM, Semikolon — öffnet in Excel). Jede Löschung und jeder Export steht im Protokoll.
 */
final class FormInboxController
{
    public function __construct(private readonly CurrentSite $current, private readonly FormCatalog $catalog, private readonly Dates $dates, private readonly Recorder $recorder) {}

    public function index(Request $request, string $handle): Response
    {
        [$site, $form, $record] = $this->resolve($handle);
        $status = in_array($request->query('status'), FormSubmission::STATUSES, true) ? (string) $request->query('status') : '';
        $q = trim((string) $request->query('q', ''));
        $query = $this->query($record, $status, $q);
        $page = $query->orderByDesc('id')->paginate(Preferences::perPage($request->user()))->withQueryString();
        $relative = $this->dates->relativeFormatter();
        $counts = FormSubmission::query()->where('form_id', $record->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('core::Forms/Inbox', [
            'form' => ['handle' => $form->handle, 'label' => $form->label($site->default_locale), 'source' => $form->source(), 'spam' => $record->spam_count,
                'retention' => $form->retainDays((int) app(Settings::class)->get('forms.retention_days', 90, $site))],
            'rows' => array_map(fn (FormSubmission $s): array => $this->row($form, $s, $site, $relative), $page->items()),
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total(), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'counts' => ['new' => (int) ($counts['new'] ?? 0), 'read' => (int) ($counts['read'] ?? 0), 'answered' => (int) ($counts['answered'] ?? 0), 'all' => (int) $counts->sum()],
            'filters' => ['status' => $status, 'q' => $q],
            'urls' => ['export' => route('basalto.forms.inbox.export', $handle), 'bulk' => route('basalto.forms.inbox.bulk', $handle), 'edit' => route('basalto.forms.edit', $handle), 'index' => route('basalto.forms.index')],
        ]);
    }

    public function show(string $handle, int $submission): Response
    {
        [$site, $form, $record] = $this->resolve($handle);
        $item = FormSubmission::query()->where('form_id', $record->id)->findOrFail($submission);
        if ($item->status === 'new') {
            $item->forceFill(['status' => 'read'])->save();
        }
        $values = [];
        foreach ($form->inputs() as $field) {
            $value = $item->data[$field->name] ?? null;
            $file = $item->files[$field->name] ?? null;
            $values[] = ['name' => $field->name, 'label' => $field->label($site->default_locale), 'type' => $field->type, 'value' => self::text($field, $value),
                'file' => $file === null ? null : ['name' => $file['name'], 'size' => $file['size'], 'url' => route('basalto.forms.inbox.file', [$handle, $item->id, $field->name])]];
        }
        foreach (array_diff_key($item->data, array_flip(array_map(static fn (FormField $f): string => $f->name, $form->inputs()))) as $name => $value) {
            $values[] = ['name' => (string) $name, 'label' => __(':field (Feld entfernt)', ['field' => (string) $name]), 'type' => 'text', 'value' => self::text(null, $value), 'file' => null];
        }

        return Inertia::render('core::Forms/Submission', [
            'form' => ['handle' => $form->handle, 'label' => $form->label($site->default_locale)],
            'submission' => ['id' => $item->id, 'created_at' => $this->dates->dateTime($item->created_at), 'locale' => strtoupper($item->locale), 'page' => $item->page_path,
                'consent_at' => $this->dates->dateTime($item->consent_at), 'status' => $item->status, 'mail_status' => $item->mail_status, 'mail_error' => $item->mail_error,
                'webhook_status' => $item->webhook_status, 'webhook_error' => $item->webhook_error, 'values' => $values, 'reply_to' => $this->replyTo($form, $item)],
            'urls' => ['update' => route('basalto.forms.inbox.update', [$handle, $item->id]), 'destroy' => route('basalto.forms.inbox.destroy', [$handle, $item->id]),
                'resend' => $form->recipients() === [] ? null : route('basalto.forms.inbox.resend', [$handle, $item->id]), 'inbox' => route('basalto.forms.inbox', $handle)],
        ]);
    }

    public function update(Request $request, string $handle, int $submission): RedirectResponse
    {
        [, , $record] = $this->resolve($handle);
        $validated = $request->validate(['status' => ['required', 'in:'.implode(',', FormSubmission::STATUSES)]]);
        FormSubmission::query()->where('form_id', $record->id)->findOrFail($submission)->forceFill(['status' => $validated['status']])->save();

        return back();
    }

    public function destroy(string $handle, int $submission): RedirectResponse
    {
        [$site, , $record] = $this->resolve($handle);
        $item = FormSubmission::query()->where('form_id', $record->id)->findOrFail($submission);
        $this->delete($item);
        $this->recorder->record('form.submission_deleted', null, ['form' => $handle, 'id' => $submission], null, $site);

        return redirect()->route('basalto.forms.inbox', $handle)->with('status', __('Eingang endgültig gelöscht — samt Dateien.'));
    }

    /** Mehrere auf einmal: als gelesen markieren oder endgültig löschen. */
    public function bulk(Request $request, string $handle): RedirectResponse
    {
        [$site, , $record] = $this->resolve($handle);
        $validated = $request->validate(['action' => ['required', 'in:read,answered,delete'], 'ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        $items = FormSubmission::query()->where('form_id', $record->id)->whereIn('id', $validated['ids'])->get();
        foreach ($items as $item) {
            $validated['action'] === 'delete' ? $this->delete($item) : $item->forceFill(['status' => $validated['action']])->save();
        }
        if ($validated['action'] === 'delete') {
            $this->recorder->record('form.submissions_deleted', null, ['form' => $handle, 'count' => $items->count()], null, $site);
        }

        return back()->with('status', $validated['action'] === 'delete'
            ? trans_choice(':count Eingang endgültig gelöscht.|:count Eingänge endgültig gelöscht.', $items->count(), ['count' => $items->count()])
            : trans_choice(':count Eingang markiert.|:count Eingänge markiert.', $items->count(), ['count' => $items->count()]));
    }

    public function resend(string $handle, int $submission): RedirectResponse
    {
        [$site, , $record] = $this->resolve($handle);
        $item = FormSubmission::query()->where('form_id', $record->id)->findOrFail($submission);
        $item->forceFill(['mail_status' => 'pending', 'mail_error' => null])->save();
        DeliverFormSubmission::dispatch($site->id, $handle, $item->id, null, true);

        return back()->with('status', __('Mail wird erneut gesendet.'));
    }

    public function file(string $handle, int $submission, string $field): StreamedResponse
    {
        [, , $record] = $this->resolve($handle);
        $item = FormSubmission::query()->where('form_id', $record->id)->findOrFail($submission);
        $file = $item->files[$field] ?? null;
        abort_if($file === null || ! Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name']);
    }

    public function export(Request $request, string $handle): StreamedResponse
    {
        [$site, $form, $record] = $this->resolve($handle);
        $status = in_array($request->query('status'), FormSubmission::STATUSES, true) ? (string) $request->query('status') : '';
        $query = $this->query($record, $status, trim((string) $request->query('q', '')))->orderByDesc('id');
        $fields = $form->inputs();
        $this->recorder->record('form.exported', null, null, ['form' => $handle, 'count' => (clone $query)->count()], $site);
        $timezone = $this->dates->timezone();

        return response()->streamDownload(static function () use ($query, $fields, $site, $timezone): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}");
            fputcsv($out, array_merge([__('Datum'), __('Sprache'), __('Seite'), __('Status'), __('Zustimmung')], array_map(static fn (FormField $f): string => $f->label($site->default_locale), $fields)), ';', '"', '');
            $query->chunk(500, static function ($chunk) use ($out, $fields, $timezone): void {
                foreach ($chunk as $s) {
                    /** @var FormSubmission $s */
                    fputcsv($out, array_merge([$s->created_at?->timezone($timezone)->format('d.m.Y H:i'), strtoupper($s->locale), (string) $s->page_path, $s->status, $s->consent_at?->timezone($timezone)->format('d.m.Y H:i:s') ?? ''],
                        array_map(static fn (FormField $f): string => self::text($f, $s->data[$f->name] ?? null), $fields)), ';', '"', '');
                }
            });
            fclose($out);
        }, sprintf('eingaenge-%s-%s.csv', $handle, now()->format('Y-m-d')), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<FormSubmission> */
    private function query(Form $record, string $status, string $q): Builder
    {
        return FormSubmission::query()->where('form_id', $record->id)
            ->when($status !== '', static fn (Builder $b) => $b->where('status', $status))
            ->when($q !== '', static fn (Builder $b) => $b->where(JsonText::column('data', true), 'like', '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $q)).'%'));
    }

    /**
     * @param  \Closure(mixed): ?string  $relative
     * @return array<string, mixed>
     */
    private function row(FormDefinition $form, FormSubmission $s, Site $site, \Closure $relative): array
    {
        $inputs = $form->inputs();
        $sender = null;
        $email = $this->replyTo($form, $s);
        foreach ($inputs as $field) {
            if ($sender === null && $field->type === 'text' && is_string($s->data[$field->name] ?? null) && $s->data[$field->name] !== '') {
                $sender = $s->data[$field->name];
            }
        }
        $excerpt = '';
        foreach ($inputs as $field) {
            if ($field->type === 'textarea' && is_string($s->data[$field->name] ?? null)) {
                $excerpt = Str::limit($s->data[$field->name], 140);
                break;
            }
        }

        return ['id' => $s->id, 'created_at' => $relative($s->created_at), 'sender' => $sender ?? $email ?? '—', 'email' => $email, 'excerpt' => $excerpt, 'status' => $s->status,
            'mail_failed' => $s->mail_status === 'failed', 'mail_error' => $s->mail_error, 'files' => count($s->files ?? []), 'locale' => strtoupper($s->locale),
            'url' => route('basalto.forms.inbox.show', [$form->handle, $s->id])];
    }

    private function replyTo(FormDefinition $form, FormSubmission $s): ?string
    {
        foreach ($form->inputs() as $field) {
            $value = $s->data[$field->name] ?? null;
            if ($field->type === 'email' && is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return $value;
            }
        }

        return null;
    }

    private function delete(FormSubmission $item): void
    {
        foreach ($item->files ?? [] as $file) {
            Storage::disk('local')->delete($file['path']);
        }
        $item->delete();
    }

    private static function text(?FormField $field, mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['name'] ?? implode(', ', array_filter($value, 'is_scalar')));
        }
        if (is_bool($value) || $field?->type === 'checkbox') {
            return $value ? 'ja' : 'nein';
        }

        return trim((string) $value);
    }

    /** @return array{0: Site, 1: FormDefinition, 2: Form} */
    private function resolve(string $handle): array
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));
        $form = $this->catalog->find($site, $handle);
        $record = Form::query()->where('handle', $handle)->first();
        if ($form === null && $record === null) {
            abort(404);
        }

        return [$site, $form ?? abort(404, __('Das Formular gibt es im Theme nicht mehr — die Eingänge bleiben bis zum Löschlauf.')), $record ?? $this->catalog->record($site, $handle)];
    }
}
