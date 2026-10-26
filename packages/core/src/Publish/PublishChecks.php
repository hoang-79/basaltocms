<?php

declare(strict_types=1);

namespace Basalto\Core\Publish;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Forms\FormCatalog;
use Basalto\Core\Media\MediaUsages;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Models\Redirect;
use Basalto\Core\Models\Site;
use Basalto\Core\Seo\PublishSettings;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Theme\Line;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Was `basalto:publish` vor dem Live-Schalten prüft (L-06): rote Linie, Rechtsseiten aus `publish.legal` (vorhanden,
 * veröffentlicht, in jeder Pflichtsprache, ohne offene Platzhalter `[[…]]`), Umleitungen (Ziel erreichbar, kein
 * Kreis) — immer; dazu, was das Theme unter `check:` nennt: Links, Bilder, Alt-Texte, Pflichtsprachen, Formulare.
 * Ein Fehler hält die Site offline, eine Warnung nicht.
 *
 * @phpstan-type Finding array{severity: 'error'|'warning', area: string, message: string, url: string|null}
 */
final class PublishChecks
{
    /** @var array<string, string> Prüfung → Bereich in der Ausgabe */
    public const AREAS = ['line' => 'Rote Linie', 'legal' => 'Rechtsseiten', 'translations' => 'Pflichtsprachen', 'links' => 'Links', 'images' => 'Bilder',
        'alt-texts' => 'Alt-Texte', 'forms' => 'Formulare', 'redirects' => 'Umleitungen'];

    /** @return array<string, string> Bereich → Beschriftung in der Sprache der Oberfläche (N-01) */
    public static function areas(): array
    {
        return ['line' => __('Rote Linie'), 'legal' => __('Rechtsseiten'), 'translations' => __('Pflichtsprachen'), 'links' => __('Links'), 'images' => __('Bilder'),
            'alt-texts' => __('Alt-Texte'), 'forms' => __('Formulare'), 'redirects' => __('Umleitungen')];
    }

    /** @var list<Finding> */
    private array $findings = [];

    /** @var array<string, int> Bereich → Zahl geprüfter Dinge */
    private array $counted = [];

    public function __construct(private readonly ThemeLoader $themes, private readonly Pages $pages, private readonly PublishSettings $publish, private readonly MediaUsages $media,
        private readonly FormCatalog $forms, private readonly Settings $settings) {}

    /** @return array{findings: list<Finding>, counted: array<string, int>, checks: list<string>} */
    public function run(Site $site): array
    {
        $this->findings = [];
        $this->counted = [];
        $this->pages->forget();
        $declared = $this->declaredChecks($site);
        $this->line($site);
        $this->legal($site);
        $this->redirects($site);
        $versions = $this->publishedVersions($site);
        if (in_array('translations', $declared, true)) {
            $this->translations($site);
        }
        if (in_array('links', $declared, true)) {
            $this->links($site, $versions);
        }
        if (in_array('images', $declared, true) || in_array('alt-texts', $declared, true)) {
            $this->images($site, $versions, in_array('alt-texts', $declared, true) || $this->settings->get('media.alt_required', false, $site) === true);
        }
        if (in_array('forms', $declared, true)) {
            $this->formChecks($site);
        }

        return ['findings' => $this->findings, 'counted' => $this->counted, 'checks' => array_values(array_unique(array_merge(['line', 'legal', 'redirects'], $declared)))];
    }

    /** @return list<string> */
    private function declaredChecks(Site $site): array
    {
        try {
            $property = $this->themes->forSite($site)->document->find('publish')?->property('check');
        } catch (ThemeException) {
            return [];
        }

        return $property === null ? ['links', 'images', 'alt-texts', 'translations', 'forms'] : array_values(array_filter(array_map(static fn ($v): string => strtolower($v->text()), $property->values), static fn (string $c): bool => array_key_exists($c, self::AREAS)));
    }

    private function line(Site $site): void
    {
        $compiled = $this->themes->compileFor($site);
        $line = new Line($compiled['theme'], $compiled['diagnostics']);
        $this->counted['line'] = count($line->rows());
        foreach ($compiled['diagnostics'] as $d) {
            if ($d->isError()) {
                $this->add('error', 'line', $d->format(), route('basalto.setup'));
            }
        }
    }

