<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Requests;

use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Theme\ThemeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Site anlegen und bearbeiten (K-01): Allgemein, Sprachen, Domains, Module — die vier Reiter des Formulars. */
final class SiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Hosts kleinschreiben, bevor die Regel greift — «Nguyen-Development.CH» ist dieselbe Domain. */
    protected function prepareForValidation(): void
    {
        $domains = $this->input('domains');
        if (is_array($domains)) {
            foreach ($domains as $i => $domain) {
                if (is_array($domain) && is_string($domain['host'] ?? null)) {
                    $domains[$i]['host'] = strtolower(trim($domain['host']));
                }
            }
            $this->merge(['domains' => $domains]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Site|null $site */
        $site = $this->route('site');

        return [
            'name' => ['required', 'string', 'max:120'],
            'handle' => ['required', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])?$/', Rule::unique('sites', 'handle')->ignore($site?->id), Rule::notIn((array) config('basalto.saas.reserved_aliases', []))],
            'default_locale' => ['required', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'timezone' => ['required', 'timezone:all'],
            'theme' => ['nullable', 'string', 'max:60', Rule::in(app(ThemeCatalog::class)->names())],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*.locale' => ['required', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/', 'distinct'],
            'locales.*.is_required' => ['boolean'],
            'locales.*.fallback_locale' => ['nullable', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'domains' => ['array'],
            'domains.*.host' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', 'distinct'],
            'domains.*.kind' => ['required', Rule::in([SiteDomain::KIND_ALIAS, SiteDomain::KIND_CUSTOM, SiteDomain::KIND_REDIRECT])],
            'domains.*.is_primary' => ['boolean'],
            'modules' => ['array'],
            'modules.*' => ['boolean'],
            'media' => ['array'],
            'media.alt_required' => ['boolean'],
            'close' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Site|null $site */
            $site = $this->route('site');
            $default = (string) $this->input('default_locale');
            $codes = array_map(static fn (mixed $row): string => is_array($row) ? (string) ($row['locale'] ?? '') : '', (array) $this->input('locales', []));
            if (! in_array($default, $codes, true)) {
                $validator->errors()->add('default_locale', __('Die Standardsprache muss eine der Sprachen der Site sein.'));
            }
            foreach ((array) $this->input('locales', []) as $i => $row) {
                $fallback = is_array($row) ? ($row['fallback_locale'] ?? null) : null;
                if (is_string($fallback) && $fallback !== '' && ! in_array($fallback, $codes, true)) {
                    $validator->errors()->add("locales.$i.fallback_locale", __('Die Fallback-Sprache muss eine Sprache der Site sein.'));
                }
            }
            foreach ((array) $this->input('domains', []) as $i => $row) {
                $host = is_array($row) ? strtolower((string) ($row['host'] ?? '')) : '';
                $taken = SiteDomain::query()->where('host', $host)->when($site !== null, static fn ($q) => $q->where('site_id', '!=', $site->id))->exists();
                if ($host !== '' && $taken) {
                    $validator->errors()->add("domains.$i.host", __('«:host» gehört schon einer anderen Site.', ['host' => $host]));
                }
            }
        });
    }
}
