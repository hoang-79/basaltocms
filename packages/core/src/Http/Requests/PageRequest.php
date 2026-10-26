<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Requests;

use Basalto\Core\Access\Access;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Seiteneinstellungen in zwölf Reitern (M-01, Mockup 28): Die Regeln hängen am Typ — eine Weiterleitung hat vier
 * Reiter, eine Seite zwölf. Jeder Fehlerpfad beginnt mit dem Schlüssel seines Reiters («routing.slugs.en»), damit
 * das Formular die Karmin-Marke an den richtigen Reiter hängt.
 */
final class PageRequest extends FormRequest
{
    public const ROBOTS = ['index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'];

    public function authorize(): bool
    {
        return true;
    }

    public function type(): PageType
    {
        return PageType::tryFrom((string) $this->input('type', PageType::Page->value)) ?? PageType::Page;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $site = app(CurrentSite::class)->require();
        $type = $this->type();
        $locales = $site->localeCodes();
        $templates = self::templatesFor($site, $type);
        $rules = [
            'type' => ['required', Rule::in(PageType::values())],
            'name' => ['required', 'array'],
            'name.'.$site->default_locale => ['required', 'string', 'max:200'],
            'parent_id' => ['nullable', 'integer'],
            'close' => ['sometimes', 'boolean'],
            'new' => ['sometimes', 'boolean'],
        ];
        foreach ($locales as $locale) {
            $rules['name.'.$locale] ??= ['nullable', 'string', 'max:200'];
        }
        if ($type === PageType::Feed) {
            $rules['feed.type'] = ['required', 'string', Rule::in(self::contentTypesFor($site))];
            $rules['feed.limit'] = ['nullable', 'integer', 'between:1,100'];
            $rules['feed.format'] = ['nullable', 'in:rss,atom'];
        }
        if ($type === PageType::Redirect) {
            $rules['redirect.kind'] = ['required', Rule::in(['internal', 'external'])];
            $rules['redirect.entry_id'] = ['nullable', 'integer', 'required_if:redirect.kind,internal'];
            $rules['redirect.url'] = ['nullable', 'url:http,https', 'max:500', 'required_if:redirect.kind,external'];
            $rules['redirect.urls'] = ['sometimes', 'array'];
            $rules['redirect.urls.*'] = ['nullable', 'url:http,https', 'max:500'];
            $rules['redirect.status'] = ['nullable', Rule::in([301, 302])];
        }
        if ($type->hasTab('routing')) {
            $rules['routing.slugs'] = ['sometimes', 'array'];
            foreach ($locales as $locale) {
                $rules['routing.slugs.'.$locale] = ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9][a-z0-9-]*(\.xml)?$/'];
                $rules['routing.home.'.$locale] = ['sometimes', 'boolean'];
            }
            $rules['routing.priority'] = ['nullable', 'integer', 'between:0,100'];
            $rules['routing.redirect_on_slug_change'] = ['sometimes', 'boolean'];
        }
        if ($type->hasTab('meta')) {
            foreach ($locales as $locale) {
                $rules['meta.'.$locale.'.title'] = ['nullable', 'string', 'max:70'];
                $rules['meta.'.$locale.'.description'] = ['nullable', 'string', 'max:160'];
            }
            $rules['meta.robots'] = ['nullable', Rule::in(self::ROBOTS)];
        }
        if ($type->hasTab('image')) {
            $rules['image.media_id'] = ['nullable', 'integer', Rule::exists('media', 'id')->where('site_id', $site->id)];
            $rules['image.focus_x'] = ['nullable', 'numeric', 'between:0,1'];
            $rules['image.focus_y'] = ['nullable', 'numeric', 'between:0,1'];
            $rules['image.alt'] = ['nullable', 'string', 'max:200'];
        }
        if ($type->hasTab('canonical')) {
            $rules['canonical.mode'] = ['nullable', Rule::in(['auto', 'custom'])];
            $rules['canonical.url'] = ['nullable', 'url:http,https', 'max:500', 'required_if:canonical.mode,custom'];
            $rules['canonical.keep_query'] = ['nullable', 'string', 'max:200'];
        }
        if ($type->hasTab('access')) {
            // TB 13 Scheibe 2 (M-23): «Nur für Mitglieder» und ob der Schutz für Unterseiten gilt
            $rules['access.protected'] = ['sometimes', 'boolean'];
            $rules['access.children'] = ['sometimes', 'boolean'];
        }
        if ($type->hasTab('layout')) {
            $rules['layout.mode'] = ['nullable', Rule::in(['inherit', 'own'])];
            $rules['layout.template'] = ['nullable', 'string', Rule::in($templates), 'required_if:layout.mode,own'];
            $rules['layout.children'] = ['nullable', 'string', Rule::in(array_merge(['inherit'], $templates))];
        }
        if ($type->hasTab('cache')) {
            $rules['cache.shared_max_age'] = ['nullable', 'integer', 'between:0,31536000'];
            $rules['cache.private_max_age'] = ['nullable', 'integer', 'between:0,31536000'];
            $rules['cache.always_shared'] = ['sometimes', 'boolean'];
            $rules['cache.page_cache'] = ['sometimes', 'boolean'];
        }
        if ($type->hasTab('rights')) {
            $rules['rights.owner_id'] = ['nullable', 'integer', Rule::exists('site_user', 'user_id')->where('site_id', $site->id)];
            $rules['rights.roles'] = ['sometimes', 'array'];
            $rules['rights.roles.*'] = [Rule::in(app(Access::class)->keys($site->account_id))];
        }
        if ($type->hasTab('expert')) {
            $rules['expert.css_class'] = ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_ -]*$/'];
            $rules['expert.in_sitemap'] = ['sometimes', 'boolean'];
            $rules['expert.in_search'] = ['sometimes', 'boolean'];
            $rules['expert.hide_in_menu'] = ['sometimes', 'boolean'];
            // CR-006 (FO-K1): ein Modul an dieser Seite einhängen (Forum …)
            $rules['expert.mount'] = ['nullable', 'string', Rule::in(app(Mounts::class)->handles())];
        }
        if ($type->hasTab('keyboard')) {
            $rules['keyboard.shortcut'] = ['nullable', 'string', 'regex:/^[a-z0-9]$/'];
        }
        if ($type->hasTab('publication')) {
            $rules['publication.visible_from'] = ['nullable', 'date_format:Y-m-d H:i'];
            $rules['publication.visible_until'] = ['nullable', 'date_format:Y-m-d H:i', 'after:publication.visible_from'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $site = app(CurrentSite::class)->require();
            $pages = app(Pages::class);
            $current = $this->route('entry');
            $currentId = $current instanceof Entry ? $current->id : null;
            $entries = Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('id');
            $parentId = $this->input('parent_id');
            $parentId = is_numeric($parentId) ? (int) $parentId : null;
            if ($parentId !== null) {
                $parent = $entries->get($parentId);
                if ($parent === null) {
                    $validator->errors()->add('parent_id', __('Die Elternseite gehört nicht zu dieser Site.'));
                } elseif (! in_array($parent->pageType(), [PageType::Page, PageType::Folder], true)) {
                    $validator->errors()->add('parent_id', __('Nur Seiten und Ordner können Unterseiten haben.'));
                } else {
                    for ($node = $parent; $node !== null; $node = $node->parent_id === null ? null : $entries->get($node->parent_id)) {
                        if ($currentId !== null && $node->id === $currentId) {
                            $validator->errors()->add('parent_id', __('Eine Seite kann nicht unter sich selbst liegen.'));
                            break;
                        }
                    }
                }
            }
            if ($this->type() === PageType::Redirect && $this->input('redirect.kind') === 'internal') {
                $target = $this->input('redirect.entry_id');
                if (! is_numeric($target) || $entries->get((int) $target) === null || (int) $target === $currentId) {
                    $validator->errors()->add('redirect.entry_id', __('Das Ziel muss eine Seite dieser Site sein.'));
                }
            }
            if ($this->type()->hasTab('routing')) {
                foreach ($site->localeCodes() as $locale) {
                    $slug = $this->input('routing.slugs.'.$locale);
                    $slug = is_string($slug) && $slug !== '' ? $slug : null;
                    $name = $this->input('name.'.$locale);
                    if ($slug === null && is_string($name) && $name !== '') {
                        $slug = str($name)->slug()->toString();
                    }
                    if ($slug === null || $validator->errors()->has('routing.slugs.'.$locale)) {
                        continue;
                    }
                    $path = $pages->pathFor($site, $parentId, $locale, $slug, false);
                    $taken = $path === null ? null : $pages->entryIdAt($site, $locale, $path, false);
                    if ($taken !== null && $taken !== $currentId) {
                        $other = $entries->get($taken);
                        $otherTitle = $other?->latestVersion($site->default_locale)->title ?? '#'.$taken;
                        $validator->errors()->add('routing.slugs.'.$locale, __('Slug «:slug» ist in :locale schon vergeben — von «:title». Vorschlag: :suggestion', ['slug' => $slug, 'locale' => strtoupper($locale), 'title' => $otherTitle, 'suggestion' => $pages->freeSlug($site, $parentId, $locale, $slug, $currentId)]));
                    }
                }
            }
            $shortcut = $this->input('keyboard.shortcut');
            if (is_string($shortcut) && $shortcut !== '') {
                foreach ($entries as $entry) {
                    if ($entry->id !== $currentId && $entry->setting('keyboard.shortcut') === $shortcut) {
                        $validator->errors()->add('keyboard.shortcut', __('Das Kürzel «:shortcut» ist auf dieser Site schon vergeben.', ['shortcut' => $shortcut]));
                        break;
                    }
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $site = app(CurrentSite::class)->require();
        $attributes = ['type' => __('Typ'), 'parent_id' => __('Elternseite'), 'routing.priority' => __('Routenpriorität'), 'meta.robots' => __('Robots'), 'image.media_id' => __('Seitenbild'),
            'canonical.url' => __('Kanonische URL'), 'layout.template' => __('Layout'), 'layout.children' => __('Layout für Unterseiten'), 'cache.shared_max_age' => __('Geteilter Cache'),
            'cache.private_max_age' => __('Privater Cache'), 'rights.owner_id' => __('Besitzer'), 'expert.css_class' => __('CSS-Klasse'), 'keyboard.shortcut' => __('Tastaturkürzel'),
            'publication.visible_from' => __('Anzeigen ab'), 'publication.visible_until' => __('Anzeigen bis'), 'feed.type' => __('Quelle des Feeds'), 'feed.limit' => __('Anzahl'),
            'redirect.kind' => __('Art der Weiterleitung'), 'redirect.entry_id' => __('Zielseite'), 'redirect.url' => __('Ziel-URL'), 'redirect.urls.*' => __('Ziel-URL'), 'redirect.status' => __('Statuscode')];
        foreach ($site->localeCodes() as $locale) {
            $upper = strtoupper($locale);
            $attributes['name.'.$locale] = __('Name (:locale)', ['locale' => $upper]);
            $attributes['routing.slugs.'.$locale] = __('Slug (:locale)', ['locale' => $upper]);
            $attributes['meta.'.$locale.'.title'] = __('Seitentitel (:locale)', ['locale' => $upper]);
            $attributes['meta.'.$locale.'.description'] = __('Beschreibung (:locale)', ['locale' => $upper]);
        }

        return $attributes;
    }

    /**
     * Templates des Themes, die zu einem Seitentyp passen («for: page») — leer, wenn das Theme Fehler hat.
     *
     * @return list<string>
     */
    public static function templatesFor(Site $site, PageType $type): array
    {
        try {
            $theme = app(ThemeLoader::class)->forSite($site);
        } catch (ThemeException) {
            return [];
        }
        $for = $type === PageType::Feed ? 'page' : 'page';
        $names = [];
        foreach ($theme->document->all('template') as $template) {
            if (explode('.', (string) $template->value('for'))[0] === $for && $template->name !== null) {
                $names[] = $template->name;
            }
        }

        return $names;
    }

    /**
     * Inhaltstypen des Themes, die ein Feed listen kann (alles ausser den Seitentypen).
     *
     * @return list<string>
     */
    public static function contentTypesFor(Site $site): array
    {
        try {
            $schema = ThemeSchema::fromTheme(app(ThemeLoader::class)->forSite($site));
        } catch (ThemeException) {
            return [];
        }
        $types = [PageType::Page->value];
        foreach (array_keys($schema->types) as $name) {
            if (! PageType::isPageType($name)) {
                $types[] = $name;
            }
        }

        return array_values(array_unique($types));
    }
}
