<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\MountRequest;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Content\FrontendEditor;
use Basalto\Core\Content\PageAccess;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Redirect;
use Basalto\Core\Models\Site;
use Basalto\Core\Navigation\Navigation;
use Basalto\Core\Seo\NotFoundLog;
use Basalto\Core\Seo\PublishSettings;
use Basalto\Core\Seo\Sitemap;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Support\BackendLocale;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLibrary;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Die Auslieferung (M-01): Pfad mit Sprachpräfix → Seite, Inhalt, Weiterleitung, Feed oder Fehlerseite der Site;
 * Umleitungen nach Slug-Wechsel antworten 301; Seiteneinstellungen wirken (Metadaten, Cache-Kopfzeilen, Zeitfenster).
 * Ohne Site oder ohne veröffentlichte Startseite führt «/» ins Backend. Theme-Fehler zeigen im `local` die Befunde.
 */
final class FrontendController
{
    public function __construct(private readonly CurrentSite $current, private readonly Pages $pages, private readonly EntryRenderer $renderer,
        private readonly Sitemap $sitemap, private readonly PublishSettings $publish, private readonly NotFoundLog $notFound, private readonly PageCache $cache) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $this->pages->forget();
        $site = $this->current->get();
        $requestPath = '/'.trim($request->path(), '/');
        if ($site === null) {
            abort_unless($requestPath === '/', 404, 'Keine Site für diesen Host');

            return redirect()->route('basalto.dashboard');
        }
        if (! $site->isLive() && $request->user() === null) {
            return $this->notLive($site, $requestPath);
        }
        $preview = $this->themePreview($request, $site);
        $cached = $this->cache->lookup($request, $site);
        if ($cached instanceof Response) {
            return $cached;
        }
        $response = $this->respond($request, $site, $requestPath);
        if ($preview !== null && $response instanceof Response) {
            return self::previewBanner($response, $preview, BackendLocale::of($request->user()));
        }

