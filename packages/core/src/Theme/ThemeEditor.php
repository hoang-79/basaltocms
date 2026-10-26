<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Jobs\GenerateMediaVariants;
use Basalto\Core\Media\ImageVariant;
use Basalto\Core\Media\ImageVariants;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteTheme;
use Basalto\Core\Models\ThemeDraft;
use Basalto\Core\Models\ThemeVersion;
use Basalto\Core\Settings\Settings;
use Basalto\Lava\Diagnostics\Diagnostic;
use Basalto\Lava\Lsp\LanguageService;
use Basalto\Lava\Theme\DatabaseSource;
use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\LavaSource;
use Basalto\Lava\Theme\Source;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Themes in der Datenbank bearbeiten (L-17): Eine Site übernimmt ihr Theme aus dem Ordner in `lava_sources`, ändert
 * Dateien als Entwürfe (`theme_drafts`), sieht sie in der Vorschau, reicht sie ein und veröffentlicht sie — mit
 * Vier-Augen, wenn die Site es verlangt. Jede Veröffentlichung legt eine Fassung ab (`theme_versions`), aus der sich
 * der frühere Stand wieder als Entwurf holen lässt. Fehler meldet der Compiler vor dem Speichern, nicht erst live.
 *
 * Seit TB 22 (Theme-Bibliothek) hat eine Site mehrere eigene Themes: Jede Methode nimmt ein `SiteTheme` — oder die
 * Site, dann gilt das Theme, das sie gerade zeigt (eigenes oder Ordner).
 *
 * @phpstan-type Finding array{file: string, line: int, column: int, severity: string, message: string}
 */
final class ThemeEditor
{
    /** Bearbeitbar sind Textdateien eines Themes — Binäres bleibt im Ordner. */
    public const EDITABLE = '/^[A-Za-z0-9_\-][A-Za-z0-9_\-.]*(\/[A-Za-z0-9_\-][A-Za-z0-9_\-.]*)*\.(lava|html|json|css|js|svg|txt|md)$/';

    public const MAX_BYTES = 524288;

    /** Wie viele Bilder die letzte Veröffentlichung neu schneiden liess (geänderte Bildvarianten, TB 10 Scheibe 3). */
    public int $regenerated = 0;

    public function __construct(private readonly ThemeLoader $themes, private readonly LanguageService $language, private readonly Recorder $recorder, private readonly Settings $settings, private readonly ThemeLibrary $library) {}

    /** Zeigt die Site ein eigenes Theme aus ihrer Bibliothek (statt eines Ordners)? */
    public function isDatabaseTheme(Site $site): bool
    {
        return $this->library->active($site) !== null;
    }

    public static function isEditablePath(string $path): bool
    {
        return FileSource::isInside($path) && ! str_starts_with($path, LayeredSource::PREFIX) && preg_match(self::EDITABLE, $path) === 1;
    }

