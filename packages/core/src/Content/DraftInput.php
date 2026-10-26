<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Eingaben für einen Entwurf (M-04) — eine Quelle für Editor und REST-API (M-17): Titel, Slug, Einleitung, Blöcke und
 * Felder des Typs, geprüft gegen das Theme-Schema der Site. Pflichtfelder prüft erst das Einreichen (Autosave darf
 * unvollständig sein). Felder der bisherigen Fassung bleiben erhalten, wenn die Eingabe sie nicht nennt.
 */
final class DraftInput
{
    public function __construct(private readonly ThemeLoader $themes) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> die Daten der Fassung (`title`, `intro`, `content`, `slug` und die Felder des Typs)
     *
     * @throws ValidationException
     */
    public function data(Entry $entry, array $input, ?EntryVersion $previous): array
    {
        $schema = ThemeSchema::fromTheme($this->themes->forSite($entry->site))->withoutRequired();
        $validator = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]*$/'],
            // Einleitung ist Fliesstext; Klartext aus älteren Fassungen bleibt gültig
            'intro' => ['nullable', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) ? mb_strlen($value) > 2000 : RichText::doc($value) === null || mb_strlen(RichText::toText($value)) > 2000) {
                    $fail(__('Die Einleitung ist zu lang (höchstens :max Zeichen).', ['max' => 2000]));
                }
            }],
            'content' => ['array'],
            'content.*.type' => ['required', 'string'],
            'content.*.data' => ['array'],
            'fields' => ['sometimes', 'array'],
        ]);
        $validator->after(static function (ValidatorInstance $validator) use ($schema, $entry, $input): void {
            $content = $input['content'] ?? [];
            foreach ($schema->validate($entry->type, is_array($content) ? array_values($content) : []) as $path => $message) {
                $validator->errors()->add($path, $message);
            }
            $fields = $input['fields'] ?? [];
            foreach ($schema->validateTypeFields($entry->type, is_array($fields) ? $fields : []) as $path => $message) {
                $validator->errors()->add($path, $message);
            }
        });
        $validated = $validator->validate();
        $data = (is_array($previous?->data) ? $previous->data : []) + [];
        unset($data['title'], $data['slug'], $data['intro'], $data['content']);
        foreach (is_array($validated['fields'] ?? null) ? $validated['fields'] : [] as $name => $value) {
            $data[(string) $name] = $value;
        }
        $data = ['title' => $validated['title'], 'intro' => $validated['intro'] ?? null, 'content' => array_values($validated['content'] ?? [])] + $data;
        $data['slug'] = ($validated['slug'] ?? '') !== '' ? $validated['slug'] : ($previous->slug ?? str($validated['title'])->slug()->toString());

        return $data;
    }
}
