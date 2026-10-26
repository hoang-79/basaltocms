<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag as Messages;
use Illuminate\Support\ViewErrorBag;

/**
 * Bausteine für das Markup der Tags rund um das Frontend-Konto (Anmeldung, Mitglieder, Kauf, Konto) — barrierearm wie
 * die Formulare des Kerns: Beschriftung je Feld, Fehler am Feld mit `aria-describedby` und `aria-invalid`,
 * Zusammenfassung als `role="alert"`, Meldungen als `role="status"`; kein Skript nötig. Klassen wie im Kern (`form`,
 * `form-field`, `form-error`, `form-help`), dazu eigene mit Präfix `account-` für das Theme. Seit TB 13 Scheibe 2 im
 * Kern; der Lizenzverkauf erweitert die Klasse um die Länder der Rechnungsadresse.
 */
class Html
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES);
    }

    /** Der Stern für Pflichtfelder — nur fürs Auge, den Screenreadern sagt es «required». */
    public static function required(): string
    {
        return ' <span class="form-required" aria-hidden="true">*</span>';
    }

    /**
     * Ein Feld mit Beschriftung, Fehler und Hilfe; `{aria}` im Steuerelement wird durch die Verweise ersetzt.
     */
    public static function field(string $id, string $name, string $labelHtml, string $control, ?string $help, MessageBag $errors, string $class = ''): string
    {
        $error = $errors->first($name);
        $describedBy = array_filter([$error !== '' ? $id.'-fehler' : null, $help !== null ? $id.'-hilfe' : null]);
        $aria = ($describedBy !== [] ? ' aria-describedby="'.implode(' ', $describedBy).'"' : '').($error !== '' ? ' aria-invalid="true"' : '');

        return sprintf('<div class="form-field%s%s"><label for="%s">%s</label>%s%s%s</div>', $class !== '' ? ' '.$class : '', $error !== '' ? ' has-error' : '', $id, $labelHtml,
            str_replace('{aria}', $aria, $control), $error !== '' ? sprintf('<p class="form-error" id="%s-fehler">%s</p>', $id, self::e($error)) : '',
            $help !== null ? sprintf('<p class="form-help" id="%s-hilfe">%s</p>', $id, self::e($help)) : '');
    }

    /** Zusammenfassung der Fehler oben im Formular. */
    public static function errorSummary(MessageBag $errors, string $lang): string
    {
        if ($errors->isEmpty()) {
            return '';
        }

        return sprintf('<div class="form-errors" role="alert" tabindex="-1"><p>%s</p><ul>%s</ul></div>', self::e(__('Bitte die markierten Felder prüfen.', [], $lang)),
            implode('', array_map(static fn (string $m): string => '<li>'.self::e($m).'</li>', $errors->all())));
    }

    /** Fehler einer benannten Tasche aus der Sitzung (nach einer Weiterleitung). */
    public static function errors(Request $request, string $bag): MessageBag
    {
        $bags = $request->hasSession() ? $request->session()->get('errors') : null;

        return $bags instanceof ViewErrorBag ? $bags->getBag($bag) : new Messages;
    }

    /**
     * Frühere Eingaben nach einem Fehler.
     *
     * @return array<string, mixed>
     */
    public static function old(Request $request): array
    {
        return $request->hasSession() ? (array) $request->session()->getOldInput() : [];
    }

    /** Verborgene Felder: Seite und Sprache, auf Wunsch das CSRF-Token (nur auf Seiten, die nie aus dem Cache kommen). */
    public static function hidden(string $pagePath, string $lang, bool $csrf): string
    {
        return ($csrf ? sprintf('<input type="hidden" name="_token" value="%s">', self::e(csrf_token())) : '')
            .sprintf('<input type="hidden" name="_seite" value="%s"><input type="hidden" name="_sprache" value="%s">', self::e($pagePath), self::e($lang));
    }

    /** Das Feld, das Menschen nie ausfüllen. */
    public static function honeypot(): string
    {
        return '<div class="form-hp" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
    }

    /** Eine Meldung (Erfolg oder Hinweis), vom Screenreader vorgelesen. */
    public static function status(string $text, string $class = 'account-status'): string
    {
        return sprintf('<p class="%s" role="status">%s</p>', $class, self::e($text));
    }

    /** Nur ein Pfad dieser Site — sonst wäre `_seite` eine offene Weiterleitung. */
    public static function pagePath(mixed $value): string
    {
        $path = (string) parse_url(is_string($value) ? $value : '/', PHP_URL_PATH);

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';
    }

    /**
     * Pfad mit Abfrage und Sprungmarke.
     *
     * @param  array<string, scalar|null>  $query
     */
    public static function url(string $path, array $query = [], ?string $anchor = null): string
    {
        $query = array_filter($query, static fn (mixed $v): bool => $v !== null && $v !== '');

        return $path.($query === [] ? '' : '?'.http_build_query($query)).($anchor !== null ? '#'.$anchor : '');
    }
}
