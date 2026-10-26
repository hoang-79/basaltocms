<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Forms\FormCatalog;
use Basalto\Core\Forms\FormDefinition;
use Basalto\Core\Forms\FormField;
use Basalto\Core\Forms\FormRenderer;
use Basalto\Core\Forms\SpamGuard;
use Basalto\Core\Jobs\DeliverFormSubmission;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Versand eines Formulars aus dem Frontend (M-11): Spam still verwerfen und zählen, Felder nach ihrem Typ prüfen,
 * Zustimmung verlangen (N-05), speichern (Dateien privat), Mail und Webhook als Auftrag. Fehler und Danke gehen über
 * eine Adresse mit Abfrage zurück zur Seite — sie rendert dann frisch, nicht aus dem Seiten-Cache. Mit
 * `Accept: application/json` (das Inline-Skript des Formulars, M-17) kommt dasselbe als JSON: Danke-Text und allenfalls
 * Weiterleitung, oder 422 mit Feldfehlern. Spam bekommt auch hier die Antwort eines Menschen.
 */
final class FormSubmissionController
{
    public function __construct(private readonly CurrentSite $current, private readonly FormCatalog $catalog, private readonly SpamGuard $guard) {}

    public function __invoke(Request $request, string $handle): RedirectResponse|JsonResponse
    {
        $site = $this->current->get();
        $form = $site === null ? null : $this->catalog->find($site, $handle);
        abort_if($site === null || $form === null, 404);
        $page = self::pagePath((string) $request->input('_seite', '/'));
        $lang = in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
        $record = $this->catalog->record($site, $handle);
        $thanks = $this->target($form, $page, $handle);
        $json = $request->wantsJson();

        if ($this->guard->reason($request, $handle, $site->id, 'formular') !== null) {
            // Still: Wer Spam schickt, sieht dasselbe wie ein Mensch — gespeichert wird nichts, nur gezählt
            $record->forceFill(['spam_count' => $record->spam_count + 1, 'spam_last_at' => now()])->save();

            return $json ? $this->sent($form, $lang) : redirect($thanks);
        }

        $validator = Validator::make($request->all(), $this->rules($form), $this->messages($lang), $this->attributes($form, $lang));
        if ($validator->fails() && $json) {
            return response()->json(['message' => FormRenderer::errorSummary($lang), 'errors' => $validator->errors()->toArray()], 422);
        }
        if ($validator->fails()) {
            return redirect($page.'?formular='.$handle.'#formular-'.$handle)->withErrors($validator, 'form_'.$handle)->withInput($request->except(array_merge(['_t', SpamGuard::HONEYPOT], $this->fileNames($form))));
        }

        $data = [];
        $files = [];
        $folder = sprintf('forms/%d/%s/%s', $site->id, $handle, Str::uuid());
        foreach ($form->inputs() as $field) {
            if ($field->type === 'file') {
                $file = $request->file($field->name);
                if ($file instanceof UploadedFile && $form->stores()) {
                    $name = Str::limit((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->getClientOriginalName()), 120, '');
                    $files[$field->name] = ['path' => (string) $file->storeAs($folder, $name, 'local'), 'name' => $name, 'size' => (int) $file->getSize(), 'mime' => (string) $file->getMimeType()];
                    $data[$field->name] = ['name' => $name];
                }

                continue;
            }
            $value = $request->input($field->name);
            $data[$field->name] = $field->type === 'checkbox' ? (bool) $value : (is_scalar($value) ? trim((string) $value) : null);
        }
        $record->forceFill(['last_submission_at' => now()])->save();
        $payload = ['site_id' => $site->id, 'form_id' => $record->id, 'locale' => $lang, 'page_path' => $page, 'data' => $data, 'files' => $files === [] ? null : $files,
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), 'consent_at' => now(), 'status' => 'new',
            'mail_status' => $form->recipients() === [] ? 'none' : 'pending'];
        if ($form->stores()) {
            $submission = FormSubmission::query()->create($payload);
            DeliverFormSubmission::dispatch($site->id, $handle, $submission->id);
        } else {
            DeliverFormSubmission::dispatch($site->id, $handle, null, ['locale' => $lang, 'page_path' => $page, 'data' => $data, 'created_at' => now()->toIso8601String()]);
        }

