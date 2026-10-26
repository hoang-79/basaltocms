<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Content\RichText;
use Basalto\Core\Content\Translations;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Media;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Ai\Agents\AltTextWriter;
use Basalto\Modules\Ai\Agents\FieldTranslator;
use Basalto\Modules\Ai\Agents\SeoAdvisor;
use Basalto\Modules\Ai\Agents\TextImprover;
use Basalto\Modules\Ai\Assistant;
use Basalto\Modules\Ai\AssistantException;
use Basalto\Modules\Ai\Feature;
use Basalto\Modules\Ai\Models\AiUsage;
use Basalto\Modules\Ai\Support\RichTextMarkdown;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Laravel\Ai\Files\Image;

/**
 * Die vier Vorschläge des KI-Moduls (M-24) — jeder nur auf einen Klick in der Oberfläche (POST), jeder nur als
 * Vorschlag: Die Antwort trägt den neuen Wert, gespeichert wird hier nichts. «Übernehmen» legt ihn in das Feld des
 * Formulars, und das Autosave des Kerns schreibt ihn wie getippt in den Entwurf — nie in die veröffentlichte Fassung.
 * Recht wie beim Bearbeiten: Text verbessern und SEO verlangen «Inhalte bearbeiten», Übersetzen das Recht am Inhalt,
 * Alt-Texte das Recht an der Mediathek. Je Benutzer höchstens zehn Vorschläge in der Minute.
 */
final class SuggestionController
{
    /** Vorschläge je Benutzer und Minute. */
    public const PER_MINUTE = 10;

