<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Backend\Schema\Action;
use Basalto\Core\Backend\Schema\AdminPage;
use Basalto\Core\Backend\Schema\Column;
use Basalto\Core\Backend\Schema\Form;
use Basalto\Core\Backend\Schema\FormField;
use Basalto\Core\Backend\Schema\Row;
use Basalto\Core\Backend\Schema\Stat;
use Basalto\Core\Backend\Schema\Table;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Seo\IndexNow;
use Basalto\Core\Seo\PublishSettings;
use Basalto\Core\Seo\SeoHead;
use Basalto\Core\Seo\Sitemap;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Verwaltung › Suchmaschinen (CR-019, TB 107): was Suchmaschinen und Netzwerke von der Site sehen — Adressen in der
 * Sitemap, ob Suchmaschinen erlaubt sind, Meldungen über IndexNow; dazu das Vorschaubild der Site für geteilte Links,
 * die Bestätigungscodes für die Google Search Console und Bing Webmaster Tools und IndexNow an oder aus. Titel,
 * Beschreibung und Bild jeder Seite setzt Basalto selbst ({@see SeoHead}).
 */
final class SearchEngineController
{
    public function __construct(private readonly CurrentSite $current, private readonly Sitemap $sitemap, private readonly PublishSettings $publish, private readonly IndexNow $indexNow,
        private readonly Settings $settings, private readonly Dates $dates, private readonly Recorder $recorder) {}

    public function index(): AdminPage
    {
        $site = $this->current->require();
        $base = rtrim($site->publicUrl(), '/');
        $urls = $this->sitemap->count($site);
        $robots = $this->publish->robotsAllowed($site);
        $indexNow = $this->indexNow->enabled($site);
        $wanted = (bool) $this->settings->get('seo.indexnow', true, $site);
        $log = DB::table('indexnow_urls')->where('site_id', $site->id);
        $sent30 = (clone $log)->where('status', IndexNow::SENT)->where('sent_at', '>=', now()->subDays(30))->count();
        $lastSent = (clone $log)->where('status', IndexNow::SENT)->max('sent_at');

        $page = AdminPage::make(__('Suchmaschinen'))
            ->crumb(__('Verwaltung › Suchmaschinen'))
            ->lead(__('Was Suchmaschinen und soziale Netzwerke von Ihrer Website sehen. Titel, Beschreibung, Vorschaubild und strukturierte Daten setzt Basalto auf jeder Seite selbst — eigene Angaben im Reiter «Metadaten» einer Seite gehen vor. Die Sitemap hält sich von selbst aktuell.'))
            ->action(Action::link(__('Sitemap öffnen'), $base.'/sitemap.xml'))
            ->action(Action::link(__('robots.txt öffnen'), $base.'/robots.txt'))
            ->stats([
                Stat::make(__('Adressen in der Sitemap'), $urls)->hint(trans_choice(':count Sprache|:count Sprachen', count($site->localeCodes()), ['count' => count($site->localeCodes())])),
                Stat::make(__('Suchmaschinen'), $robots ? __('erlaubt') : __('nicht erlaubt'))->tone($robots ? 'success' : 'warning')
                    ->hint($robots ? __('robots.txt nennt die Sitemap') : ((bool) config('basalto.noindex', false) ? __('Testumgebung') : __('vom Theme gesperrt'))),
                Stat::make(__('IndexNow'), $indexNow ? __('an') : __('aus'))->tone($indexNow ? 'success' : 'neutral')
                    ->hint($indexNow ? ($lastSent !== null ? __('zuletzt :date', ['date' => $this->dates->dateTime((string) $lastSent)]) : __('noch nichts gemeldet')) : ($wanted ? $this->why($site) : __('abgeschaltet'))),
                Stat::make(__('Gemeldet (30 Tage)'), $sent30)->hint(__('Adressen an Bing und weitere')),
            ]);
        if (! $site->isLive()) {
            $page->notice(__('Die Site ist noch nicht live — Suchmaschinen sehen «Bald online» und keine Seiten. Live schalten unter Einrichtung › Veröffentlichen.'), 'warning');
        }

        $images = Media::query()->where('site_id', $site->id)->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp'])->orderByDesc('id')->limit(60)->get()
            ->map(static fn (Media $m): array => ['id' => $m->id, 'name' => $m->name, 'url' => $m->url('thumb')])->all();
        $page->form(Form::make(route('basalto.search_engines.update'), 'put')->title(__('Einstellungen'))->autosave()->fields([
            FormField::image('share_image', __('Vorschaubild beim Teilen'), $images, __('erzeugt'))
                ->help(__('Gilt für Seiten ohne eigenes Bild. Ohne Wahl erzeugt Basalto je Seite ein Bild mit Titel, Logo und Farben der Site. Am besten 1200 × 630 Pixel; hochladen in der Mediathek.')),
            FormField::text('google_verification', __('Google Search Console: Bestätigungscode'))->mono()->onLeave()->half()
                ->placeholder('4bF9…')->help(__('Den Code aus dem Meta-Tag «google-site-verification» — oder das ganze Tag einfügen.')),
            FormField::text('bing_verification', __('Bing Webmaster Tools: Bestätigungscode'))->mono()->onLeave()->half()
                ->placeholder('1A2B…')->help(__('Den Code aus dem Meta-Tag «msvalidate.01» — oder das ganze Tag einfügen.')),
            FormField::checkbox('indexnow', __('Änderungen über IndexNow melden'))
                ->help(__('Veröffentlichte, geänderte und offline genommene Seiten gehen an Bing, Yandex, Seznam, Naver und Yep — nur die Adressen, keine Personendaten. Google liest die Sitemap selbst.')),
        ])->values([
            'share_image' => is_numeric($site->setting('seo.share_image')) ? (int) $site->setting('seo.share_image') : null,
            'google_verification' => (string) ($site->setting('seo.google_verification') ?? ''),
            'bing_verification' => (string) ($site->setting('seo.bing_verification') ?? ''),
            'indexnow' => $wanted,
        ]));

        $rows = DB::table('indexnow_urls')->where('site_id', $site->id)->orderByDesc('id')->limit(50)->get();
        $page->table(Table::make('meldungen')->title(__('Meldungen an Suchmaschinen'))
            ->columns([Column::text('url', __('Adresse')), Column::date('date', __('Zeit')), Column::badge('status', __('Stand')), Column::number('response', __('Antwort'))])
            ->rows($rows, fn (object $row): Row => Row::make((int) $row->id)
                ->cell('url', (string) $row->url)
                ->cell('date', $this->dates->dateTime((string) ($row->sent_at ?? $row->created_at)))
                ->badge('status', match ((string) $row->status) {
                    IndexNow::SENT => __('Gemeldet'),
                    IndexNow::FAILED => __('Gescheitert'),
                    default => __('Wartet'),
                }, match ((string) $row->status) {
                    IndexNow::SENT => 'success',
                    IndexNow::FAILED => 'danger',
                    default => 'warning',
                })
                ->cell('response', $row->response === null ? '—' : (int) $row->response))
            ->empty(__('Noch keine Meldungen'), __('Sobald Sie eine Seite veröffentlichen, meldet Basalto die Adresse — höchstens alle zehn Minuten gesammelt.')));

        return $page;
    }

