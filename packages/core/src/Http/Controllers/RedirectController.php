<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\NotFoundHit;
use Basalto\Core\Models\Redirect;
use Basalto\Core\Models\Site;
use Basalto\Core\Seo\RedirectRules;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Umleitungen und 404-Protokoll im Seitenbaum (M-10): anlegen, ändern, entfernen, als CSV importieren; aus einem
 * 404-Treffer mit einem Klick eine Umleitung machen oder die Zeile verwerfen. Jede Änderung steht im Protokoll.
 */
final class RedirectController
{
    private const IMPORT_LIMIT = 5000;

    public function __construct(private readonly CurrentSite $current, private readonly RedirectRules $rules, private readonly Recorder $recorder) {}

    public function store(Request $request): RedirectResponse
    {
        $site = $this->authorize();
        $checked = $this->checked($request);
        [$redirect, $created] = $this->rules->save($site, $checked, Redirect::SOURCE_MANUAL);
        $this->recorder->record($created ? 'redirect.created' : 'redirect.updated', $redirect, null, ['from' => $redirect->from_path, 'to' => $redirect->to_path, 'status' => $redirect->status], $site);

        return back()->with('status', $created ? __('Umleitung :path angelegt.', ['path' => $redirect->from_path]) : __('Umleitung :path aktualisiert.', ['path' => $redirect->from_path]));
    }

    public function update(Request $request, Redirect $redirect): RedirectResponse
    {
        $site = $this->authorize();
        $before = ['from' => $redirect->from_path, 'to' => $redirect->to_path, 'status' => $redirect->status];
        $checked = $this->checked($request, $redirect->id);
        $clash = Redirect::query()->where('from_path', $checked['from'])->whereKeyNot($redirect->id)->exists();
        if ($clash) {
            throw ValidationException::withMessages(['from' => __('Für :path gibt es schon eine Umleitung.', ['path' => $checked['from']])]);
        }
        $this->rules->save($site, $checked, Redirect::SOURCE_MANUAL, $redirect);
        $this->recorder->record('redirect.updated', $redirect, $before, ['from' => $redirect->from_path, 'to' => $redirect->to_path, 'status' => $redirect->status], $site);

        return back()->with('status', __('Umleitung :path gespeichert.', ['path' => $redirect->from_path]));
    }

    public function destroy(Redirect $redirect): RedirectResponse
    {
        $site = $this->authorize();
        $redirect->delete();
        $this->recorder->record('redirect.deleted', $redirect, ['from' => $redirect->from_path, 'to' => $redirect->to_path, 'status' => $redirect->status], null, $site);

        return back()->with('status', __('Umleitung :path entfernt — die alte Adresse antwortet jetzt 404.', ['path' => $redirect->from_path]));
    }

    /**
     * CSV mit «alte Adresse; Ziel; Status» je Zeile — Trennzeichen Semikolon, Komma oder Tabulator, Kopfzeile erlaubt,
     * Status leer = 301. Gültige Zeilen werden übernommen, die anderen mit Zeilennummer und Grund gemeldet.
     */
    public function import(Request $request): RedirectResponse
    {
        $site = $this->authorize();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], [], ['file' => __('CSV-Datei')]);
        $text = (string) file_get_contents((string) $request->file('file')?->getRealPath());
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\R/', trim($text)) ?: [];
        $delimiter = self::delimiter($lines[0] ?? '');
        [$created, $updated, $errors] = [0, 0, []];
        foreach (array_slice($lines, 0, self::IMPORT_LIMIT) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, '"', ''));
            if ($i === 0 && RedirectRules::path($cells[0]) === null) {
                continue;
            }
            $statusCell = $cells[2] ?? '';
            $checked = $this->rules->check($site, $cells[0], $cells[1] ?? '', $statusCell === '' ? 301 : (int) $statusCell);
            if (isset($checked['error'])) {
                $errors[] = __('Zeile :line: :error', ['line' => $i + 1, 'error' => $checked['error']]);

                continue;
            }
            [, $isNew] = $this->rules->save($site, $checked, Redirect::SOURCE_IMPORT);
            $isNew ? $created++ : $updated++;
        }
        $this->recorder->record('redirect.imported', null, null, ['created' => $created, 'updated' => $updated, 'errors' => count($errors)], $site);
        $status = $errors === [] ? __('Import: :created neu, :updated aktualisiert.', ['created' => $created, 'updated' => $updated])
            : __('Import: :created neu, :updated aktualisiert, :skipped Zeile(n) übersprungen.', ['created' => $created, 'updated' => $updated, 'skipped' => count($errors)]);

        return back()->with('status', $status)->with('import_errors', array_slice($errors, 0, 50));
    }

    /** Eine Zeile aus dem 404-Protokoll verwerfen (z. B. ein Scanner) — kommt der Pfad wieder, zählt er neu. */
    public function dismissHit(NotFoundHit $hit): RedirectResponse
    {
        $site = $this->authorize();
        $hit->delete();
        $this->recorder->record('not_found.dismissed', null, ['path' => $hit->path, 'hits' => $hit->hits], null, $site);

        return back()->with('status', __(':path aus dem 404-Protokoll entfernt.', ['path' => $hit->path]));
    }

    /** @return array{from: string, to: string, status: int, locale: string} */
    private function checked(Request $request, ?int $exceptId = null): array
    {
        $request->validate(['from' => ['required', 'string', 'max:250'], 'to' => ['nullable', 'string', 'max:250'], 'status' => ['required', 'integer']],
            [], ['from' => __('Alte Adresse'), 'to' => __('Ziel'), 'status' => __('Status')]);
        $checked = $this->rules->check($this->current->require(), (string) $request->input('from'), (string) $request->input('to', ''), (int) $request->input('status'), $exceptId);
        if (isset($checked['error'])) {
            throw ValidationException::withMessages([$checked['field'] => $checked['error']]);
        }

        return $checked;
    }

    private function authorize(): Site
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));

        return $site;
    }

    private static function delimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