        return $json ? $this->sent($form, $lang) : redirect($thanks);
    }

    /** Antwort für das Skript: Danke-Text der Sprache und — nur mit `redirect` im Formular — wohin es danach geht. */
    private function sent(FormDefinition $form, string $lang): JsonResponse
    {
        $target = $form->redirectTarget();

        return response()->json(['message' => FormRenderer::thanks($form, $lang), 'redirect' => $target === null || $target === '' ? null : $this->target($form, '/', $form->handle)]);
    }

    /** @return array<string, mixed> */
    private function rules(FormDefinition $form): array
    {
        $rules = ['_consent' => ['accepted']];
        foreach ($form->inputs() as $field) {
            $base = $field->required ? ['required'] : ['nullable'];
            $max = $field->number('max');
            $min = $field->number('min');
            $rules[$field->name] = array_merge($base, match ($field->type) {
                'email' => ['string', 'email', 'max:254'],
                'number', 'range' => array_filter(['numeric', $min !== null ? 'min:'.$min : null, $max !== null ? 'max:'.$max : null]),
                'tel' => ['string', 'max:40', 'regex:/^[0-9 +()\/.-]+$/'],
                'url' => ['string', 'url', 'max:500'],
                'date' => ['date'],
                'textarea' => array_filter(['string', 'max:'.(int) ($max ?? 5000), $min !== null ? 'min:'.(int) $min : null]),
                'select', 'radio' => ['string', Rule::in(self::allOptions($field))],
                'checkbox' => $field->required ? ['accepted'] : ['boolean'],
                'file' => ['file', 'mimes:'.implode(',', $field->fileTypes()), 'max:'.(int) (($field->number('size') ?? 10) * 1024)],
                'hidden' => ['string', 'max:500'],
                default => array_filter(['string', 'max:'.(int) ($max ?? 500), $min !== null ? 'min:'.(int) $min : null]),
            });
        }

        return $rules;
    }

    /**
     * Optionen über alle Sprachen — gesendet wird die Beschriftung der Sprache, in der das Formular stand.
     *
     * @return list<string>
     */
    private static function allOptions(FormField $field): array
    {
        $all = [];
        foreach (['de', 'en', 'it', 'fr'] as $lang) {
            $all = array_merge($all, $field->options($lang));
        }

        return array_values(array_unique($all));
    }

    /** @return array<string, string> */
    private function attributes(FormDefinition $form, string $lang): array
    {
        $out = ['_consent' => $lang === 'en' ? 'consent' : 'Zustimmung'];
        foreach ($form->inputs() as $field) {
            $out[$field->name] = $field->label($lang);
        }

        return $out;
    }

    /** @return array<string, string> */
    private function messages(string $lang): array
    {
        return $lang === 'en' ? ['_consent.accepted' => 'Please agree to your details being stored.'] : ['_consent.accepted' => 'Bitte der Speicherung Ihrer Angaben zustimmen.'];
    }

    /** @return list<string> */
    private function fileNames(FormDefinition $form): array
    {
        return array_values(array_map(static fn (FormField $f): string => $f->name, array_filter($form->inputs(), static fn (FormField $f): bool => $f->type === 'file')));
    }

    /** Weiterleitung nach dem Senden: Seite aus `redirect` (Pfad, Adresse oder Seitenname), sonst zurück mit Danke. */
    private function target(FormDefinition $form, string $page, string $handle): string
    {
        $target = $form->redirectTarget();
        if ($target === null || $target === '') {
            return $page.'?gesendet='.$handle.'#formular-'.$handle;
        }
        if (preg_match('#^https?://#i', $target) === 1) {
            return $target;
        }

        return str_starts_with($target, '/') ? $target : '/'.trim($target, '/');
    }

    /** Nur ein Pfad dieser Site — sonst wäre `_seite` eine offene Weiterleitung. */
    private static function pagePath(string $value): string
    {
        $path = (string) parse_url($value, PHP_URL_PATH);

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';
    }
}
