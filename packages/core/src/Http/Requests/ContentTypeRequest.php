<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Requests;

use Basalto\Core\Content\ContentTypes;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Lava\Runtime\Registry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ein Inhaltstyp aus dem Backend (M-02, Station E): Name, Beschriftung je Sprache, Felder mit Typ und Flags,
 * Route je Sprache, Template — geprüft in der Form und per Probe-Kompilierung gegen das Theme.
 *
 * @phpstan-import-type TypeInput from ContentTypes
 */
final class ContentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $site = app(CurrentSite::class)->require();
        $rules = [
            'kind' => ['required', Rule::in(['type', 'global'])],
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/'],
            'labels' => ['required', 'array'],
            'labels.'.$site->default_locale => ['required', 'string', 'max:80'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.name' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.type' => ['required', 'string', Rule::in(app(Registry::class)->fieldTypes())],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.translatable' => ['sometimes', 'boolean'],
            'fields.*.options' => ['nullable', 'string', 'max:500'],
            'fields.*.default' => ['nullable', 'string', 'max:200'],
            'fields.*.relation' => ['nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/'],
            'fields.*.allow' => ['nullable', 'array'],
            'fields.*.allow.*' => ['string', 'regex:/^[a-z][a-z0-9-]*$/'],
            // TB 32: Beschriftung je Sprache und Hilfe der Grundsprache — was die Redaktion im Editor über dem Feld sieht
            'fields.*.labels' => ['nullable', 'array'],
            'fields.*.labels.*' => ['nullable', 'string', 'max:120'],
            'fields.*.help' => ['nullable', 'string', 'max:300'],
            'route' => ['sometimes', 'array'],
            'template' => ['nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/'],
            'close' => ['sometimes', 'boolean'],
        ];
        foreach ($site->localeCodes() as $locale) {
            $rules['labels.'.$locale] ??= ['nullable', 'string', 'max:80'];
            $rules['route.'.$locale] = ['nullable', 'string', 'max:120', 'regex:#^/[a-z0-9\-/{}]*$#'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $site = app(CurrentSite::class)->require();
            $current = $this->route('name');
            foreach (app(ContentTypes::class)->validate($site, $this->definition(), is_string($current) ? $current : null) as $path => $message) {
                $validator->errors()->add($path, $message);
            }
        });
    }

    /** @return TypeInput */
    public function definition(): array
    {
        $site = app(CurrentSite::class)->require();
        $labels = [];
        foreach ((array) $this->input('labels', []) as $lang => $label) {
            $key = $lang === $site->default_locale ? '*' : (string) $lang;
            $labels[$key] = is_string($label) ? $label : '';
        }
        $routes = [];
        foreach ((array) $this->input('route', []) as $lang => $route) {
            $key = $lang === $site->default_locale ? '*' : (string) $lang;
            $routes[$key] = is_string($route) ? $route : '';
        }
        $fields = [];
        foreach ((array) $this->input('fields', []) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $fields[] = [
                'name' => (string) ($field['name'] ?? ''), 'type' => (string) ($field['type'] ?? 'text'),
                'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOL), 'translatable' => filter_var($field['translatable'] ?? false, FILTER_VALIDATE_BOOL),
                'options' => is_string($field['options'] ?? null) ? $field['options'] : null, 'default' => is_string($field['default'] ?? null) ? $field['default'] : null,
                'relation' => is_string($field['relation'] ?? null) ? $field['relation'] : null,
                'allow' => is_array($field['allow'] ?? null) ? array_values(array_map(static fn (mixed $v): string => (string) $v, $field['allow'])) : null,
                'labels' => self::byLocale(is_array($field['labels'] ?? null) ? $field['labels'] : [], $site->default_locale),
                'help' => is_string($field['help'] ?? null) ? $field['help'] : null,
            ];
        }

        return ['kind' => (string) $this->input('kind', 'type'), 'name' => (string) $this->input('name'), 'labels' => $labels, 'fields' => $fields, 'route' => $routes,
            'template' => is_string($this->input('template')) ? $this->input('template') : null];
    }

    /**
     * Texte je Sprache mit «*» für die Grundsprache, wie Lava sie schreibt (`label`, `label.en`).
     *
     * @param  array<mixed>  $texts
     * @return array<string, string>
     */
    private static function byLocale(array $texts, string $default): array
    {
        $out = [];
        foreach ($texts as $lang => $text) {
            if (is_string($text)) {
                $out[$lang === $default ? '*' : (string) $lang] = $text;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => __('Name'), 'kind' => __('Art'), 'fields' => __('Felder'), 'fields.*.name' => __('Feldname'), 'fields.*.type' => __('Feldtyp'), 'template' => __('Template'), 'route.*' => __('Route')];
    }
}