    private function legal(Site $site): void
    {
        $legal = $this->themes->forSite($site)->document->find('publish')?->childrenOf('legal')[0] ?? null;
        $required = $site->requiredLocales();
        foreach ($legal === null ? [] : $legal->properties as $property) {
            $this->counted['legal'] = ($this->counted['legal'] ?? 0) + 1;
            $name = $property->text();
            $label = $property->key === 'datenschutz' ? __('Datenschutzseite') : ($property->key === 'impressum' ? __('Impressum') : __('Rechtsseite «:key»', ['key' => $property->key]));
            $entry = $this->seedEntry($site, $name) ?? $this->entryAtPath($site, '/'.trim($name, '/'));
            if ($entry === null) {
                $this->add('error', 'legal', __(':label «:name» fehlt — ohne sie geht die Site nicht live. Anlegen im Seitenbaum oder mit einer Vorlage (Recht).', ['label' => $label, 'name' => $name]), route('basalto.pages.index'));

                continue;
            }
            foreach ($required as $locale) {
                $version = $entry->versions()->where('locale', $locale)->where('status', EntryStatus::Published->value)->first();
                if ($version === null) {
                    $this->add('error', 'legal', __(':label «:name» ist in :locale nicht veröffentlicht.', ['label' => $label, 'name' => $name, 'locale' => strtoupper($locale)]), route('basalto.entries.edit', [$entry, $locale]));

                    continue;
                }
                if (LegalPages::isEmpty($version->data)) {
                    $this->add('warning', 'legal', __(':label «:name» (:locale) ist leer — eine Vorlage einsetzen (Einrichtung › Veröffentlichen).', ['label' => $label, 'name' => $name, 'locale' => strtoupper($locale)]), route('basalto.entries.edit', [$entry, $locale]));
                }
                if (preg_match('/\[\[([^\]]+)\]\]/u', (string) json_encode($version->data, JSON_UNESCAPED_UNICODE), $m) === 1) {
                    $this->add('error', 'legal', __(':label «:name» (:locale) hat einen offenen Platzhalter «[[:placeholder]]» — die Vorlage muss ausgefüllt sein.', ['label' => $label, 'name' => $name, 'locale' => strtoupper($locale), 'placeholder' => $m[1]]), route('basalto.entries.edit', [$entry, $locale]));
                }
            }
        }
    }

    private function translations(Site $site): void
    {
        $required = array_values(array_diff($site->requiredLocales(), [$site->default_locale]));
        foreach ($this->pages->entries($site) as $entry) {
            $type = $entry->pageType();
            if ($entry->trashed() || ($type !== null && ! $type->hasContent()) || ! $entry->versions()->where('locale', $site->default_locale)->where('status', EntryStatus::Published->value)->exists()) {
                continue;
            }
            $this->counted['translations'] = ($this->counted['translations'] ?? 0) + 1;
            foreach ($required as $locale) {
                if (! $entry->versions()->where('locale', $locale)->where('status', EntryStatus::Published->value)->exists()) {
                    $title = (string) $entry->versions()->where('locale', $site->default_locale)->orderByDesc('number')->value('title');
                    $this->add('error', 'translations', __('«:title» ist in der Pflichtsprache :locale nicht veröffentlicht.', ['title' => $title, 'locale' => strtoupper($locale)]), route('basalto.entries.translation', [$entry, $locale]));
                }
            }
        }
    }

    /** @param  list<EntryVersion>  $versions */
    private function links(Site $site, array $versions): void
    {
        foreach ($versions as $version) {
            $hrefs = [];
            self::hrefs($version->data, $hrefs);
            foreach (array_unique($hrefs) as $href) {
                $this->counted['links'] = ($this->counted['links'] ?? 0) + 1;
                if (! $this->reachable($site, $href)) {
                    $this->add('error', 'links', __('Link auf :href führt ins Leere — in «:title» (:locale).', ['href' => $href, 'title' => $version->title, 'locale' => strtoupper($version->locale)]), route('basalto.entries.edit', [$version->entry_id, $version->locale]));
                }
            }
        }
        foreach (NavigationItem::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNotNull('url')->get() as $item) {
            $this->counted['links'] = ($this->counted['links'] ?? 0) + 1;
            if (is_string($item->url) && str_starts_with($item->url, '/') && ! $this->reachable($site, $item->url)) {
                $this->add('error', 'links', __('Menüeintrag «:label» zeigt auf :url — dort ist keine Seite.', ['label' => $item->labelFor([$site->default_locale]) ?? $item->url, 'url' => $item->url]), route('basalto.setup.navigation.index'));
            }
        }
    }

    /** @param  list<EntryVersion>  $versions */
    private function images(Site $site, array $versions, bool $alt): void
    {
        foreach ($versions as $version) {
            $entry = $version->entry;
            $used = $this->media->collect($entry, $version);
            $this->counted['images'] = ($this->counted['images'] ?? 0) + count($used);
            if ($used !== []) {
                $present = Media::query()->withoutGlobalScopes()->whereKey(array_keys($used))->pluck('id')->all();
                foreach (array_diff(array_keys($used), $present) as $missing) {
                    $this->add('error', 'images', __('Bild #:id fehlt oder liegt im Papierkorb — in «:title» (:locale).', ['id' => $missing, 'title' => $version->title, 'locale' => strtoupper($version->locale)]), route('basalto.entries.edit', [$version->entry_id, $version->locale]));
                }
            }
            if ($alt) {
                foreach ($this->media->missingAltTexts($version) as $name) {
                    $this->counted['alt-texts'] = ($this->counted['alt-texts'] ?? 0) + 1;
                    $this->add('error', 'alt-texts', __('Bild «:name» ohne Alt-Text in :locale — in «:title».', ['name' => $name, 'locale' => strtoupper($version->locale), 'title' => $version->title]), route('basalto.media.index'));
                }
            }
        }
    }