    /** Grösste Bilddatei, die als Original an den Anbieter geht, wenn keine kleinere Variante da ist. */
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly CurrentSite $current, private readonly Assistant $assistant) {}

    /** «Text verbessern» an einem Text-, Textbereich- oder Fliesstextfeld im Block-Editor. */
    public function improve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry' => ['required', 'integer'],
            'locale' => ['required', 'string', 'max:10'],
            'type' => ['required', Rule::in(['text', 'textarea', 'richtext'])],
            'value' => ['nullable'],
        ]);
        $entry = $this->entry((int) $validated['entry'], (string) $validated['locale']);
        Gate::authorize('editOnPage', $entry);
        $type = (string) $validated['type'];
        $value = $request->input('value');

        return $this->answer($request, function () use ($request, $entry, $type, $value, $validated): array {
            [$input, $markdown] = $this->input($type, $value);
            $locale = (string) $validated['locale'];
            $result = $this->assistant->run($this->current->require(), $request->user(), Feature::Improve,
                new TextImprover($markdown, $type === 'text', $locale), $input, subject: $entry, locale: $locale);

            return [$result['usage'], $this->suggestion($type, $value, (string) ($result['data']['text'] ?? ''))];
        });
    }

    /** «Vorschlag» an einem Feld beim Übersetzen nebeneinander — aus der Quellfassung der Standardsprache. */
    public function translate(Request $request, Translations $translations): JsonResponse
    {
        $validated = $request->validate([
            'entry' => ['required', 'integer'],
            'locale' => ['required', 'string', 'max:10'],
            'path' => ['required', 'string', 'max:200'],
        ]);
        $entry = $this->entry((int) $validated['entry'], (string) $validated['locale']);
        Gate::authorize('update', $entry);
        $site = $entry->site;
        $locale = (string) $validated['locale'];
        abort_if($locale === $site->default_locale, 422, __('In der Standardsprache gibt es nichts zu übersetzen.'));
        app(Access::class)->authorizeLocale($request->user(), $site, $locale);
        $source = $entry->latestVersion($site->default_locale);
        abort_if($source === null, 404, __('Keine Fassung in der Standardsprache'));
        $pair = collect($translations->pairs($entry, $source))->first(static fn (array $p): bool => $p['path'] === $validated['path'] && $p['translatable']);
        abort_if($pair === null, 422, __('Dieses Feld ist nicht übersetzbar.'));
        $type = in_array($pair['type'], ['richtext', 'textarea'], true) ? $pair['type'] : 'text';
        $value = Arr::get(['title' => $source->title] + $source->data, $pair['path']);

        return $this->answer($request, function () use ($request, $entry, $site, $locale, $type, $value): array {
            [$input, $markdown] = $this->input($type, $value);
            $result = $this->assistant->run($site, $request->user(), Feature::Translate,
                new FieldTranslator($site->default_locale, $locale, $markdown, $type === 'text'), $input, subject: $entry, locale: $locale);

            return [$result['usage'], $this->suggestion($type, $value, (string) ($result['data']['text'] ?? ''))];
        });
    }

    /** «SEO-Vorschläge» im Reiter Metadaten: Seitentitel und Beschreibung aus dem Inhalt der neuesten Fassung. */
    public function seo(Request $request, Translations $translations): JsonResponse
    {
        $validated = $request->validate(['entry' => ['required', 'integer'], 'locale' => ['required', 'string', 'max:10']]);
        $entry = $this->entry((int) $validated['entry'], (string) $validated['locale']);
        Gate::authorize('editOnPage', $entry);
        $locale = (string) $validated['locale'];
        $version = $entry->latestVersion($locale);
        abort_if($version === null, 422, __('In dieser Sprache gibt es noch keine Fassung — erst Inhalt, dann Metadaten.'));
        $lines = [];
        foreach ($translations->translatableValues($entry, $version) as $path => $text) {
            if ($text !== null && $text !== '' && ! str_starts_with($path, 'settings.meta.')) {
                $lines[] = $text;
            }
        }
        $content = mb_substr(implode("\n\n", $lines), 0, Assistant::MAX_INPUT - 200);
        $site = $entry->site;

        return $this->answer($request, function () use ($request, $entry, $site, $locale, $content): array {
            if (trim($content) === '') {
                throw new AssistantException(__('Die Seite hat noch keinen Text — ohne Inhalt gibt es keinen Vorschlag.'));
            }
            $result = $this->assistant->run($site, $request->user(), Feature::Seo, new SeoAdvisor($locale, $site->name), $content, subject: $entry, locale: $locale);

            return [$result['usage'], [
                'title' => $this->plain((string) ($result['data']['title'] ?? ''), 70, true),
                'description' => $this->plain((string) ($result['data']['description'] ?? ''), 160, true),
            ]];
        });
    }

    /** «Alt-Texte» im Bild-Detail der Mediathek: je Sprache der Site ein Vorschlag aus dem Bild. */
    public function altText(Request $request): JsonResponse
    {
        $validated = $request->validate(['media' => ['required', 'integer']]);
        $media = Media::query()->find((int) $validated['media']);
        abort_if($media === null, 404);
        Gate::authorize('update', $media);
        abort_unless($media->isRasterImage(), 422, __('Alt-Texte schlägt die KI nur für Bilder vor (keine Vektorgrafiken, keine Dokumente).'));
        $site = $media->site;
        $locales = $site->localeCodes();

        return $this->answer($request, function () use ($request, $media, $site, $locales): array {
            $result = $this->assistant->run($site, $request->user(), Feature::AltText, new AltTextWriter($locales),
                'Write the alt texts for the attached image.', [$this->image($media)], subject: $media);
            $alt = [];
            foreach ($locales as $locale) {
                $alt[$locale] = $this->plain((string) ($result['data'][$locale] ?? ''), 250, true);
            }

            return [$result['usage'], ['alt' => $alt]];
        });
    }

    /**
     * «Übernehmen» geklickt: im Protokoll vermerken. Beim Alt-Text zusätzlich im Protokoll des Kerns — Medien haben
     * keine Fassungen, der übernommene Alt-Text gilt mit dem Autosave sofort.
     */
    public function applied(Request $request, int $usage, Recorder $recorder): JsonResponse
    {
        $row = AiUsage::query()->whereKey($usage)->where('site_id', $this->current->require()->id)
            ->where('user_id', (int) $request->user()?->getAuthIdentifier())->where('status', AiUsage::OK)->first();
        abort_if($row === null, 404);
        if ($row->applied_at === null) {
            $row->forceFill(['applied_at' => CarbonImmutable::now()])->save();
            if ($row->feature === Feature::AltText->value && $row->subject_id !== null) {
                $media = Media::query()->find($row->subject_id);
                $locales = array_values(array_intersect((array) $request->input('locales', []), $media?->site->localeCodes() ?? []));
                if ($media !== null) {
                    $recorder->record('ai.alt_text.applied', $media, null, ['locales' => $locales, 'usage' => $row->id]);
                }
            }
        }

        return response()->json(['applied' => true]);
    }

    /**
     * Ausführen mit Rate-Limit je Benutzer; Absagen als 422/429/502 mit freundlicher Meldung.
     *
     * @param  \Closure(): array{0: AiUsage, 1: array<string, mixed>}  $run
     */
    private function answer(Request $request, \Closure $run): JsonResponse
    {
        $key = 'basalto-ai:'.(string) $request->user()?->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response()->json(['message' => __('Zu viele Vorschläge in kurzer Zeit — bitte in :seconds Sekunden noch einmal.', ['seconds' => RateLimiter::availableIn($key)])], 429);
        }
        RateLimiter::hit($key, 60);
        try {
            [$usage, $payload] = $run();
        } catch (AssistantException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($payload + ['usage' => $usage->id, 'applied_url' => route('basalto.ai.applied', $usage->id)]);
    }

    private function entry(int $id, string $locale): Entry
    {
        $entry = Entry::query()->find($id);
        abort_if($entry === null, 404);
        abort_unless(in_array($locale, $entry->site->localeCodes(), true), 404);

        return $entry;
    }

    /**
     * Der Wert eines Felds als Eingabe für die KI: Fliesstext als Markdown, sonst Klartext.
     *
     * @return array{0: string, 1: bool}
     *
     * @throws AssistantException
     */
    private function input(string $type, mixed $value): array
    {
        if ($type === 'richtext') {
            $markdown = RichTextMarkdown::fromValue($value);
            if ($markdown === null) {
                throw new AssistantException(__('Dieser Fliesstext enthält Bilder, Tabellen, Aufgaben oder Einbettungen — die würden beim Vorschlag verloren gehen. Bitte von Hand bearbeiten.'));
            }
            $text = $markdown;
        } else {
            $text = is_scalar($value) ? trim((string) $value) : '';
        }
        if ($text === '') {
            throw new AssistantException(__('Das Feld ist leer — es gibt nichts vorzuschlagen.'));
        }

        return [$text, $type === 'richtext'];
    }

    /**
     * Die Antwort als Vorschlag: der neue Wert (Fliesstext als gesäubertes Dokument) und alt/neu als Text für den Vergleich.
     *
     * @return array<string, mixed>
     *
     * @throws AssistantException
     */
    private function suggestion(string $type, mixed $before, string $answer): array
    {
        if ($type === 'richtext') {
            $value = RichTextMarkdown::toDoc($answer);
            $after = RichText::toText($value);
        } else {
            $value = $this->plain($answer, $type === 'text' ? 300 : 5000, $type === 'text');
            $after = $value;
        }
        if (trim($after) === '') {
            throw new AssistantException(__('Der Vorschlag ist leer gekommen — bitte noch einmal.'), 502);
        }

        return ['kind' => $type, 'value' => $value, 'before' => $type === 'richtext' ? RichText::toText($before) : (is_scalar($before) ? (string) $before : ''), 'after' => $after];
    }

    /** Klartext aus einer Antwort: ohne HTML und Steuerzeichen, gekürzt; einzeilig ohne Umbrüche und Anführungszeichen aussen. */
    private function plain(string $text, int $max, bool $singleLine): string
    {
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if ($singleLine) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            $text = trim($text, " \"'«»„“”");
        }

        return mb_substr(trim($text), 0, $max);
    }

    /**
     * Das Bild für den Anbieter: die grösste Variante bis 1600 px Breite, sonst das Original bis 8 MB.
     *
     * @throws AssistantException
     */
    private function image(Media $media): Image
    {
        $best = null;
        foreach ($media->variants ?? [] as $variant) {
            if ($variant['width'] <= 1600 && ($best === null || $variant['width'] > $best['width'])) {
                $best = $variant;
            }
        }
        try {
            if ($best !== null && $media->storage()->exists($best['path'])) {
                return Image::fromStorage($best['path'], $media->disk);
            }
            $original = $media->size <= self::MAX_IMAGE_BYTES && $media->storage()->exists($media->path);
        } catch (\Throwable) {
            throw new AssistantException(__('Das Bild lässt sich gerade nicht aus dem Speicher lesen — bitte später noch einmal.'), 502);
        }
        if (! $original) {
            throw new AssistantException(__('Das Bild ist zu gross oder fehlt im Speicher — erst die Varianten erzeugen lassen.'));
        }

        return Image::fromStorage($media->path, $media->disk);
    }
}
