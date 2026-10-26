<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\FrontendAccounts\Html;
use Basalto\Modules\Forum\Models\Node;
use Illuminate\Contracts\Support\MessageBag;

/**
 * Themenformulare (CR-006 FO-09, bei WoltLab «Themenformular»): eigene Felder, die beim Eröffnen eines Themas vor dem
 * Text abgefragt werden — z. B. «Basalto-Version» (Auswahl) oder «Adresse der Site» (URL). Sie stehen in den
 * Einstellungen des Forums (`settings.fields`); die Werte erscheinen über dem Startbeitrag.
 *
 * Abweichung von CR-006: eigene Felder am Forum statt eines Formulars aus dem Baukasten — der Baukasten kennt
 * Versand, Eingänge und Spam-Schutz, die hier stören würden. Feldarten: Text, Adresse, Auswahl.
 *
 * @phpstan-type Field array{key: string, label: array<string, string>, type: string, options: list<string>, required: bool}
 */
final class ThreadFields
{
    public const TYPES = ['text', 'url', 'select'];

    /** @return list<Field> */
    public static function of(Node $node): array
    {
        $out = [];
        foreach ((array) $node->setting('fields', []) as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null) || preg_match('/^[a-z0-9_]{1,30}$/', $field['key']) !== 1) {
                continue;
            }
            $out[] = ['key' => $field['key'], 'label' => is_array($field['label'] ?? null) ? array_map('strval', $field['label']) : [],
                'type' => in_array($field['type'] ?? null, self::TYPES, true) ? (string) $field['type'] : 'text',
                'options' => array_values(array_filter(array_map(static fn (mixed $o): string => trim((string) $o), is_array($field['options'] ?? null) ? $field['options'] : []), static fn (string $o): bool => $o !== '')),
                'required' => (bool) ($field['required'] ?? false)];
        }

        return $out;
    }

    /** @param  Field  $field */
    public static function label(array $field, string $lang): string
    {
        $value = $field['label'][$lang] ?? null;
        if ($value !== null && trim($value) !== '') {
            return $value;
        }
        foreach ($field['label'] as $other) {
            if (trim($other) !== '') {
                return $other;
            }
        }

        return $field['key'];
    }

    /**
     * Werte aus dem Formular prüfen.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, string>, 1: array<string, string>} Werte und Fehler je Feld (`feld.<key>`)
     */
    public static function validate(Node $node, array $input, string $lang): array
    {
        $values = [];
        $errors = [];
        foreach (self::of($node) as $field) {
            $value = is_string($input[$field['key']] ?? null) ? trim(mb_substr($input[$field['key']], 0, 200)) : '';
            $label = self::label($field, $lang);
            if ($value === '') {
                if ($field['required']) {
                    $errors['feld.'.$field['key']] = (string) __(':field ist ein Pflichtfeld.', ['field' => $label], $lang);
                }

                continue;
            }
            if ($field['type'] === 'url' && preg_match('#^https?://[^\s]+$#i', $value) !== 1) {
                $errors['feld.'.$field['key']] = (string) __(':field braucht eine Adresse mit https://.', ['field' => $label], $lang);

                continue;
            }
            if ($field['type'] === 'select' && ! in_array($value, $field['options'], true)) {
                $errors['feld.'.$field['key']] = (string) __('Bitte eine Auswahl für :field treffen.', ['field' => $label], $lang);

                continue;
            }
            $values[$field['key']] = $value;
        }

        return [$values, $errors];
    }

    /**
     * Die Felder im Formular «Neues Thema».
     *
     * @param  array<string, mixed>  $old
     */
    public static function inputs(Node $node, array $old, MessageBag $errors, string $lang): string
    {
        $html = '';
        foreach (self::of($node) as $field) {
            $id = 'forum-feld-'.$field['key'];
            $name = 'feld['.$field['key'].']';
            $value = is_array($old['feld'] ?? null) && is_string($old['feld'][$field['key']] ?? null) ? $old['feld'][$field['key']] : '';
            $label = Html::e(self::label($field, $lang)).($field['required'] ? Html::required() : '');
            $required = $field['required'] ? ' required' : '';
            $control = $field['type'] === 'select'
                ? sprintf('<select id="%s" name="%s"%s{aria}><option value="">%s</option>%s</select>', $id, $name, $required, Html::e(__('Bitte wählen', [], $lang)),
                    implode('', array_map(static fn (string $o): string => sprintf('<option%s>%s</option>', $o === $value ? ' selected' : '', Html::e($o)), $field['options'])))
                : sprintf('<input id="%s" name="%s" type="%s" value="%s" maxlength="200"%s{aria}>', $id, $name, $field['type'] === 'url' ? 'url' : 'text', Html::e($value), $required);
            $html .= Html::field($id, 'feld.'.$field['key'], $label, $control, null, $errors);
        }

        return $html;
    }

    /**
     * Die Werte über dem Startbeitrag.
     *
     * @param  array<string, string>|null  $values
     */
    public static function display(Node $node, ?array $values, string $lang): string
    {
        $rows = '';
        foreach (self::of($node) as $field) {
            $value = $values[$field['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $shown = $field['type'] === 'url' ? sprintf('<a href="%1$s" rel="nofollow ugc noopener" target="_blank">%1$s</a>', Html::e($value)) : Html::e($value);
            $rows .= sprintf('<div><dt>%s</dt><dd>%s</dd></div>', Html::e(self::label($field, $lang)), $shown);
        }

        return $rows === '' ? '' : '<dl class="forum-felder">'.$rows.'</dl>';
    }
}