        return $response instanceof Response ? $this->cache->remember($request, $site, $response) : $response;
    }

    /**
     * Theme-Vorschau (M-15, Mockup 27): Wer angemeldet ist und in Station «Themes» «Vorschau» gewählt hat, sieht die Site
     * mit diesem Theme — nur in dieser Sitzung, ohne Seiten-Cache (Angemeldete umgehen ihn), ohne die Site umzustellen.
     */
    private function themePreview(Request $request, Site $site): ?string
    {
        if ($request->user() === null || ! $request->hasSession()) {
            return null;
        }
        $name = $request->session()->get('basalto.theme_preview.'.$site->id);
        if (is_string($name) && app(ThemeLibrary::class)->exists($site, $name)) {
            $site->setAttribute('theme', $name);
            app(ThemeLoader::class)->previewTheme($site, $name);

            return __('Vorschau mit Theme «:name» — die Site selbst ist unverändert.', ['name' => $name], BackendLocale::of($request->user()));
        }
        // Entwürfe aus dem Theme-Editor (L-17): nur wer die Site bearbeiten darf, sieht sie
        $drafts = $request->session()->get(ThemeEditorController::previewKey($site));
        if (($drafts === true || is_string($drafts)) && Gate::allows('update', $site)) {
            // TB 22: die Entwürfe des Themes, das im Editor offen ist — auch eines, das die Site (noch) nicht zeigt
            if (is_string($drafts) && app(ThemeLibrary::class)->find($site, $drafts) !== null) {
                $site->setAttribute('theme', $drafts);
                app(ThemeLoader::class)->previewTheme($site, $drafts);
            }
            app(ThemeLoader::class)->previewDrafts($site);

            return __('Vorschau mit den Theme-Entwürfen — live ist noch die veröffentlichte Fassung.', [], BackendLocale::of($request->user()));
        }

        return null;
    }

    /** Ein schmaler Hinweis oben auf jeder Seite der Vorschau, mit dem Weg zurück — in der Sprache des Profils (N-01). */
    private static function previewBanner(Response $response, string $message, ?string $locale = null): Response
    {
        $content = (string) $response->getContent();
        $drafts = $message === __('Vorschau mit den Theme-Entwürfen — live ist noch die veröffentlichte Fassung.', [], $locale);
        $end = $drafts ? route('basalto.setup.theme-editor.preview.end') : route('basalto.setup.themes.preview.end');
        $bar = sprintf('<div role="status" style="position:sticky;top:0;z-index:2147483647;background:#1d1d1b;color:#fff;font:14px/1.4 system-ui,sans-serif;padding:8px 16px;display:flex;gap:16px;align-items:center">%s <a href="%s" style="color:#fff;text-decoration:underline">%s</a></div>',
            htmlspecialchars($message, ENT_QUOTES), htmlspecialchars($end, ENT_QUOTES), htmlspecialchars(__('Vorschau beenden', [], $locale), ENT_QUOTES));
        $content = (string) preg_replace('/<body([^>]*)>/i', '<body$1>'.$bar, $content, 1);
        $response->setContent($content);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function respond(Request $request, Site $site, string $requestPath): Response|RedirectResponse
    {
        $seoFile = $this->seoFile($request, $site, $requestPath);
        if ($seoFile !== null) {
            return $seoFile;
        }
        [$locale, $path] = $this->pages->splitLocale($site, $requestPath);
        app()->setLocale($locale);
        try {
            $hit = $this->pages->resolve($site, $locale, $path);
            if ($hit !== null) {
                return $this->deliver($request, $site, $hit['entry'], $hit['version'], $locale, $hit['path']);
            }
            // CR-006 (FO-K1): Pfade unter einer Einhängeseite beantwortet ihr Modul
            $mounted = $this->mountedAt($site, $locale, $path);
            if ($mounted !== null) {
                return $this->deliver($request, $site, $mounted['hit']['entry'], $mounted['hit']['version'], $locale, $mounted['hit']['path'], $mounted['rest']);
            }
            $redirect = Redirect::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_path', $requestPath)->first();
            if ($redirect !== null) {
                $redirect->forceFill(['hits' => $redirect->hits + 1, 'last_hit_at' => now()])->save();
                if ($redirect->status === 410) {
                    return $this->error($request, $site, $locale, $path, 404, 410);
                }

                return redirect($redirect->to_path, $redirect->status);
            }
            $themeTarget = $this->publish->redirects($site)[$requestPath] ?? null;
            if ($themeTarget !== null) {
                return redirect($themeTarget, 301);
            }
            if ($requestPath === '/' && $this->pages->errorPage($site, $locale, 404) === null) {
                return redirect()->route('basalto.dashboard');
            }
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                $this->notFound->record($site, $locale, $requestPath, $request->headers->get('referer'));
            }

            return $this->error($request, $site, $locale, $path, 404);
        } catch (ThemeException $e) {
            return $this->themeError($e);
        }
    }

    private function deliver(Request $request, Site $site, Entry $entry, EntryVersion $version, string $locale, string $path, string $rest = ''): Response|RedirectResponse
    {
        // TB 13 Scheibe 2 (M-23): geschützte Seiten nur für Mitglieder (und die Redaktion) — sonst die Anmeldung, nie der Inhalt
        $access = app(PageAccess::class);
        if (! $access->mayView($entry, $request)) {
            return $this->protectedPage($request, $site, $access, $locale, $path);
        }
        $type = $entry->pageType();
        if ($type === PageType::Redirect) {
            return $this->redirectPage($site, $entry, $locale);
        }
        $protected = $access->isProtected($entry);
        if ($type === PageType::Feed) {
            $feed = $this->feed($request, $site, $entry, $version, $locale);

            return $protected ? self::personal($feed) : $feed;
        }
        if (app(Mounts::class)->handleOf($entry) !== null) {
            return $this->deliverMount($request, $site, $entry, $version, $locale, $path, $rest, $protected);
        }
        $page = $this->pages->pageVariables($site, $entry, $version, $locale, $path, $request->getSchemeAndHttpHost());
        // TB 10 Scheibe 4: Frontend-Editor (M-19) — nur für Angemeldete mit Recht, sonst null; solche Antworten nie in den Seiten-Cache
        $editor = app(FrontendEditor::class)->state($request, $entry, $version);
        $response = response($this->renderer->forVisitors()->html($editor['version'] ?? $version, $page, $editor['marks'] ?? false));
        if ($editor !== null) {
            return $response->setContent(app(FrontendEditor::class)->inject((string) $response->getContent(), $editor['config']))->header('Cache-Control', 'no-store, private');
        }
        if ($protected) {
            return self::personal($response);
        }
        $this->cacheHeaders($response, $site, $entry);
        $this->allowPageCache($response, $site, $entry);
        // TB 24: Schaufenster des Marktplatzes (und andere Sites mit `noindex`) erscheinen in keiner Suchmaschine
        if (($entry->setting('meta.robots') !== null && str_contains((string) $entry->setting('meta.robots'), 'noindex')) || $site->setting('noindex') === true) {
            $response->header('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    /**
     * CR-006 (FO-K1): die nächste Einhängeseite über einem Pfad, der selbst keine Seite ist — `/community/thema/12`
     * → Seite `/community` mit Rest `thema/12`. Nur Seiten, deren Modul auf der Site an ist.
     *
     * @return array{hit: array{entry: Entry, version: EntryVersion, path: string}, rest: string}|null
     */
    private function mountedAt(Site $site, string $locale, string $path): ?array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
        $mounts = app(Mounts::class);
        if ($segments === [] || $mounts->all() === []) {
            return null;
        }
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $hit = $this->pages->resolve($site, $locale, '/'.implode('/', array_slice($segments, 0, $i)));
            if ($hit !== null && $mounts->handleOf($hit['entry']) !== null) {
                return ['hit' => $hit, 'rest' => implode('/', array_slice($segments, $i))];
            }
        }

        return null;
    }

    /**
     * CR-006 (FO-K1): Eine Einhängeseite ausliefern. Das Modul liefert Markup, Titel, Brotkrumen und Metadaten; das Markup
     * ist der erste Block der Seite (volle Breite, das Modul setzt seine Breite selbst), sonst steht es am Anfang von
     * <main>. Auf der Seite selbst bleiben Einleitung und Blöcke der Redaktion (unter dem Modul) stehen; auf den Pfaden
     * darunter zeigt die Seite nur das Modul. Persönliche Antworten (angemeldet, Formulare) nie im Cache.
     */
    private function deliverMount(Request $request, Site $site, Entry $entry, EntryVersion $version, string $locale, string $path, string $rest, bool $protected): Response|RedirectResponse
    {
        $mounts = app(Mounts::class);
        $base = rtrim($this->pages->urlPath($site, $locale, $path), '/');
        $result = $mounts->handler((string) $mounts->handleOf($entry))->handle(new MountRequest($site, $locale, $entry, $version, $base, $rest, $request));
        if ($result->kind === 'redirect') {
            return redirect((string) $result->location);
        }
        if ($result->kind === 'not_found') {
            return $this->error($request, $site, $locale, $path, 404);
        }
        if ($result->kind === 'raw') {
            $response = response($result->html, 200, ['Content-Type' => $result->head]);
            if ($protected) {
                return self::personal($response);
            }
            $this->cacheHeaders($response, $site, $entry);

            return $response;
        }
        $render = $version->replicate();
        $render->setRelation('entry', $entry);
        if ($rest !== '') {
            // Unter der Seite nur das Modul: ohne Einleitung und Blöcke der Redaktion
            $render->data = ['intro' => null, 'content' => []] + $version->data;
            if ($result->title !== null) {
                $render->title = $result->title;
            }
        }
        $page = $this->pages->pageVariables($site, $entry, $version, $locale, $path, $request->getSchemeAndHttpHost());
        $url = $request->getSchemeAndHttpHost().$base.($rest === '' ? '' : '/'.$rest);
        if ($rest !== '') {
            $page['url'] = $url;
            $page['canonical'] = $url;
            $page['alternates'] = array_map(static fn (array $alt): array => ['locale' => $alt['locale'], 'url' => rtrim($alt['url'], '/').'/'.$rest], $page['alternates']);
            $page['hreflang'] = array_map(static fn (array $alt): array => ['locale' => $alt['locale'], 'url' => rtrim($alt['url'], '/').'/'.$rest], $page['hreflang']);
        }
        if ($result->description !== null) {
            $page['meta'] = ['description' => $result->description];
        }
        if ($result->image !== null) {
            $page['image'] = ['src' => $result->image, 'alt' => ''];
        }
        if ($result->noindex) {
            $page['meta'] = [...($page['meta'] ?? []), 'robots' => 'noindex, follow'];
        }
        if ($result->crumbs !== [] || $rest !== '') {
            $crumbs = array_map(static fn (array $c): array => [...$c, 'current' => false], app(Navigation::class)->breadcrumbs($entry, $locale));
            if ($crumbs === []) {
                $crumbs[] = ['label' => $version->title, 'href' => $base === '' ? '/' : $base, 'current' => false];
            }
            $extra = $result->crumbs;
            foreach ($extra as $i => $crumb) {
                $crumbs[] = ['label' => $crumb['label'], 'href' => $crumb['href'], 'current' => $i === count($extra) - 1];
            }
            $page['breadcrumbs'] = $crumbs;
        }
        // Das Modul als erster Block — mit der Breite, die es selbst setzt; zeigt das Template keine Blöcke, an den Anfang von <main>
        $html = $this->renderer->forVisitors()->html($render, $page, false, [$result->html]);
        if (! str_contains($html, $result->html)) {
            $html = self::prepend($html, $result->html);
        }
        if ($result->head !== '') {
            $html = (string) preg_replace('#</head>#i', $result->head.'</head>', $html, 1);
        }
        $response = response($html, $result->status);
        if ($result->noindex) {
            $response->header('X-Robots-Tag', 'noindex');
        }
        if ($result->personal || $protected || $result->status !== 200) {
            return self::personal($response);
        }
        $this->cacheHeaders($response, $site, $entry);
        $this->allowPageCache($response, $site, $entry);

        return $response;
    }

    /**
     * TB 13 Scheibe 2 (M-23): Statt einer geschützten Seite die Anmeldung — 401 für Gäste, 403 für Angemeldete ohne
     * Mitgliedschaft. Gerendert wird die Fehlerseite 401 der Site (Text der Redaktion) oder, ohne sie, eine Seite
     * «Anmeldung erforderlich» im Layout des Themes; vom geschützten Eintrag kommt nichts ins HTML — kein Titel, keine
     * Brotkrumen, kein Inhalt. Nie im Seiten-Cache, nie im Browser- oder CDN-Cache, nie im Index der Suchmaschinen.
     */
    private function protectedPage(Request $request, Site $site, PageAccess $access, string $locale, string $path): Response
    {
        $urlPath = $this->pages->urlPath($site, $locale, $path);
        $html = $this->accountPage($request, $site, $locale, $urlPath, $this->pages->errorPage($site, $locale, 401), (string) __('Anmeldung erforderlich', [], $locale),
            (string) __('Diese Seite ist nur für Mitglieder. Bitte melden Sie sich an.', [], $locale), $access->deniedMarkup($site, $request, $locale, $urlPath));

        return response($html, $access->deniedStatus())->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store, private');
    }

    /**
     * TB 13 Scheibe 2: `GET /account/login` — eine eigene Anmeldeseite im Layout des Themes (Ziel von «Anmelden» im
     * Seitenkopf und der Mails der Mitglieder), damit kein Link die Adresse einer geschützten Seite verrät. Nur, wenn
     * auf der Site ein Modul das Frontend-Konto nutzt; persönlich (nie im Cache), nicht indexiert.
     */
    public function login(Request $request): Response
    {
        $site = $this->current->get();
        abort_if($site === null || ! CustomerAuth::available($site), 404);
        $this->pages->forget();
        $locale = in_array($request->query('sprache'), $site->localeCodes(), true) ? (string) $request->query('sprache') : $site->default_locale;
        app()->setLocale($locale);
        $path = Html::url('/account/login', $locale === $site->default_locale ? [] : ['sprache' => $locale]);
        try {
            $html = $this->accountPage($request, $site, $locale, $path, null, (string) __('Konto', [], $locale), '', app(PageAccess::class)->loginMarkup($site, $request, $locale, $path));
        } catch (ThemeException $e) {
            return $this->themeError($e);
        }

        return response($html)->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store, private');
    }

    /**
     * Eine Seite des Frontend-Kontos im Layout des Themes: die Fehlerseite 401 der Site (falls übergeben) oder eine
     * Seite ohne Eintrag mit Titel und Text. Das Markup (Anmeldung, Mitgliedschaft) steht an der Stelle der Einleitung —
     * über einen Platzhalter, den das Theme mit `page.intro` ausgibt —, sonst am Anfang von <main>.
     *
     * @param  array{entry: Entry, version: EntryVersion, path: string}|null  $hit
     */
    private function accountPage(Request $request, Site $site, string $locale, string $urlPath, ?array $hit, string $title, string $text, string $markup): string
    {
        $marker = 'basalto-anmeldung-'.bin2hex(random_bytes(8));
        $paragraph = static fn (string $value): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $value]]];
        $url = $request->getSchemeAndHttpHost().$urlPath;
        if ($hit !== null) {
            $version = $hit['version']->replicate();
            $version->setRelation('entry', $hit['entry']);
            $intro = $version->data['intro'] ?? null;
            $text = RichText::toText($intro);
            $doc = is_array($intro) && ($intro['type'] ?? null) === 'doc' ? $intro : ['type' => 'doc', 'content' => []];
            $doc['content'] = [...(is_array($doc['content'] ?? null) ? $doc['content'] : []), $paragraph($marker)];
            $version->data = ['intro' => $doc] + $version->data;
            $page = $this->pages->pageVariables($site, $hit['entry'], $hit['version'], $locale, $urlPath, $request->getSchemeAndHttpHost());
            $page['url'] = $url;
            $page['canonical'] = $url;
        } else {
            $entry = new Entry(['site_id' => $site->id, 'type' => PageType::Error401->value]);
            $entry->setRelation('site', $site);
            $version = new EntryVersion(['locale' => $locale, 'title' => $title, 'slug' => '',
                'data' => ['intro' => ['type' => 'doc', 'content' => $text === '' ? [$paragraph($marker)] : [$paragraph($text), $paragraph($marker)]]]]);
            $version->setRelation('entry', $entry);
            $page = ['path' => $urlPath, 'url' => $url, 'locale' => $locale, 'is_home' => false, 'canonical' => $url, 'image' => null, 'alternates' => [], 'hreflang' => []];
        }
        $page['meta'] = ['robots' => 'noindex, nofollow', 'description' => $text !== '' ? $text : $title];
        $html = $this->renderer->forVisitors()->html($version, $page);

        return str_contains($html, '<p>'.$marker.'</p>') ? str_replace('<p>'.$marker.'</p>', $markup, $html) : self::prepend(str_replace($marker, '', $html), $markup);
    }

    /** Markup an den Anfang von <main> setzen — ohne <main> an den Anfang von <body>. */
    private static function prepend(string $html, string $markup): string
    {
        foreach (['/<main\b[^>]*>/i', '/<body\b[^>]*>/i'] as $pattern) {
            $result = preg_replace_callback($pattern, static fn (array $m): string => $m[0].$markup, $html, 1, $count);
            if ($count > 0 && is_string($result)) {
                return $result;
            }
        }

        return $markup.$html;
    }

    /** Eine Antwort nur für diese Person (geschützte Seite für Mitglieder): kein Seiten-, Browser- oder CDN-Cache, nicht indexieren. */
    private static function personal(Response $response): Response
    {
        $response->headers->remove(PageCache::TTL_HEADER);

        return $response->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
    }

    /** Eine Weiterleitungs-Seite: intern auf eine andere Seite (in derselben Sprache), extern auf eine URL. */
    private function redirectPage(Site $site, Entry $entry, string $locale): Response|RedirectResponse
    {
        $target = is_array($entry->redirect_to) ? $entry->redirect_to : [];
        $status = (int) ($target['status'] ?? 302) === 301 ? 301 : 302;
        $external = $entry->externalRedirect($locale);
        if ($external !== null) {
            return redirect()->away($external, $status);
        }
        $destination = is_numeric($target['entry_id'] ?? null) ? Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->find((int) $target['entry_id']) : null;
        $url = $destination === null ? null : $this->pages->urlOf($destination, $locale);
        if ($url === null) {
            return response(__('Das Ziel dieser Weiterleitung ist nicht veröffentlicht.'), 404);
        }

        return redirect($url, $status);
    }

    /** Ein Feed (RSS 2.0) der veröffentlichten Inhalte eines Typs, neueste zuerst (Reiter «Name und Typ»). */
    private function feed(Request $request, Site $site, Entry $entry, EntryVersion $version, string $locale): Response
    {
        $type = (string) $entry->setting('feed.type', PageType::Page->value);
        $limit = max(1, min(100, (int) $entry->setting('feed.limit', 20)));
        $base = $request->getSchemeAndHttpHost();
        $items = [];
        $entries = Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('type', $type)->get();
        foreach ($entries as $candidate) {
            $published = $candidate->resolve($locale);
            $url = $this->pages->urlOf($candidate, $locale);
            // TB 13 Scheibe 2: Feeds sind öffentlich (und ohne Anmeldung lesbar) — geschützte Seiten nie darin
            if ($published === null || $url === null || ! $this->pages->isVisible($candidate) || $this->pages->isProtected($candidate)) {
                continue;
            }
            $date = is_string($published->data['date'] ?? null) && strtotime($published->data['date']) !== false ? Carbon::parse($published->data['date']) : $published->published_at;
            $items[] = ['title' => $published->title, 'link' => $base.$url, 'date' => $date, 'description' => RichText::toText($published->data['intro'] ?? null)];
        }
        usort($items, static fn (array $a, array $b): int => ($b['date']?->getTimestamp() ?? 0) <=> ($a['date']?->getTimestamp() ?? 0));
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1);
        if ($entry->setting('feed.format') === 'atom') {
            return $this->atom($site, $entry, $version, array_slice($items, 0, $limit), $base, $locale);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>';
        $xml .= sprintf('<title>%s</title><link>%s</link><description>%s</description>', $e($version->title), $e($base.($this->pages->urlOf($entry, $locale) ?? '/')), $e(RichText::toText($version->data['intro'] ?? null) ?: $site->name));
        foreach (array_slice($items, 0, $limit) as $item) {
            $xml .= sprintf('<item><title>%s</title><link>%s</link><guid>%s</guid>%s%s</item>', $e($item['title']), $e($item['link']), $e($item['link']),
                $item['date'] === null ? '' : sprintf('<pubDate>%s</pubDate>', $e($item['date']->toRssString())),
                $item['description'] === '' ? '' : sprintf('<description>%s</description>', $e($item['description'])));
        }
        $xml .= '</channel></rss>';
        $response = response($xml)->header('Content-Type', 'application/rss+xml; charset=UTF-8');
        $this->cacheHeaders($response, $site, $entry);
        $this->allowPageCache($response, $site, $entry);

        return $response;
    }

    /**
     * Derselbe Feed als Atom 1.0 (M-13) — Einstellung `feed.format: atom` im Reiter «Name und Typ» oder im Seed.
     *
     * @param  list<array{title: string, link: string, date: \DateTimeInterface|null, description: string}>  $items
     */
    private function atom(Site $site, Entry $entry, EntryVersion $version, array $items, string $base, string $locale): Response
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1);
        $self = $base.($this->pages->urlOf($entry, $locale) ?? '/');
        $updated = $items[0]['date'] ?? $version->published_at ?? now();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom" xml:lang="'.$e($locale).'">';
        $xml .= sprintf('<title>%s</title><id>%s</id><link rel="self" href="%s"/><link rel="alternate" href="%s"/><updated>%s</updated><author><name>%s</name></author>',
            $e($version->title), $e($self), $e($self), $e($base.'/'), $updated->format(DATE_ATOM), $e($site->name));
        foreach ($items as $item) {
            $xml .= sprintf('<entry><title>%s</title><id>%s</id><link href="%s"/><updated>%s</updated>%s</entry>', $e($item['title']), $e($item['link']), $e($item['link']),
                ($item['date'] ?? $updated)->format(DATE_ATOM), $item['description'] === '' ? '' : sprintf('<summary>%s</summary>', $e($item['description'])));
        }
        $response = response($xml.'</feed>')->header('Content-Type', 'application/atom+xml; charset=UTF-8');
        $this->cacheHeaders($response, $site, $entry);
        $this->allowPageCache($response, $site, $entry);

        return $response;
    }

    /** Die Fehlerseite der Site für einen Code in der Sprache — sonst die nackte Antwort. 410 zeigt die 404-Seite. */
    private function error(Request $request, Site $site, string $locale, string $path, int $code, ?int $status = null): Response
    {
        $status ??= $code;
        $hit = $this->pages->errorPage($site, $locale, $code);
        if ($hit === null) {
            abort($status);
        }
        $page = $this->pages->pageVariables($site, $hit['entry'], $hit['version'], $locale, $path, $request->getSchemeAndHttpHost());
        $page['meta'] = ['robots' => 'noindex, nofollow'];

        return response($this->renderer->forVisitors()->html($hit['version'], $page), $status)->header('X-Robots-Tag', 'noindex');
    }

    /** `/robots.txt`, `/sitemap.xml` und `/sitemap-<sprache>.xml` aus dem Block `publish` des Themes (M-10). */
    private function seoFile(Request $request, Site $site, string $requestPath): ?Response
    {
        $base = $request->getSchemeAndHttpHost();
        if ($requestPath === '/robots.txt') {
            $lines = ['User-agent: *', $this->publish->robotsAllowed($site) ? 'Disallow: /admin/' : 'Disallow: /'];
            if ($this->publish->sitemapEnabled($site) && $this->publish->robotsAllowed($site)) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.$base.'/sitemap.xml';
            }

            return response(implode("\n", $lines)."\n")->header('Content-Type', 'text/plain; charset=UTF-8')->header(PageCache::TTL_HEADER, (string) $this->cache->ttlFor($site));
        }
        $isIndex = $requestPath === '/sitemap.xml';
        if (! $isIndex && preg_match('#^/sitemap-([a-z]{2,3}(?:-[a-z0-9]{2,4})?)\.xml$#i', $requestPath, $m) !== 1) {
            return null;
        }
        if (! $this->publish->sitemapEnabled($site)) {
            abort(404);
        }
        $xml = $isIndex ? $this->sitemap->index($site, $base) : $this->sitemap->forLocale($site, strtolower($m[1]), $base);
        abort_if($xml === null, 404);

        return response($xml)->header('Content-Type', 'application/xml; charset=UTF-8')->header(PageCache::TTL_HEADER, (string) $this->cache->ttlFor($site));
    }

    /** Seiten-Cache (M-16): erlaubt, ausser die Seite ist im Reiter «Cache» ausgenommen; nie über das Zeitfenster hinaus. */
    private function allowPageCache(Response $response, Site $site, Entry $entry): void
    {
        if ($entry->setting('cache.page_cache', true) === false) {
            return;
        }
        $response->header(PageCache::TTL_HEADER, (string) $this->cache->ttlFor($site, $this->pages->secondsUntilHidden($entry)));
    }

    /** Cache-Kopfzeilen aus dem Reiter «Cache» (Vorgabe aus den Site-Einstellungen), begrenzt durch das Zeitfenster. */
    private function cacheHeaders(Response $response, Site $site, Entry $entry): void
    {
        $shared = (int) $entry->setting('cache.shared_max_age', $site->setting('cache.shared_max_age', 0));
        $private = (int) $entry->setting('cache.private_max_age', $site->setting('cache.private_max_age', 0));
        $limit = $this->pages->secondsUntilHidden($entry);
        if ($limit !== null) {
            $shared = min($shared, $limit);
            $private = min($private, $limit);
        }
        if ($shared <= 0 && $private <= 0) {
            return;
        }
        $response->setPublic();
        $response->setMaxAge(max(0, $private));
        $response->setSharedMaxAge(max(0, $shared));
    }

    /**
     * Noch nicht live (L-06): Gäste sehen «Bald online» (503 mit Retry-After, noindex), Suchmaschinen eine gesperrte
     * robots.txt; Angemeldete sehen die Site schon. Live schaltet `basalto:publish`.
     */
    private function notLive(Site $site, string $requestPath): Response
    {
        if ($requestPath === '/robots.txt') {
            return response("User-agent: *\nDisallow: /\n")->header('Content-Type', 'text/plain; charset=UTF-8');
        }
        $name = htmlspecialchars($site->name, ENT_QUOTES);
        $html = sprintf('<!doctype html><html lang="%1$s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>%2$s</title>'
            .'<style>body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f4f0;color:#23262b}main{text-align:center;padding:24px}h1{font-size:24px;margin:0 0 8px}p{margin:0;color:#3b3f46}</style></head>'
            .'<body><main><h1>%2$s</h1><p>%3$s</p></main></body></html>', htmlspecialchars($site->default_locale, ENT_QUOTES), $name, $site->default_locale === 'en' ? 'Coming soon.' : 'Bald online.');

        return response($html, 503)->header('Retry-After', '3600')->header('X-Robots-Tag', 'noindex');
    }

    /** Theme-Fehler: im `local` und `testing` die Befunde mit Datei, Zeile, Spalte und Vorschlag (L-13); sonst 503. */
    private function themeError(ThemeException $e): Response
    {
        if (! app()->environment(['local', 'testing'])) {
            return response('<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Vorübergehend nicht verfügbar</title></head><body><h1>Vorübergehend nicht verfügbar</h1><p>Das Theme dieser Site hat einen Fehler. Die Betreiber sind informiert.</p></body></html>', 503);
        }
        $items = '';
        foreach ($e->diagnostics as $d) {
            $items .= sprintf('<li><code>%s:%d:%d</code> %s%s%s</li>', htmlspecialchars($d->file, ENT_QUOTES), $d->line, $d->column,
                $d->step !== null ? sprintf('<b>[%s]</b> ', htmlspecialchars($d->step, ENT_QUOTES)) : '', htmlspecialchars($d->message, ENT_QUOTES),
                $d->suggestion !== null ? sprintf(' — meinten Sie <code>%s</code>?', htmlspecialchars($d->suggestion, ENT_QUOTES)) : '');
        }
        $html = sprintf('<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Lava: Theme «%1$s» hat Fehler</title>'
            .'<style>body{font-family:"IBM Plex Sans",system-ui,sans-serif;background:#f6f4f0;color:#23262b;margin:0;padding:48px}h1{font-size:22px;margin:0 0 6px}h1 b{color:#e4572e}p{color:#3b3f46;margin:0 0 20px}ul{list-style:none;padding:0;margin:0;background:#fff;border:1px solid #e3e6ea;border-radius:8px}li{padding:12px 16px;border-top:1px solid #e3e6ea;border-left:3px solid #b8232f}li:first-child{border-top:0}code{font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:12.5px;background:#f6f4f0;padding:1px 5px;border-radius:4px}</style></head>'
            .'<body><h1><b>Lava</b> · Theme «%1$s» hat Fehler</h1><p>%2$d Befund(e) — die Seite erscheint, sobald die rote Linie wieder grün ist. Diese Ansicht gibt es nur in der Entwicklung.</p><ul>%3$s</ul></body></html>',
            htmlspecialchars($e->themeName, ENT_QUOTES), count($e->diagnostics), $items);

        return response($html, 500);
    }
}
