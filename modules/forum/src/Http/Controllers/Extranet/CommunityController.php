<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use App\Models\User;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityTerms;
use Basalto\Core\Community\ModerationSettings;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Reactions;
use Basalto\Core\Community\Reports;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Community › Übersicht (CR-006 § 6): Zahlen, die Einhängeseite (anlegen, wenn sie fehlt) und das eigene
 * Community-Konto des Teams (FO-K14) — verknüpft, erscheint man im Forum mit dem Abzeichen «Team».
 */
final class CommunityController
{
    public function __construct(private readonly CurrentSite $current, private readonly Profiles $profiles, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $site = self::site($this->current);
        $user = $request->user();
        assert($user instanceof User);
        $entry = app(Mounts::class)->entryFor($site, Forum::MOUNT);
        $mine = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('user_id', $user->id)->first();
        $base = app(Forum::class)->basePath($site, $site->default_locale);
        $front = ForumRoutes::front($site);
        $moderation = app(ModerationSettings::class)->all($site);

        return Inertia::render('forum::Index', [
            'counts' => [
                'nodes' => Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->count(),
                'threads' => Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count(),
                'posts' => Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count(),
                'members' => CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->count(),
                'today' => CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('last_activity_at', '>=', now()->startOfDay())->count(),
            ],
            'page' => $entry === null ? null : ['title' => $entry->resolve($site->default_locale)->title ?? '', 'url' => $base === null ? null : $front($base.'/'),
                'edit_url' => null],
            'team' => [
                'linked' => $mine === null ? null : ['name' => $mine->name, 'avatar' => $front($mine->avatarUrl()), 'profile_url' => $base === null ? null : $front($base.'/konto')],
                'email' => $user->email,
                'members' => CommunityProfile::query()->withoutGlobalScopes()->with('user')->where('site_id', $site->id)->whereNotNull('user_id')->orderBy('name')->get()
                    ->map(static fn (CommunityProfile $p): array => ['name' => $p->name, 'user' => (string) $p->user?->name])->values()->all(),
            ],
            'settings' => [
                'edit_minutes' => app(Forum::class)->editMinutes($site),
                'max_mb' => intdiv(app(Attachments::class)->maxBytes($site), 1048576),
                'quota_mb' => intdiv(app(Attachments::class)->quotaBytes($site), 1048576),
                'types' => implode(', ', app(Attachments::class)->types($site)),
                // TB 52: Anhänge je Beitrag, angebotene Reaktionen
                'max_files' => app(Attachments::class)->maxFiles($site),
                'reactions' => array_keys(Reactions::enabled($site)),
                // TB 39 (FO-17): Flood-Control, Verweis nach dem Verschieben, Meldungen, Verwarnungen, Zensur
                'redirect_days' => max(0, (int) app(Settings::class)->get('forum.redirect_days', 7, $site)),
                ...$moderation,
                'censor' => implode("\n", $moderation['censor']),
                'privacy_posts' => (string) app(Settings::class)->get('forum.privacy_posts', 'anonymisieren', $site),
                // CR-011 TB 60: Zustimmung zu den Bedingungen
                'terms_required' => app(CommunityTerms::class)->required($site),
                'terms_version' => app(CommunityTerms::class)->version($site),
            ],
            'reaction_options' => array_map(static fn (string $type, string $symbol): array => ['key' => $type, 'symbol' => $symbol, 'label' => Reactions::label($type)], array_keys(Reactions::TYPES), Reactions::TYPES),
            'moderation' => ['open' => app(Reports::class)->pendingCount($site->id, [Moderation::REPORT_TYPE]),
                'pending' => Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', true)->count()],
            'urls' => ['settings' => ForumRoutes::url('settings'), 'nodes' => ForumRoutes::url('nodes'), 'groups' => ForumRoutes::url('groups'), 'link' => ForumRoutes::url('team.link'),
                'unlink' => ForumRoutes::url('team.unlink'), 'create_page' => ForumRoutes::url('page'),
                'moderation' => ForumRoutes::url('moderation'), 'members' => ForumRoutes::url('members'), 'notices' => ForumRoutes::url('notices'),
                'recognition' => ForumRoutes::url('recognition')],
        ]);
    }

    public function link(Request $request): RedirectResponse
    {
        $site = self::site($this->current);
        $user = $request->user();
        assert($user instanceof User);
        $profile = $this->profiles->linkTeam($user, $site);
        $this->recorder->record('forum.team_linked', null, null, ['profil' => $profile->name], $site);

        return back()->with('status', __('Ihr Community-Konto «:name» trägt jetzt das Abzeichen «Team».', ['name' => $profile->name]));
    }

    public function unlink(Request $request): RedirectResponse
    {
        $site = self::site($this->current);
        $user = $request->user();
        assert($user instanceof User);
        $this->profiles->unlinkTeam($user, $site);
        $this->recorder->record('forum.team_unlinked', null, null, null, $site);

        return back()->with('status', __('Verknüpfung gelöst — Ihr Konto schreibt wieder ohne Abzeichen.'));
    }

    /**
     * Das Forum unter `/community` einrichten — der schnelle Weg, ohne im Seitenbaum das Feld «Modul einhängen» zu
     * suchen. Gibt es die Seite schon (etwa mit Karten zu GitHub), hängt sich das Forum an sie: Einleitung und Blöcke
     * der Redaktion bleiben oben stehen. Sonst entsteht sie auf der obersten Ebene, veröffentlicht.
     */
    public function createPage(Request $request): RedirectResponse
    {
        $site = self::site($this->current);
        if (app(Mounts::class)->entryFor($site, Forum::MOUNT) !== null) {
            return back();
        }
        $pages = app(Pages::class);
        $existing = $pages->entryIdAt($site, $site->default_locale, '/community');
        $entry = $existing === null ? null : Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($existing);
        if ($entry !== null) {
            $settings = is_array($entry->settings) ? $entry->settings : [];
            $settings['expert'] = [...(is_array($settings['expert'] ?? null) ? $settings['expert'] : []), 'mount' => Forum::MOUNT];
            $entry->forceFill(['settings' => $settings])->save();
            $pages->forget();
            app(PageCache::class)->invalidate($site->id);
            $this->recorder->record('forum.page_mounted', $entry, null, ['pfad' => '/community'], $site);

            return back()->with('status', __('Das Forum hängt jetzt an der bestehenden Seite unter /community — ihr Inhalt bleibt oben stehen.'));
        }
        $entry = new Entry(['site_id' => $site->id, 'type' => PageType::Page->value, 'parent_id' => null]);
        $entry->settings = ['expert' => ['mount' => Forum::MOUNT, 'in_sitemap' => true, 'in_search' => true, 'hide_in_menu' => false, 'css_class' => '']];
        $entry->save();
        $author = $request->user()?->getAuthIdentifier();
        foreach ($site->localeCodes() as $locale) {
            // «community», solange frei — freeSlug() hängt immer eine Nummer an
            $slug = $pages->entryIdAt($site, $locale, '/community', false) === null ? 'community' : $pages->freeSlug($site, null, $locale, 'community');
            $version = $entry->newDraft($locale, ['title' => 'Community', 'slug' => $slug], is_int($author) ? $author : null);
            // Vom Team im Extranet angelegt: ohne Vier-Augen-Runde freigegeben und veröffentlicht (wie ein Seed)
            $version->forceFill(['status' => EntryStatus::Approved])->save();
            $version->publish();
        }
        $pages->forget();
        $this->recorder->record('forum.page_created', $entry, null, ['pfad' => '/community'], $site);

        return back()->with('status', __('Die Seite «Community» ist angelegt — das Forum steht unter /community.'));
    }

    /** Einstellungen: Bearbeitungsfenster, Anhänge (Grösse, Kontingent, Arten) und seit TB 39 die Moderation. */
    public function settings(Request $request): RedirectResponse
    {
        $site = self::site($this->current);
        $data = $request->validate([
            'edit_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'max_mb' => ['required', 'integer', 'min:1', 'max:100'],
            'quota_mb' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_files' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'reactions' => ['sometimes', 'array', 'min:1'],
            'reactions.*' => ['string', 'in:'.implode(',', array_keys(Reactions::TYPES))],
            'types' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9, ]+$/'],
            'redirect_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'flood_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'auto_hide' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'warning_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'mute_points' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'mute_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'ban_points' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'ban_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'censor' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'privacy_posts' => ['sometimes', 'in:anonymisieren,loeschen'],
            'terms_required' => ['sometimes', 'boolean'],
            'terms_version' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], [], ['edit_minutes' => __('Bearbeiten erlaubt'), 'max_mb' => __('Grösse je Datei'), 'max_files' => __('Anhänge je Beitrag'), 'reactions' => __('Reaktionen'), 'quota_mb' => __('Speicher je Konto'), 'types' => __('Erlaubte Dateiarten'),
            'redirect_days' => __('Verweis nach dem Verschieben'), 'flood_seconds' => __('Mindestabstand zwischen Beiträgen'), 'auto_hide' => __('Ausblenden ab Meldungen'),
            'warning_days' => __('Verwarnungen gelten'), 'mute_points' => __('Schreibsperre ab Punkten'), 'mute_days' => __('Dauer der Schreibsperre'),
            'ban_points' => __('Sperre ab Punkten'), 'ban_days' => __('Dauer der Sperre'), 'censor' => __('Zensur'), 'terms_version' => __('Fassung')]);
        $types = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string) $data['types']))))));
        $settings = app(Settings::class);
        $settings->set('forum.edit_minutes', (int) $data['edit_minutes'], $site);
        $settings->set('community.attachments.max_mb', (int) $data['max_mb'], $site);
        $settings->set('community.attachments.quota_mb', (int) $data['quota_mb'], $site);
        $settings->set('community.attachments.types', $types, $site);
        if (array_key_exists('max_files', $data)) {
            $settings->set('community.attachments.max_files', (int) $data['max_files'], $site);
        }
        if (array_key_exists('reactions', $data)) {
            $settings->set('community.reactions', array_values(array_intersect(array_keys(Reactions::TYPES), array_map('strval', (array) $data['reactions']))), $site);
        }
        if (array_key_exists('redirect_days', $data)) {
            $settings->set('forum.redirect_days', (int) $data['redirect_days'], $site);
        }
        if (array_key_exists('privacy_posts', $data)) {
            $settings->set('forum.privacy_posts', (string) $data['privacy_posts'], $site);
        }
        if (array_key_exists('terms_required', $data)) {
            $settings->set(CommunityTerms::REQUIRED, (bool) $data['terms_required'], $site);
        }
        if (array_key_exists('terms_version', $data)) {
            $settings->set(CommunityTerms::VERSION, (string) $data['terms_version'], $site);
        }
        if (array_key_exists('censor', $data)) {
            $data['censor'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $data['censor']) ?: []))));
        }
        app(ModerationSettings::class)->save($site, $data);
        $this->recorder->record('forum.settings_updated', null, null, $data, $site);

        return back()->with('status', __('Einstellungen gespeichert.'));
    }

    public static function site(CurrentSite $current): Site
    {
        $site = $current->require();
        abort_unless(app(Backend::class)->moduleEnabled('forum', $site), 404);

        return $site;
    }

    /** @return list<array{id: int, email: string, name: string}> Kundenkonten zur Auswahl (Suche nach E-Mail oder Name) */
    public static function customers(Site $site, string $query): array
    {
        $found = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)
            ->when($query !== '', static fn ($q) => $q->where(static fn ($w) => $w->where('email', 'like', '%'.$query.'%')->orWhere('name', 'like', '%'.$query.'%')))
            ->orderBy('email')->limit(10)->get();

        return array_values($found->map(static fn (Customer $c): array => ['id' => $c->id, 'email' => $c->email, 'name' => (string) $c->name])->all());
    }
}