    public function update(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        $request->merge([
            'google_verification' => self::code($request->input('google_verification')),
            'bing_verification' => self::code($request->input('bing_verification')),
        ]);
        $validated = $request->validate([
            'share_image' => ['nullable', 'integer', Rule::exists('media', 'id')->where('site_id', $site->id)->whereNull('deleted_at')],
            'google_verification' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_\-]{8,120}$/'],
            'bing_verification' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_\-]{8,120}$/'],
            'indexnow' => ['boolean'],
        ], [
            'google_verification.regex' => __('Das sieht nicht nach einem Bestätigungscode aus. Fügen Sie den Wert aus content="…" ein.'),
            'bing_verification.regex' => __('Das sieht nicht nach einem Bestätigungscode aus. Fügen Sie den Wert aus content="…" ein.'),
        ], [
            'share_image' => __('Vorschaubild beim Teilen'), 'google_verification' => __('Google Search Console: Bestätigungscode'),
            'bing_verification' => __('Bing Webmaster Tools: Bestätigungscode'),
        ]);
        $before = $site->setting('seo', []);
        $settings = is_array($site->settings) ? $site->settings : [];
        foreach (['share_image', 'google_verification', 'bing_verification'] as $key) {
            if ($request->has($key)) {
                $value = $validated[$key] ?? null;
                data_set($settings, 'seo.'.$key, $value === '' ? null : $value);
            }
        }
        $site->settings = $settings;
        $site->save();
        if ($request->has('indexnow')) {
            $user = $request->user();
            $this->settings->set('seo.indexnow', $request->boolean('indexnow'), $site, $user instanceof Authenticatable ? $user : null);
        }
        $after = $site->setting('seo', []);
        unset($before['indexnow_key'], $after['indexnow_key']);
        $this->recorder->record('search_engines.updated', $site, is_array($before) ? $before : null, is_array($after) ? $after : null, $site);

        return back()->with('status', __('Gespeichert.'));
    }

    /** Den Code aus einem eingefügten Meta-Tag holen (`<meta name="…" content="CODE">`) oder den Wert selbst. */
    private static function code(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $value, $m) === 1) {
            $value = trim($m[1]);
        }

        return $value === '' ? null : $value;
    }

    /** Warum IndexNow trotz Einstellung nicht meldet. */
    private function why(Site $site): string
    {
        return match (true) {
            ! app()->isProduction() && ! (bool) config('basalto.indexnow.always', false) => __('nur in Produktion'),
            ! $site->isLive() => __('Site noch nicht live'),
            ! $this->publish->robotsAllowed($site) => __('Suchmaschinen gesperrt'),
            default => __('Adresse nicht öffentlich'),
        };
    }
}