    /**
     * Die veröffentlichten Textdateien des Themes (Datenbank oder Ordner).
     *
     * @return array<string, string>
     */
    public function liveFiles(Site|SiteTheme $subject): array
    {
        $theme = $this->theme($subject);
        if ($theme !== null) {
            return $this->library->textFiles($theme);
        }
        $source = $this->themes->sourceFor($this->site($subject), false)['source'];
        $files = [];
        foreach ($source->paths() as $path) {
            $content = $source->read($path);
            if (mb_check_encoding($content, 'UTF-8')) {
                $files[$path] = $content;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Offene Entwürfe: Pfad → neuer Inhalt, `null` = Datei fällt weg.
     *
     * @return array<string, string|null>
     */
    public function draftChanges(Site|SiteTheme $subject): array
    {
        $theme = $this->theme($subject);

        return $theme === null ? [] : ThemeDraft::query()->where('site_theme_id', $theme->id)->orderBy('path')->pluck('source', 'path')->all();
    }

    /**
     * Das Theme, wie es mit den Entwürfen und optional einem noch ungespeicherten Puffer aussähe — mit der Site-Schicht.
     *
     * @param  array<string, string|null>  $buffers
     */
    public function draftSource(Site|SiteTheme $subject, array $buffers = []): Source
    {
        $site = $this->site($subject);
        $own = $this->theme($subject);
        $base = $own !== null ? $this->library->textSource($own) : $this->themes->sourceFor($site, false)['source'];
        $theme = ThemeLoader::overlay($base, [...$this->draftChanges($subject), ...$buffers], $site->handle);
        $siteLayer = new DatabaseSource($site->id, LavaSource::SCOPE_SITE, $site->handle.'/site');

        return $siteLayer->paths() === [] ? $theme : new LayeredSource($theme, $siteLayer);
    }

    /**
     * Befunde des Compilers für das Theme mit einem Puffer darüber — Fehler und Warnungen aller Dateien.
     *
     * @param  array<string, string|null>  $buffers
     * @return list<Finding>
     */
    public function check(Site|SiteTheme $subject, array $buffers = []): array
    {
        $out = [];
        foreach ($this->language->diagnostics($this->draftSource($subject, $buffers)) as $file => $diagnostics) {
            foreach ($diagnostics as $diagnostic) {
                $out[] = ['file' => (string) $file, 'line' => $diagnostic->line, 'column' => $diagnostic->column, 'severity' => $diagnostic->severity, 'message' => LanguageService::message($diagnostic)];
            }
        }

        return $out;
    }

    /** Übernimmt das Theme aus dem Ordner in die Datenbank — Fassung 1 hält den Ausgangsstand fest. */
    public function adopt(Site $site, Authenticatable $user): ThemeVersion
    {
        $theme = $this->library->adoptFolder($site, $user);
        $this->themes->forget();

        return ThemeVersion::query()->where('site_theme_id', $theme->id)->orderBy('number')->firstOrFail();
    }

    /**
     * Zurück zum Theme-Ordner: Das eigene Theme gleichen Namens bleibt mit Entwürfen und Fassungen in der Bibliothek,
     * nur unter neuem Namen (`<name>-eigen`) — die Site zeigt wieder den Ordner.
     */
    public function release(Site|SiteTheme $subject, Authenticatable $user): SiteTheme
    {
        $theme = $this->requireTheme($subject);
        if (! ThemeLibrary::hasFolder($theme->name)) {
            throw new ThemeEditorException(__('Zu «:name» gibt es keinen Theme-Ordner, auf den die Site zurück könnte.', ['name' => $theme->name]));
        }
        $before = $theme->name;
        $theme->forceFill(['name' => $this->library->uniqueName($theme->site, $before.'-eigen'), 'label' => __(':name (eigene Fassung)', ['name' => $theme->label ?? $before])])->save();
        $this->themes->forget();
        $this->recorder->record('theme.release', $theme->site, ['theme' => $before], ['theme' => $theme->name], $theme->site, $user);

        return $theme;
    }

    /**
     * Speichert einen Entwurf — nur, wenn das Theme damit fehlerfrei kompiliert. `$base` ist die Prüfsumme des Textes,
     * den der Editor geladen hat: Hat jemand anderes inzwischen gespeichert, gibt es einen Konflikt statt eines
     * stillen Überschreibens.
     *
     * @return list<Finding> verbleibende Warnungen
     */
    public function saveDraft(Site|SiteTheme $subject, string $path, ?string $content, Authenticatable $user, ?string $base = null): array
    {
        $theme = $this->requireTheme($subject);
        $site = $theme->site;
        if (! self::isEditablePath($path)) {
            throw new ThemeEditorException(__('«:path» ist kein bearbeitbarer Pfad (Textdatei im Theme, z. B. templates/seite.lava).', ['path' => $path]));
        }
        if ($content !== null && (strlen($content) > self::MAX_BYTES || ! mb_check_encoding($content, 'UTF-8'))) {
            throw new ThemeEditorException(__('Die Datei ist zu gross oder kein gültiger Text (höchstens 512 KB, UTF-8).'));
        }
        if ($path === 'theme.lava' && $content === null) {
            throw new ThemeEditorException('theme.lava ist der Einstieg des Themes und lässt sich nicht löschen.');
        }
        $current = $this->currentText($theme, $path);
        if ($base !== null && ! hash_equals($base, hash('sha256', $current ?? ''))) {
            throw new ThemeEditorException(__('Die Datei wurde inzwischen von jemand anderem gespeichert — bitte neu laden und die Änderung wiederholen.'), status: 409);
        }
        $findings = $this->check($theme, [$path => $content]);
        $errors = array_values(array_filter($findings, static fn (array $f): bool => $f['severity'] === Diagnostic::ERROR));
        if ($errors !== []) {
            throw new ThemeEditorException(__('Nicht gespeichert: :count Fehler im Theme. :first', ['count' => count($errors), 'first' => $errors[0]['file'].':'.$errors[0]['line'].' '.$errors[0]['message']]), $errors);
        }
        $live = $this->liveFiles($theme)[$path] ?? null;
        if ($content === $live) {
            ThemeDraft::query()->where('site_theme_id', $theme->id)->where('path', $path)->delete();
        } else {
            ThemeDraft::query()->updateOrCreate(['site_id' => $site->id, 'site_theme_id' => $theme->id, 'path' => $path], [
                'source' => $content, 'base_hash' => hash('sha256', $live ?? ''), 'updated_by' => (int) $user->getAuthIdentifier(), 'submitted_at' => null, 'submitted_by' => null,
            ]);
        }
        $this->recorder->record('theme.draft', $site, null, ['theme' => $theme->name, 'pfad' => $path, 'geloescht' => $content === null], $site, $user);

        return $findings;
    }

    /** Verwirft einen Entwurf oder alle. */
    public function discard(Site|SiteTheme $subject, ?string $path, Authenticatable $user): int
    {
        $theme = $this->theme($subject);
        if ($theme === null) {
            return 0;
        }
        $site = $theme->site;
        $n = ThemeDraft::query()->where('site_theme_id', $theme->id)->when($path !== null, fn ($q) => $q->where('path', $path))->delete();
        if ($n > 0) {
            $this->recorder->record('theme.discard', $site, null, ['theme' => $theme->name, 'pfad' => $path ?? 'alle', 'anzahl' => $n], $site, $user);
        }

        return (int) $n;
    }

    /** Reicht alle Entwürfe zur Freigabe ein. */
    public function submit(Site|SiteTheme $subject, Authenticatable $user): int
    {
        $theme = $this->requireTheme($subject);
        $site = $theme->site;
        $n = ThemeDraft::query()->where('site_theme_id', $theme->id)->update(['submitted_at' => now(), 'submitted_by' => (int) $user->getAuthIdentifier()]);
        if ($n === 0) {
            throw new ThemeEditorException(__('Es gibt keine Entwürfe zum Einreichen.'));
        }
        $this->recorder->record('theme.submit', $site, null, ['theme' => $theme->name, 'dateien' => $n], $site, $user);

        return $n;
    }

    public function requiresFourEyes(Site $site): bool
    {
        return $this->settings->get('four_eyes', false, $site) === true;
    }

    /** Veröffentlicht alle Entwürfe auf einmal — fehlerfrei kompiliert, mit Vier-Augen, als neue Fassung. */
    public function publish(Site|SiteTheme $subject, Authenticatable $user, ?string $note = null): ThemeVersion
    {
        $theme = $this->requireTheme($subject);
        $site = $theme->site;
        $drafts = ThemeDraft::query()->where('site_theme_id', $theme->id)->get();
        if ($drafts->isEmpty()) {
            throw new ThemeEditorException(__('Es gibt keine Entwürfe zum Veröffentlichen.'));
        }
        $userId = (int) $user->getAuthIdentifier();
        if ($this->requiresFourEyes($site)) {
            if ($drafts->contains(fn (ThemeDraft $d): bool => $d->submitted_at === null)) {
                throw new ThemeEditorException(__('Vier-Augen-Prinzip: Die Entwürfe müssen zuerst eingereicht werden.'), status: 403);
            }
            if ($drafts->contains(fn (ThemeDraft $d): bool => $d->submitted_by === $userId || $d->updated_by === $userId)) {
                throw new ThemeEditorException(__('Vier-Augen-Prinzip: Wer Entwürfe geschrieben oder eingereicht hat, gibt sie nicht selbst frei.'), status: 403);
            }
        }
        $errors = array_values(array_filter($this->check($theme), static fn (array $f): bool => $f['severity'] === Diagnostic::ERROR));
        if ($errors !== []) {
            throw new ThemeEditorException(__('Nicht veröffentlicht: Das Theme hat mit den Entwürfen Fehler.'), $errors);
        }
        // Bildvarianten zählen nur beim Theme, das die Site gerade zeigt
        $active = ThemeLibrary::activeName($site) === $theme->name;
        $variantsBefore = $active ? $this->variantSignature($site) : '';

        $version = DB::transaction(function () use ($theme, $site, $drafts, $user, $note): ThemeVersion {
            foreach ($drafts as $draft) {
                if ($draft->source === null) {
                    LavaSource::query()->where('site_id', $site->id)->where('scope', $theme->scope())->where('path', $draft->path)->delete();
                } else {
                    LavaSource::query()->updateOrCreate(['site_id' => $site->id, 'scope' => $theme->scope(), 'path' => $draft->path], ['source' => $draft->source]);
                }
            }
            $first = $drafts->firstOrFail();
            $submittedBy = $first->submitted_by ?? $first->updated_by;
            ThemeDraft::query()->where('site_theme_id', $theme->id)->delete();
            $this->themes->forget();
            $version = $this->library->snapshot($theme, $note, $submittedBy, $user);
            $theme->touch();
            $this->recorder->record('theme.publish', $site, null, ['theme' => $theme->name, 'fassung' => $version->number, 'dateien' => $drafts->pluck('path')->all()], $site, $user);

            return $version;
        });
        // TB 10 Scheibe 3 (K-09): Haben sich Bildvarianten geändert, werden alle Bilder der Site in der Queue neu geschnitten
        $this->regenerated = ! $active || $variantsBefore === $this->variantSignature($site) ? 0 : $this->regenerateVariants($site);

        return $version;
    }

    private function variantSignature(Site $site): string
    {
        $this->themes->forget();

        return (string) json_encode(array_map(static fn (ImageVariant $v): array => $v->toArray(), app(ImageVariants::class)->forSite($site)));
    }

    private function regenerateVariants(Site $site): int
    {
        $ids = Media::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('mime', 'like', 'image/%')->whereNull('deleted_at')->pluck('id');
        foreach ($ids as $id) {
            GenerateMediaVariants::dispatch((int) $id);
        }

        return $ids->count();
    }

    /**
     * Holt eine frühere Fassung als Entwürfe zurück — veröffentlicht wird sie wie jede Änderung.
     *
     * @return int Anzahl Entwürfe
     */
    public function restore(Site|SiteTheme $subject, ThemeVersion $version, Authenticatable $user): int
    {
        $theme = $this->requireTheme($subject);
        $site = $theme->site;
        abort_unless($version->site_theme_id === $theme->id, 404);
        $n = $this->replaceDrafts($theme, $version->files, $user);
        $this->recorder->record('theme.restore', $site, null, ['theme' => $theme->name, 'fassung' => $version->number, 'entwuerfe' => $n], $site, $user);

        return $n;
    }

    /**
     * Ein Theme-Paket als Entwürfe einlesen: geprüft (Manifest, Prüfsummen, Signatur falls vorhanden), dann wie jede
     * Änderung durch Vorschau und Freigabe. Bilder und Schriften aus dem Paket gelten sofort — sie haben keine Entwürfe.
     *
     * @return array{drafts: int, name: string, signed: bool}
     */
    public function import(Site|SiteTheme $subject, string $zip, Authenticatable $user): array
    {
        $verified = ThemeLibrary::verifyPackage($zip);
        $files = ThemeLibrary::packageFiles($zip);
        if (! isset($files['text']['theme.lava'])) {
            throw new ThemeEditorException(__('Im Paket fehlt theme.lava.'));
        }
        if ($subject instanceof Site && ! $this->isDatabaseTheme($subject)) {
            $this->adopt($subject, $user);
        }
        $theme = $this->requireTheme($subject);
        foreach ($files['binary'] as $path => $content) {
            $this->library->storeAsset($theme, $path, $content, $user);
        }
        $n = $this->replaceDrafts($theme, $files['text'], $user);
        $this->recorder->record('theme.import', $theme->site, null, ['theme' => $theme->name, 'paket' => $verified['manifest']['name'], 'signiert' => $verified['signed'], 'entwuerfe' => $n], $theme->site, $user);

        return ['drafts' => $n, 'name' => $verified['manifest']['name'], 'signed' => $verified['signed']];
    }

    /** Das veröffentlichte Theme als Paket (unsigniert — signieren kann nur der Hersteller). */
    public function export(Site|SiteTheme $subject, string $outputDir): string
    {
        $theme = $this->theme($subject);
        if ($theme !== null) {
            return $this->library->export($theme, $outputDir);
        }
        $name = ThemeLibrary::activeName($this->site($subject));

        return (new ThemePackage)->packSource(new MemorySource($this->liveFiles($subject), $name), $name, $outputDir)['path'];
    }

    /** @return list<array{id: int, number: int, note: string|null, files: int, published_at: string|null, submitted_by: int|null, approved_by: int|null}> */
    public function versions(Site|SiteTheme $subject): array
    {
        $theme = $this->theme($subject);
        if ($theme === null) {
            return [];
        }

        return array_values(ThemeVersion::query()->where('site_theme_id', $theme->id)->orderByDesc('number')->limit(50)->get()
            ->map(static fn (ThemeVersion $v): array => ['id' => $v->id, 'number' => $v->number, 'note' => $v->note, 'files' => count($v->files),
                'published_at' => $v->published_at?->toIso8601String(), 'submitted_by' => $v->submitted_by, 'approved_by' => $v->approved_by])->all());
    }

    /** Der Text, den der Editor für einen Pfad zeigt: Entwurf, sonst Live-Fassung; `null`, wenn es die Datei nicht gibt. */
    public function currentText(Site|SiteTheme $subject, string $path): ?string
    {
        $theme = $this->theme($subject);
        $draft = $theme === null ? null : ThemeDraft::query()->where('site_theme_id', $theme->id)->where('path', $path)->first();
        if ($draft !== null) {
            return $draft->source;
        }

        return $this->liveFiles($subject)[$path] ?? null;
    }

    /** Das eigene Theme hinter `$subject` — bei einer Site das, welches sie zeigt; null bei einem Theme aus dem Ordner. */
    public function theme(Site|SiteTheme $subject): ?SiteTheme
    {
        return $subject instanceof SiteTheme ? $subject : $this->library->active($subject);
    }

    private function site(Site|SiteTheme $subject): Site
    {
        return $subject instanceof SiteTheme ? $subject->site : $subject;
    }

    private function requireTheme(Site|SiteTheme $subject): SiteTheme
    {
        return $this->theme($subject) ?? throw new ThemeEditorException(__('Das Theme liegt im Ordner — zuerst «In die Datenbank übernehmen».'), status: 409);
    }

    /**
     * Ersetzt alle Entwürfe durch den Unterschied zwischen `$files` und der Live-Fassung.
     *
     * @param  array<string, string>  $files
     */
    private function replaceDrafts(SiteTheme $theme, array $files, Authenticatable $user): int
    {
        $live = $this->liveFiles($theme);
        $userId = (int) $user->getAuthIdentifier();

        return DB::transaction(function () use ($theme, $files, $live, $userId): int {
            ThemeDraft::query()->where('site_theme_id', $theme->id)->delete();
            $n = 0;
            foreach ($files + array_fill_keys(array_keys(array_diff_key($live, $files)), null) as $path => $content) {
                if (($live[$path] ?? null) === $content) {
                    continue;
                }
                ThemeDraft::query()->create(['site_id' => $theme->site_id, 'site_theme_id' => $theme->id, 'path' => $path, 'source' => $content, 'base_hash' => hash('sha256', $live[$path] ?? ''), 'updated_by' => $userId]);
                $n++;
            }

            return $n;
        });
    }
}
