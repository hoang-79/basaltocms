<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Live\Live;
use Basalto\Core\Live\LivePush;
use Basalto\Core\Sites\CurrentSite;

/**
 * Inseln der Community (CR-006 FO-K4): Markup mit `data-insel` bekommt das kleine Bündel `community.ts` dazu. Gäste
 * ohne Formular bekommen kein Skript; die Seite funktioniert in jedem Fall ohne.
 */
final class Islands
{
    /** Die Skript-Tags des Bündels — ans Ende des Markups eines Moduls hängen, wenn es eine Insel enthält. */
    public static function tags(): string
    {
        return app(Backend::class)->vite()->withEntryPoints(['resources/js/community.ts'])->toHtml();
    }

    /** CR-008: das kleine Bündel der Live-Aktualisierung (ohne Editor) — für Stellen mit `data-live`. */
    public static function liveTags(): string
    {
        return self::pushConfig().app(Backend::class)->vite()->withEntryPoints(['resources/js/live.ts'])->toHtml();
    }

    /** CR-008 (LV-11): Verbindungsdaten für Push, wenn eingerichtet — ohne Geheimnis; der Browser lädt Echo erst dann. */
    public static function pushConfig(): string
    {
        $config = app(LivePush::class)->clientConfig();

        return $config === null ? '' : '<script type="application/json" id="basalto-live-push">'.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES).'</script>';
    }

    /** Hängt die Skript-Tags an, wenn das Markup eine Insel oder eine Stelle der Live-Aktualisierung enthält. */
    public static function attach(string $html): string
    {
        return $html.(str_contains($html, 'data-insel=') ? self::tags() : '').(str_contains($html, ' data-live="') ? self::liveTags() : '');
    }

    /**
     * Ein Textfeld, das die Editor-Insel übernimmt — ohne Skript ein gewöhnliches Textfeld; Beschriftungen in der Sprache
     * der Seite. Optionen (alle Adressen relativ):
     *
     * - `upload` — Anhänge hochladen (Bilder landen im Text, andere Dateien in der Liste darunter);
     * - `names` — Namenssuche für Erwähnungen mit `@`;
     * - `quote` — Adresse eines Zitats mit `{id}` (Knöpfe «Zitieren» und «Merken» an den Beiträgen);
     * - `doc` — das Dokument, mit dem der Editor startet (Bearbeiten);
     * - `draft` — Schlüssel für den Entwurf im Browser (fehlt er, gibt es keinen);
     * - TB 49: `preview` — Adresse der Vorschau; `rules` — Regeln der Anhänge als Satz; `typing` — Ort für «Wer schreibt
     *   gerade» (`Typing::key()`), die Insel bekommt Adresse und Unterschrift dazu.
     *
     * @param  array{upload?: string, names?: string, quote?: string, doc?: array<string, mixed>|null, draft?: string, preview?: string, rules?: string, typing?: string}  $options
     */
    public static function editor(string $id, string $name, string $value, string $lang, int $rows = 8, bool $required = true, array $options = []): string
    {
        $texts = ['toolbar' => __('Formatierung', [], $lang), 'bold' => __('Fett', [], $lang), 'italic' => __('Kursiv', [], $lang), 'strike' => __('Durchgestrichen', [], $lang),
            'link' => __('Link', [], $lang), 'link_prompt' => __('Adresse des Links (https://…)', [], $lang), 'list' => __('Liste', [], $lang), 'ordered' => __('Nummerierte Liste', [], $lang),
            'quote' => __('Zitat', [], $lang), 'code' => __('Code im Text', [], $lang), 'codeblock' => __('Codeblock', [], $lang), 'spoiler' => __('Spoiler', [], $lang),
            'spoiler_prompt' => __('Titel des Spoilers (leer = «Spoiler»)', [], $lang), 'video' => __('Video einbetten', [], $lang),
            'video_prompt' => __('Adresse des Videos (YouTube oder Vimeo)', [], $lang), 'video_bad' => __('Nur Videos von YouTube oder Vimeo lassen sich einbetten.', [], $lang),
            'emoji' => __('Emoji', [], $lang), 'file' => __('Datei oder Bild anhängen', [], $lang), 'uploading' => __('Lädt hoch …', [], $lang),
            'upload_failed' => __('Hochladen fehlgeschlagen.', [], $lang), 'attachments' => __('Anhänge', [], $lang), 'remove' => __('Entfernen', [], $lang),
            'mention_none' => __('Kein Mitglied gefunden', [], $lang), 'mention_group' => __('Gruppe', [], $lang), 'draft_restored' => __('Entwurf wiederhergestellt.', [], $lang), 'draft_discard' => __('Verwerfen', [], $lang),
            'quote_insert' => __(':count Zitate einfügen', [], $lang), 'quoted' => __('Zitat übernommen', [], $lang), 'remembered' => __('Gemerkt', [], $lang),
            'wrote' => __(':name schrieb:', [], $lang)] + self::toolTexts($lang);
        $data = '';
        foreach (['upload' => 'hochladen', 'names' => 'namen', 'quote' => 'zitat', 'draft' => 'entwurf', 'preview' => 'vorschau', 'rules' => 'regeln'] as $key => $attribute) {
            if (is_string($options[$key] ?? null) && $options[$key] !== '') {
                $data .= sprintf(' data-insel-%s="%s"', $attribute, e($options[$key]));
            }
        }
        if (is_string($options['typing'] ?? null) && Typing::isKey($options['typing'])) {
            $site = app(CurrentSite::class)->get();
            $data .= sprintf(' data-insel-schreibt="%s"', e((string) json_encode(['url' => route('basalto.community.typing', absolute: false), 'key' => $options['typing'],
                'token' => app(Live::class)->token($site?->id, $options['typing'])], JSON_UNESCAPED_SLASHES)));
        }
        if (is_array($options['doc'] ?? null)) {
            $data .= sprintf(' data-insel-dokument="%s"', e((string) json_encode($options['doc'], JSON_UNESCAPED_UNICODE)));
        }

        return sprintf('<textarea id="%s" name="%s" rows="%d"%s data-insel="editor" data-insel-texte="%s"%s{aria}>%s</textarea>', e($id), e($name), $rows, $required ? ' required' : '',
            e((string) json_encode($texts, JSON_UNESCAPED_UNICODE)), $data, e($value));
    }

    /**
     * «Wer schreibt gerade» (TB 49): eine Stelle, die sich über die Live-Aktualisierung füllt — ohne Skript unsichtbar.
     * `$corner` setzt sie (nur für die Augen) in die Ecke des Editors; die andere sagt es auch Screenreadern an.
     */
    public static function typingIndicator(int $siteId, string $place, string $lang, ?int $selfProfile = null, bool $corner = false): string
    {
        $key = Typing::key($place);
        $attributes = app(Live::class)->attributes($siteId, $key, 'schreibt', array_filter(['ich' => $selfProfile, 'takt' => 6,
            'eins' => __(':name schreibt …', [], $lang), 'zwei' => __(':a und :b schreiben …', [], $lang), 'mehr' => __(':count Personen schreiben …', [], $lang)], static fn (mixed $v): bool => $v !== null));

        return sprintf('<p class="insel-schreibt%s"%s hidden%s><span class="insel-schreibt__punkte" aria-hidden="true"><i></i><i></i><i></i></span><span data-live-text></span></p>',
            $corner ? ' insel-schreibt--ecke' : '', $corner ? ' data-insel-ecke aria-hidden="true"' : ' role="status"', $attributes);
    }

    /** @return array<string, string> Beschriftungen der Werkzeuge und Reiter (TB 49) */
    private static function toolTexts(string $lang): array
    {
        $t = static fn (string $text): string => (string) __($text, [], $lang);

        return ['undo' => $t('Rückgängig'), 'redo' => $t('Wiederholen'), 'more' => $t('Weitere Formate'), 'underline' => $t('Unterstrichen'), 'subscript' => $t('Tiefgestellt'),
            'superscript' => $t('Hochgestellt'), 'clear' => $t('Format entfernen'), 'block_type' => $t('Absatzformat'), 'paragraph' => $t('Absatz'), 'heading1' => $t('Überschrift 1'),
            'heading2' => $t('Überschrift 2'), 'heading3' => $t('Überschrift 3'), 'highlight' => $t('Markieren'), 'highlight_gelb' => $t('Gelb markieren'), 'highlight_gruen' => $t('Grün markieren'),
            'highlight_blau' => $t('Blau markieren'), 'highlight_rot' => $t('Rot markieren'), 'highlight_violett' => $t('Violett markieren'), 'highlight_none' => $t('Markierung entfernen'),
            'link_address' => $t('Adresse'), 'link_text' => $t('Text'), 'link_text_hint' => $t('leer = Adresse'), 'link_remove' => $t('Link entfernen'),
            'link_bad' => $t('Bitte eine Adresse mit https://, mailto: oder / eingeben.'), 'cancel' => $t('Abbrechen'), 'apply' => $t('Übernehmen'), 'insert' => $t('Einfügen'),
            'lists' => $t('Listen'), 'outdent' => $t('Einzug verkleinern'), 'indent' => $t('Einzug vergrössern'), 'align' => $t('Ausrichtung'), 'align_left' => $t('Linksbündig'),
            'align_center' => $t('Zentriert'), 'align_right' => $t('Rechtsbündig'), 'align_justify' => $t('Blocksatz'), 'codeblock_settings' => $t('Sprache, Zeilennummer und Dateiname'),
            'code_auto' => $t('(Automatische Erkennung)'), 'code_none' => $t('(Kein)'), 'code_language' => $t('Sprache'), 'code_line' => $t('Zeilennummer'),
            'code_line_hint' => $t('Nummer der ersten Zeile, leer = 1'), 'code_file' => $t('Dateiname'), 'code' => $t('Code im Text'), 'table' => $t('Tabelle'),
            'table_insert' => $t('Tabelle einfügen'), 'table_size' => $t(':rows Zeilen, :cols Spalten'), 'row_before' => $t('Zeile darüber'), 'row_after' => $t('Zeile darunter'),
            'col_before' => $t('Spalte links'), 'col_after' => $t('Spalte rechts'), 'row_delete' => $t('Zeile löschen'), 'col_delete' => $t('Spalte löschen'),
            'header_row' => $t('Kopfzeile ein/aus'), 'table_delete' => $t('Tabelle löschen'), 'quotes' => $t('Zitate'), 'quote_remove' => $t('Zitat aufheben'),
            'spoiler_titled' => $t('Spoiler mit Titel'), 'spoiler_title' => $t('Titel'), 'spoiler_hint' => $t('leer = «Spoiler»'), 'video_address' => $t('Adresse des Videos'),
            'video_hint' => $t('YouTube oder Vimeo — geladen erst nach Klick'), 'smileys' => $t('Smileys'), 'upload' => $t('Hochladen'), 'settings' => $t('Einstellungen'),
            'poll' => $t('Umfrage'), 'preview' => $t('Vorschau'), 'preview_loading' => $t('Vorschau wird erstellt …'), 'preview_empty' => $t('Noch nichts geschrieben.'),
            'preview_failed' => $t('Die Vorschau liess sich nicht laden.'), 'extras' => $t('Zusätze'), 'list' => $t('Aufzählung')];
    }
}