    private function formChecks(Site $site): void
    {
        $mailer = (string) config('mail.default');
        foreach ($this->forms->all($site) as $form) {
            $this->counted['forms'] = ($this->counted['forms'] ?? 0) + 1;
            $recipients = $form->recipients();
            if ($recipients === [] && ! $form->stores() && $form->webhookUrl() === null) {
                $this->add('error', 'forms', __('Formular «:handle» hat keine Aktion — ohne Empfänger, Speichern oder Webhook gingen Eingänge verloren.', ['handle' => $form->handle]), route('basalto.forms.edit', $form->handle));
            }
            $raw = $form->definition->property('to');
            if ($raw !== null && count($recipients) < count(array_filter(preg_split('/[,;\s]+/', $raw->text()) ?: []))) {
                $this->add('error', 'forms', __('Formular «:handle» hat einen ungültigen Empfänger («:address»).', ['handle' => $form->handle, 'address' => $raw->text()]), route('basalto.forms.edit', $form->handle));
            }
            if ($recipients !== [] && in_array($mailer, ['log', 'array'], true) && app()->environment('production')) {
                $this->add('warning', 'forms', __('Formular «:handle» meldet per Mail, aber der Mailer ist «:mailer» — Mails kommen nicht an.', ['handle' => $form->handle, 'mailer' => $mailer]), null);
            }
        }
    }

    private function redirects(Site $site): void
    {
        $rows = Redirect::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('status', '!=', 410)->pluck('to_path', 'from_path')->all();
        $all = $rows + $this->publish->redirects($site);
        foreach ($all as $from => $to) {
            $this->counted['redirects'] = ($this->counted['redirects'] ?? 0) + 1;
            $seen = [$from];
            $target = $to;
            while (isset($all[$target]) && ! in_array($target, $seen, true)) {
                $seen[] = $target;
                $target = $all[$target];
            }
            if (in_array($target, $seen, true)) {
                $this->add('error', 'redirects', __('Umleitung :from läuft im Kreis (:chain).', ['from' => $from, 'chain' => implode(' → ', [...$seen, $target])]), route('basalto.pages.index', ['tab' => 'redirects']));
            } elseif (str_starts_with($target, '/') && ! $this->reachable($site, $target, false)) {
                $this->add('error', 'redirects', __('Umleitung :from → :to: dort ist keine veröffentlichte Seite.', ['from' => $from, 'to' => $target]), route('basalto.pages.index', ['tab' => 'redirects']));
            }
        }
    }

    /** Ist ein interner Pfad erreichbar — Seite, Umleitung, Formular oder Datei der Auslieferung? */
    private function reachable(Site $site, string $href, bool $followRedirects = true): bool
    {
        if (! str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return true;
        }
        $path = '/'.trim((string) parse_url($href, PHP_URL_PATH), '/');
        if (in_array($path, ['/robots.txt', '/sitemap.xml'], true) || str_starts_with($path, '/forms/') || str_starts_with($path, '/media/') || str_starts_with($path, '/sitemap-')) {
            return true;
        }
        // CR-004: Systemrouten der Installation (z. B. /_download/installer, /_recht/…) — alles ausser der Fallback-Route
        try {
            if (! app('router')->getRoutes()->match(Request::create($path, 'GET'))->isFallback) {
                return true;
            }
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            // keine Route — dann muss es eine Seite sein
        }
        [$locale, $inner] = $this->pages->splitLocale($site, $path);
        if ($this->pages->resolve($site, $locale, $inner) !== null) {
            return true;
        }
        if (! $followRedirects) {
            return false;
        }

        return Redirect::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('from_path', $path)->exists() || isset($this->publish->redirects($site)[$path]);
    }

    /** @return list<EntryVersion> veröffentlichte Fassungen aller Einträge der Site */
    private function publishedVersions(Site $site): array
    {
        $ids = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('deleted_at')->pluck('id');

        return array_values(EntryVersion::query()->whereIn('entry_id', $ids)->where('status', EntryStatus::Published->value)->with('entry.site')->get()->all());
    }

    private function seedEntry(Site $site, string $name): ?Entry
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:page:%s', $site->id, $name))->toString();

        return Entry::query()->withoutGlobalScopes()->where('uuid', $uuid)->whereNull('deleted_at')->first();
    }

    private function entryAtPath(Site $site, string $path): ?Entry
    {
        $id = $this->pages->entryIdAt($site, $site->default_locale, $path);

        return $id === null ? null : Entry::query()->withoutGlobalScopes()->find($id);
    }

    /**
     * Alle `href` in Fassungsdaten: Link-Felder und Links im Fliesstext.
     *
     * @param  list<string>  $out
     */
    private static function hrefs(mixed $data, array &$out): void
    {
        if (! is_array($data)) {
            return;
        }
        if (is_string($data['href'] ?? null) && $data['href'] !== '') {
            $out[] = $data['href'];
        }
        foreach ($data as $value) {
            self::hrefs($value, $out);
        }
    }

    /** @param  'error'|'warning'  $severity */
    private function add(string $severity, string $area, string $message, ?string $url): void
    {
        $this->findings[] = ['severity' => $severity, 'area' => self::areas()[$area] ?? $area, 'message' => $message, 'url' => $url];
    }
}
