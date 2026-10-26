<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Testdata;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\CommunityGroupRule;
use Basalto\Core\Community\CommunityNotice;
use Basalto\Core\Community\CommunityRank;
use Basalto\Core\Community\CommunityReport;
use Basalto\Core\Community\CommunityTrophy;
use Basalto\Core\Community\Moderator;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Community\Recognition;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\Community\Warnings;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Testdata\TestdataImages;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Core\Testdata\TestdataText;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Console\ForumSeedCommand;
use Basalto\Modules\Forum\Counters;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Frontend\ForumMount;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\NodeType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Testdaten des Forums: die Startstruktur wie bei WoltLab plus Zusatzforen (Archiv mit Unterforen, Testforum, Team
 * intern, ein leeres Forum, tiefe Verschachtelung, ein Verweis) und darin viele Themen aller Arten — mit Präfixen,
 * Themenfeldern, Tags, Umfragen, Reaktionen, hilfreichsten und offiziellen Antworten, Bildern, Bearbeitungen,
 * Freischaltung, Papierkorb, Verschiebe-Hinweisen, Lesezeichen und Abos. Dazu Moderation (Meldungen, Verwarnungen,
 * Sperren), Ränge, Trophäen, eine Gruppe mit Regel und Hinweise. Was nicht an Kundenkonten der Testdaten hängt,
 * trägt die Marke `testdaten` in den Einstellungen bzw. Bedingungen.
 */
final class ForumTestdata
{
    public const KEY = 'forum';

    public const MARK = 'testdaten';

    /** Eigene Gruppe der Testdaten mit Regel (ab 20 Beiträgen). */
    public const GROUP = 'Erfahrene Mitglieder';

    /** @var array<string, list<mixed>> Zusatzforen der Testdaten unter Knoten der Startstruktur (Kurzname des Elternknotens → Knoten) */
    private const EXTRA = [
        'kundenforen' => [['archiv', ['de' => 'Ältere Versionen (Archiv)', 'en' => 'Older versions (archive)', 'fr' => 'Anciennes versions (archives)', 'it' => 'Versioni precedenti (archivio)'], ['de' => 'Themen zu Versionen vor 1.2 — nur noch zum Nachlesen.', 'en' => 'Topics about versions before 1.2 — read only.', 'fr' => 'Sujets sur les versions antérieures à 1.2 — en lecture seule.', 'it' => 'Discussioni sulle versioni precedenti alla 1.2 — sola lettura.'], NodeType::Board, ['icon' => 'book', 'closed' => true], [
            ['archiv-1-0', ['de' => 'Version 1.0', 'en' => 'Version 1.0', 'fr' => 'Version 1.0', 'it' => 'Versione 1.0'], ['de' => 'Erste Version, Oktober 2026.'], NodeType::Board, ['icon' => 'lock', 'closed' => true], []],
            ['archiv-1-1', ['de' => 'Version 1.1', 'en' => 'Version 1.1', 'fr' => 'Version 1.1', 'it' => 'Versione 1.1'], ['de' => 'SaaS, Marktplatz und Mitglieder.'], NodeType::Board, ['icon' => 'lock', 'closed' => true], []],
        ]]],
        'module-und-plugins' => [['schnittstellen', ['de' => 'Schnittstellen (API)', 'en' => 'Interfaces (API)', 'fr' => 'Interfaces (API)', 'it' => 'Interfacce (API)'], ['de' => 'REST-API, Webhooks und Anbindungen.'], NodeType::Board, ['icon' => 'link'], [
            ['webhooks', ['de' => 'Webhooks', 'en' => 'Webhooks', 'fr' => 'Webhooks', 'it' => 'Webhook'], ['de' => 'Dritte Ebene — für die Verschachtelung.'], NodeType::Board, ['icon' => 'share'], []],
        ]]],
        'anpassung' => [['marktplatz-verweis', ['de' => 'Marktplatz', 'en' => 'Marketplace', 'fr' => 'Marketplace', 'it' => 'Marketplace'], ['de' => 'Themes und Plugins finden — ein Verweis.'], NodeType::Link, ['icon' => 'star', 'link' => '/marktplatz'], []]],
        'sonstiges' => [
            ['testforum', ['de' => 'Testforum', 'en' => 'Test forum', 'fr' => 'Forum de test', 'it' => 'Forum di prova'], ['de' => 'Hier dürfen Sie alles ausprobieren — auch Unsinn.', 'en' => 'Try anything here.', 'fr' => 'Essayez tout ce que vous voulez ici.', 'it' => 'Qui può provare tutto.'], NodeType::Board, ['icon' => 'hash'], []],
            ['team-intern', ['de' => 'Team intern', 'en' => 'Team only', 'fr' => 'Équipe uniquement', 'it' => 'Solo team'], ['de' => 'Nur für Team und Moderation sichtbar.'], NodeType::Board, ['icon' => 'lock', 'hidden' => true], []],
            ['leeres-forum', ['de' => 'Neu und noch leer', 'en' => 'New and empty', 'fr' => 'Nouveau et encore vide', 'it' => 'Nuovo e ancora vuoto'], ['de' => 'Ein Forum ohne Themen — für den leeren Zustand.'], NodeType::Board, ['icon' => 'folder'], []],
        ],
    ];

    /** Anteil der Themen je Forum (Gewicht). */
    private const WEIGHTS = ['ankundigungen' => 4, 'fragen-vor-dem-kauf' => 6, 'hilfe-und-support' => 30, 'ideen' => 10, 'umgesetzte-vorschlaege' => 3, 'fehlermeldungen' => 12,
        'erledigte-fehlermeldungen' => 4, 'themes-und-module' => 8, 'module-und-plugins' => 6, 'schnittstellen' => 2, 'webhooks' => 1, 'off-topic' => 6, 'testforum' => 3,
        'team-intern' => 2, 'archiv-1-0' => 2, 'archiv-1-1' => 2];

    private const FEATURES = ['die Mehrsprachigkeit', 'den Seiten-Cache', 'die Suche', 'Formulare mit Anhang', 'den Medien-Upload', 'Umleitungen', 'den Mail-Versand', 'Benutzerrollen',
        'die Sitemap', 'den Lava-Editor', 'die Navigation', 'den Blog', 'Kommentare', 'die API mit Sanctum', 'Backups', 'den Web-Installer', 'Passkeys', 'SSO mit Microsoft', 'den Export'];

    private const SYMPTOMS = ['Fehler 500', 'Weisse Seite', 'Fehler 413', 'Endlosschleife beim Anmelden', 'Umlaute falsch dargestellt', 'Bilder fehlen im Frontend', 'Zeitüberschreitung',
        'Doppelte Einträge', 'Leere Vorschau', 'Speichern hängt'];

    private const VERSIONS = ['1.0.3', '1.1.0', '1.1.2', '1.2.0', '1.2.1', '1.2.2'];

    private const IDEAS = ['Termine als iCal exportieren', 'Blöcke zwischen Seiten kopieren', 'Medien-Ordner per Drag & Drop', 'Dunkelmodus im Frontend-Editor', 'Mehr Feldarten im Formular-Baukasten',
        'Besucherstatistik im Cockpit', 'Markdown-Import', 'Block für Öffnungszeiten', 'Newsletter-Anbindung', 'Versionen mit Bildern vergleichen', 'Bewertungen in Kommentaren', 'Mehrere Logos je Sprache'];

    private const TAGS = ['lava', 'theme', 'update', 'mehrsprachigkeit', 'formulare', 'seo', 'performance', 'docker', 'mariadb', 'postgresql', 'medien', 'editor', 'api', 'plugin',
        'dunkelmodus', 'barrierefreiheit', 'übersetzung', 'hosting'];

    private const RANKS = [['Neuling', 0, 'grey'], ['Mitglied', 10, 'blue'], ['Stammgast', 50, 'green'], ['Expertin/Experte', 200, 'lava']];

    /** @var list<array{0: string, 1: string, 2: string, 3: string|null, 4: int}> */
    private const TROPHIES = [['Erster Beitrag', 'Hat zum ersten Mal geschrieben.', '✍️', 'posts', 1], ['Zehn Beiträge', 'Schreibt regelmässig mit.', '🔟', 'posts', 10],
        ['Hilfsbereit', 'Eine Antwort wurde als hilfreichste gewählt.', '💡', 'forum_best_answers', 1], ['Beliebt', 'Zehn Reaktionen erhalten.', '❤️', 'reactions', 10],
        ['Ein Jahr dabei', 'Seit einem Jahr in der Community.', '🎂', 'member_days', 365], ['Beta-Testerin/Beta-Tester', 'Hat vor 1.0 mitgetestet (von Hand vergeben).', '🧪', null, 0]];

    /** @var array<int, int> Beitrag → Kundenkonto (für Reaktionen und Meldungen) */
    private array $posts = [];

    /** @var list<int> */
    private array $threads = [];

    /** Globale Ankündigungen erscheinen in jedem Forum — zwei reichen. */
    private int $announcements = 0;

    /** Umfragen bisher — das erste Thema in «Ideen» bekommt sicher eine (sonst entschiede der Zufall allein). */
    private int $polls = 0;

    public function __construct(private readonly VisitorAccess $access, private readonly VisitorGroups $groups, private readonly Counters $counters, private readonly Reports $reports,
        private readonly Warnings $warnings, private readonly Sanctions $sanctions, private readonly Recognition $recognition, private readonly Attachments $attachments) {}

    public function seed(TestdataRun $run): void
    {
        $site = $run->site;
        $made = [];
        ForumSeedCommand::build($site, ForumSeedCommand::STRUCTURE, $this->access, $this->groups, [], null, $made);
        foreach (self::EXTRA as $parent => $children) {
            if (isset($made[$parent])) {
                ForumSeedCommand::build($site, $children, $this->access, $this->groups, [self::MARK => true], $made[$parent], $made);
            }
        }
        app(Forum::class)->forget();
        $this->page($run);
        $members = $run->pool('aktiv');
        $team = $run->pool('team');
        if ($members === []) {
            return;
        }
        $this->moderators($run);
        $this->recognitionSetup($run);
        $this->notices($run);
        $tags = $this->tags($run);
        $prefixes = ForumSeedCommand::prefixes($site);
        $boards = array_filter($made, static fn (Node $n): bool => $n->type === NodeType::Board && array_key_exists($n->slug, self::WEIGHTS));
        $total = $run->times(45);
        $weights = array_intersect_key(self::WEIGHTS, $boards);
        for ($t = 0; $t < $total; $t++) {
            $board = $boards[$t < count($weights) ? array_keys($weights)[$t] : $run->weighted($weights)];
            $this->thread($run, $board, $members, $team, $prefixes, $tags, $t === array_search('hilfe-und-support', array_keys($weights), true));
        }
        $this->moved($run, $boards);
        $this->reactions($run, $members);
        $this->moderation($run, $members, $team);
        $this->reads($run, $members, $boards);
        $this->counters->all($site);
        $this->recognition->run($site);
        app(PageCache::class)->invalidate($site->id);
    }

    public function remove(Site $site): void
    {
        $customers = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', 'like', '%@'.TestdataRun::DOMAIN)->pluck('id')->all();
        $threads = DB::table('forum_threads')->where('site_id', $site->id)->whereIn('customer_id', $customers)->pluck('id')->all();
        $posts = DB::table('forum_posts')->where('site_id', $site->id)->where(static fn ($q) => $q->whereIn('customer_id', $customers)->orWhereIn('thread_id', $threads))->pluck('id')->all();
        foreach (DB::table('community_attachments')->where('site_id', $site->id)->whereIn('customer_id', $customers)->pluck('path') as $path) {
            Storage::disk()->delete((string) $path);
        }
        DB::table('community_attachments')->where('site_id', $site->id)->whereIn('customer_id', $customers)->delete();
        foreach (array_chunk($posts, 500) as $chunk) {
            DB::table('community_reactions')->where('subject_type', Forum::ATTACHMENTS)->whereIn('subject_id', $chunk)->delete();
            DB::table('community_reports')->where('subject_type', Moderation::REPORT_TYPE)->whereIn('subject_id', $chunk)->delete();
        }
        DB::table('forum_threads')->where('site_id', $site->id)->whereIn('moved_to_id', $threads)->delete();
        foreach (array_chunk($threads, 500) as $chunk) {
            DB::table('search_documents')->where('source', 'forum')->whereIn('source_id', $chunk)->delete();
            DB::table('forum_threads')->whereIn('id', $chunk)->delete();
        }
        foreach (array_chunk($posts, 500) as $chunk) {
            DB::table('forum_posts')->whereIn('id', $chunk)->delete();
        }
        DB::table('community_moderation_log')->where('site_id', $site->id)->where(static fn ($q) => $q->whereIn('target_customer_id', $customers)->orWhereIn('customer_id', $customers))->delete();
        $nodes = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->get()->filter(static fn (Node $n): bool => (bool) $n->setting(self::MARK, false));
        Node::query()->withoutGlobalScopes()->whereIn('id', $nodes->pluck('id'))->delete();
        DB::table('forum_tags')->where('site_id', $site->id)->whereNotIn('id', DB::table('forum_thread_tag')->select('tag_id'))->delete();
        CommunityNotice::query()->withoutGlobalScopes()->where('site_id', $site->id)->get()->filter(static fn (CommunityNotice $n): bool => array_key_exists(self::MARK, $n->conditions ?? []))->each->delete();
        CommunityRank::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('title', array_column(self::RANKS, 0))->delete();
        CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('title', array_column(self::TROPHIES, 0))->delete();
        $group = VisitorGroup::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('key')->where('name', self::GROUP)->first();
        if ($group !== null) {
            CommunityGroupRule::query()->withoutGlobalScopes()->where('visitor_group_id', $group->id)->delete();
            $group->delete();
        }
        foreach (Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->get() as $entry) {
            $settings = is_array($entry->settings) ? $entry->settings : [];
            if ((bool) ($settings[self::MARK] ?? false)) {
                $entry->delete();
            } elseif ((bool) ($settings[self::MARK.'_mount'] ?? false)) {
                unset($settings[self::MARK.'_mount'], $settings['expert']['mount']);
                $entry->forceFill(['settings' => $settings])->save();
            }
        }
        app(Pages::class)->forget();
        app(Forum::class)->forget();
        app(Counters::class)->all($site);
    }

    /** Die Seite der Community, wenn es noch keine gibt — mit fester UUID (Prüfsumme der Demo). */
    private function page(TestdataRun $run): void
    {
        $site = $run->site;
        if (app(Mounts::class)->entryFor($site, Forum::MOUNT) !== null) {
            return;
        }
        $pages = app(Pages::class);
        // Gibt es /community schon (etwa im Starter-Theme), hängt sich das Forum daran — wie «Forum unter /community einrichten»
        $existing = $pages->entryIdAt($site, $site->default_locale, '/community');
        $entry = $existing === null ? null : Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($existing);
        if ($entry !== null) {
            $settings = is_array($entry->settings) ? $entry->settings : [];
            $settings['expert'] = [...(is_array($settings['expert'] ?? null) ? $settings['expert'] : []), 'mount' => Forum::MOUNT];
            $entry->forceFill(['settings' => $settings + [self::MARK.'_mount' => true]])->save();
            $pages->forget();
            $run->count('Seite der Community');

            return;
        }
        $entry = new Entry(['site_id' => $site->id, 'type' => PageType::Page->value, 'parent_id' => null, 'uuid' => $run->uuid('forum-page')]);
        $entry->settings = ['expert' => ['mount' => Forum::MOUNT, 'in_sitemap' => true, 'in_search' => true, 'hide_in_menu' => false, 'css_class' => ''], self::MARK => true];
        $entry->save();
        foreach ($site->localeCodes() as $locale) {
            // Die Adresse kann über den Rückfall der Sprache schon auf diese Seite zeigen (Englisch → Deutsch) — dann ist sie frei
            $owner = $pages->entryIdAt($site, $locale, '/community', false);
            $slug = $owner === null || $owner === $entry->id ? 'community' : $pages->freeSlug($site, null, $locale, 'community');
            $version = $entry->newDraft($locale, ['title' => 'Community', 'slug' => $slug], null);
            $version->forceFill(['status' => EntryStatus::Approved])->save();
            $version->publish();
        }
        $pages->forget();
        $run->count('Seite der Community');
    }

    private function moderators(TestdataRun $run): void
    {
        $group = $this->groups->byKey($run->site, VisitorGroup::MODERATORS);
        foreach ($run->pool('moderation') as $customer) {
            DB::table('visitor_group_customer')->updateOrInsert(['visitor_group_id' => $group->id, 'customer_id' => $customer->id], ['created_at' => now()]);
            $run->count('Moderation aus der Community');
        }
        $this->groups->forget();
    }

    private function recognitionSetup(TestdataRun $run): void
    {
        $site = $run->site;
        if (! CommunityRank::query()->withoutGlobalScopes()->where('site_id', $site->id)->exists()) {
            foreach (self::RANKS as [$title, $min, $color]) {
                CommunityRank::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'title' => $title, 'min_posts' => $min, 'color' => $color]);
                $run->count('Ränge');
            }
        }
        if (! CommunityTrophy::query()->withoutGlobalScopes()->where('site_id', $site->id)->exists()) {
            foreach (self::TROPHIES as $i => [$title, $description, $icon, $rule, $threshold]) {
                $trophy = CommunityTrophy::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'title' => $title, 'description' => $description, 'icon' => $icon, 'rule' => $rule,
                    'threshold' => $threshold, 'is_active' => true, 'position' => $i]);
                $run->count('Trophäen');
                if ($rule === null) {
                    foreach (array_slice($run->pool('aktiv'), 0, 3) as $customer) {
                        $this->recognition->award($site, $trophy, $customer->id);
                    }
                }
            }
        }
        $group = VisitorGroup::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('key')->where('name', self::GROUP)->first()
            ?? VisitorGroup::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'key' => null, 'name' => self::GROUP, 'badge' => 'Erfahren', 'position' => 10]);
        CommunityGroupRule::query()->withoutGlobalScopes()->firstOrCreate(['site_id' => $site->id, 'visitor_group_id' => $group->id, 'rule' => 'posts'], ['threshold' => 20, 'is_active' => true]);
        $this->groups->forget();
    }

    private function notices(TestdataRun $run): void
    {
        $site = $run->site;
        $mark = [self::MARK => true];
        $notices = [
            [['de' => 'Zum Schreiben bitte anmelden — das geht mit einem Code per Mail.', 'en' => 'Please sign in to write — it works with a code by email.', 'fr' => 'Pour écrire, veuillez vous connecter — avec un code par e-mail.', 'it' => 'Per scrivere, effettui l’accesso — con un codice via e-mail.'], 'warnung', ['area' => '*', 'audience' => 'guests', 'groups' => []], false, true, null, null],
            [['de' => 'Neu im Forum: Antworten erscheinen jetzt live, ohne Neuladen.', 'en' => 'New in the forum: replies now appear live, without reloading.', 'fr' => 'Nouveau dans le forum : les réponses apparaissent en direct, sans recharger.', 'it' => 'Novità nel forum: le risposte compaiono ora in diretta, senza ricaricare.'], 'erfolg', ['area' => '*', 'audience' => 'members', 'groups' => []], true, true, null, $run->now()->addDays(14)],
            [['de' => 'Abgelaufener Hinweis — darf nirgends erscheinen.'], 'info', ['area' => '*', 'audience' => 'all', 'groups' => []], false, true, $run->now()->subDays(20), $run->now()->subDays(2)],
            [['de' => 'Ausgeschalteter Hinweis — darf nirgends erscheinen.'], 'warnung', ['area' => '*', 'audience' => 'all', 'groups' => []], false, false, null, null],
        ];
        foreach ($notices as $i => [$text, $tone, $conditions, $dismissible, $active, $starts, $ends]) {
            CommunityNotice::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'text' => array_intersect_key($text, array_flip($site->localeCodes())) ?: ['de' => $text['de']], 'tone' => $tone,
                'conditions' => $conditions + $mark, 'dismissible' => $dismissible, 'is_active' => $active, 'position' => 100 + $i, 'starts_at' => $starts, 'ends_at' => $ends]);
            $run->count('Hinweise');
        }
    }

    /** @return array<string, int> Name → ID */
    private function tags(TestdataRun $run): array
    {
        $out = [];
        foreach (self::TAGS as $name) {
            $slug = Str::slug($name, '-', 'de');
            $id = DB::table('forum_tags')->where('site_id', $run->site->id)->where('slug', $slug)->value('id');
            $out[$name] = (int) ($id ?? DB::table('forum_tags')->insertGetId(['site_id' => $run->site->id, 'name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]));
        }

        return $out;
    }

    /**
     * @param  list<Customer>  $members
     * @param  list<Customer>  $team
     * @param  array<string, int>  $prefixes
     * @param  array<string, int>  $tags
     */
    private function thread(TestdataRun $run, Node $board, array $members, array $team, array $prefixes, array $tags, bool $mega): void
    {
        $site = $run->site;
        $locales = $site->localeCodes();
        $locale = count($locales) > 1 && $run->chance(15) ? $run->pick(array_values(array_diff($locales, [$site->default_locale]))) : $site->default_locale;
        $slug = $board->slug;
        $teamOnly = in_array($slug, ['ankundigungen', 'team-intern'], true);
        $author = $teamOnly && $team !== [] ? $run->pick($team) : $this->author($run, $members);
        [$title, $prefix] = $this->title($run, $slug, $locale);
        $title = $mega ? 'Sammelthema: Erfahrungen mit dem Update auf 1.2 (mit sehr vielen Antworten)' : $title;
        $allowed = array_map('intval', (array) $board->setting('prefixes', []));
        $prefixId = $prefix !== null && in_array($prefixes[$prefix] ?? 0, $allowed, true) ? $prefixes[$prefix] : null;
        $created = $mega ? $run->ago(200, 150) : (str_starts_with($slug, 'archiv') ? $run->ago(400, 200) : $run->ago(300, 0));
        $values = [];
        foreach ((array) $board->setting('fields', []) as $field) {
            $values[(string) $field['key']] = match ((string) $field['key']) {
                'version' => $run->pick(self::VERSIONS),
                default => $run->pick((array) ($field['options'] ?? ['—'])),
            };
        }
        $replies = $mega ? ForumMount::POSTS_PER_PAGE * 3 + 2 : (int) $run->weighted(['0' => 15, (string) $run->int(1, 5) => 50, (string) $run->int(6, 20) => 30, (string) $run->int(21, 45) => 5]);
        // Das grosse Thema bleibt immer sichtbar (es prüft das Blättern)
        $disabled = ! $teamOnly && ! $mega && $run->chance(3);
        $trashed = ! $disabled && ! $mega && $run->chance(2);
        $threadId = (int) DB::table('forum_threads')->insertGetId(['site_id' => $site->id, 'node_id' => $board->id, 'customer_id' => $author->id,
            'title' => $title, 'slug' => Str::limit(Str::slug($title, '-', 'de') ?: 'thema', 100, ''),
            'locale' => $locale, 'prefix_id' => $prefixId, 'form_values' => $values === [] ? null : json_encode($values, JSON_UNESCAPED_UNICODE),
            'is_sticky' => $mega || ($slug === 'ankundigungen' && $run->chance(40)) || $run->chance(3), 'is_closed' => (bool) $board->setting('closed', false) || $run->chance(5),
            'is_disabled' => $disabled, 'is_announcement' => $slug === 'ankundigungen' && $this->announcements++ < 2, 'is_featured' => $run->chance(4),
            'views' => $run->int($replies * 3, $replies * 40 + 20), 'created_at' => $created, 'updated_at' => $created,
            'deleted_at' => $trashed ? $run->after($created, 48) : null, 'trash_reason' => $trashed ? 'Doppelt eröffnet' : null]);
        $this->threads[] = $threadId;
        $run->count('Themen');
        foreach ($run->picks($tags, $run->weighted(['0' => 30, '1' => 35, '2' => 25, '3' => 10]) === '0' ? 0 : $run->int(1, 3)) as $tagId) {
            DB::table('forum_thread_tag')->insertOrIgnore(['thread_id' => $threadId, 'tag_id' => $tagId]);
        }
        $first = $this->post($run, $threadId, $author, $created, $this->firstBody($run, $slug, $locale), false, false);
        $at = $created;
        $best = null;
        $people = [$author];
        for ($r = 0; $r < $replies; $r++) {
            $fromTeam = $team !== [] && ! $teamOnly && $run->chance(in_array($slug, ['hilfe-und-support', 'fehlermeldungen', 'fragen-vor-dem-kauf'], true) ? 18 : 6);
            $by = $teamOnly && $team !== [] ? $run->pick($team) : ($fromTeam ? $run->pick($team) : ($run->chance(25) ? $run->pick($people) : $this->author($run, $members)));
            $people[] = $by;
            $at = $run->after($at, $mega ? 30 : 60);
            $postId = $this->post($run, $threadId, $by, $at, $this->replyBody($run, $locale, $r, $by === $author), $fromTeam && $run->chance(50), ! $disabled && ! $mega && $run->chance(3));
            if ($best === null && $by !== $author && $prefix === 'Gelöst' && $r > 0 && $run->chance(45)) {
                $best = $postId;
            }
        }
        // Kennzahlen gleich setzen — auch für Themen im Papierkorb, die der Zähler auslässt
        $last = DB::table('forum_posts')->where('thread_id', $threadId)->orderByDesc('id')->first();
        DB::table('forum_threads')->where('id', $threadId)->update(['first_post_id' => $first, 'last_post_id' => $last?->id, 'last_customer_id' => $last?->customer_id,
            'last_posted_at' => $last?->created_at, 'replies' => $replies]);
        if ($best !== null) {
            DB::table('forum_threads')->where('id', $threadId)->update(['best_post_id' => $best, 'is_done' => true]);
        } elseif (in_array($prefix, ['Behoben', 'Umgesetzt'], true)) {
            DB::table('forum_threads')->where('id', $threadId)->update(['is_done' => true]);
        }
        if (in_array($slug, ['ideen', 'off-topic', 'testforum', 'fragen-vor-dem-kauf'], true) && (($slug === 'ideen' && $this->polls === 0) || $run->chance($slug === 'ideen' ? 30 : 10))) {
            $this->poll($run, $threadId, $created, $members);
        }
        if ($run->chance(8)) {
            $this->revisions($run, $first, $author, $created);
        }
    }

    /** @param  list<Customer>  $members */
    private function author(TestdataRun $run, array $members): Customer
    {
        // Ein paar Vielschreiber: das erste Fünftel schreibt viel öfter
        $heavy = array_slice($members, 0, max(1, intdiv(count($members), 5)));

        return $run->chance(45) ? $run->pick($heavy) : $run->pick($members);
    }

    /** @return array{0: string, 1: string|null} Titel und Präfix (Bezeichnung) */
    private function title(TestdataRun $run, string $slug, string $locale): array
    {
        if ($locale !== 'de') {
            return [match ($locale) {
                'en' => $run->pick(['Question about the page cache', 'Upgrade to 1.2 fails on shared hosting', 'How do I translate the navigation?', 'Dark mode flickers in Safari']),
                'fr' => $run->pick(['Question sur le cache des pages', 'Mise à jour vers 1.2 impossible', 'Comment traduire la navigation ?']),
                'it' => $run->pick(['Domanda sulla cache delle pagine', 'Aggiornamento alla 1.2 non riesce', 'Come traduco la navigazione?']),
                default => 'Question',
            }, $run->pick(['Frage', null])];
        }
        $feature = $run->pick(self::FEATURES);
        $symptom = $run->pick(self::SYMPTOMS);
        $version = $run->pick(self::VERSIONS);

        return match ($slug) {
            'ankundigungen' => [$run->pick(['Basalto '.$version.' ist erschienen', 'Wartungsfenster am Sonntagmorgen', 'Neu im Marktplatz: fünf Themes für Vereine', 'Sicherheits-Update '.$version.' — bitte einspielen',
                'Rückblick auf die Basalto-Tagung 2026', 'Neue Doku zu Lava und Design-Tokens']), null],
            'fragen-vor-dem-kauf' => [$run->pick(['Brauche ich für drei Sites eine Agentur-Lizenz?', 'Läuft Basalto auf Shared Hosting?', 'Rabatt für Vereine und Schulen?', 'Kann ich von WordPress umziehen?',
                'Rechnung statt Kreditkarte möglich?', 'Gibt es eine Testversion?', 'Was passiert nach einem Jahr Updates?']), $run->pick(['Frage', 'Frage', 'Gelöst'])],
            'ideen', 'umgesetzte-vorschlaege' => [$run->pick(['Wunsch: ', 'Vorschlag: ', '']).$run->pick(self::IDEAS), $slug === 'ideen' ? $run->pick(['Idee', 'Idee', 'Umgesetzt', null]) : 'Umgesetzt'],
            'fehlermeldungen', 'erledigte-fehlermeldungen' => [$run->pick(['['.$version.'] '.$symptom.' bei '.Str::after($feature, ' '), ucfirst(Str::after($feature, ' ')).': '.$symptom]),
                $slug === 'fehlermeldungen' ? $run->pick(['Fehler', 'Fehler', 'Behoben']) : 'Behoben'],
            'themes-und-module' => [$run->pick(['Lava: Schleife über Unterseiten', 'Bild mit Fokuspunkt ausgeben', 'Eigene Schriftarten einbinden', 'Farben aus den Design-Tokens im Teil', 'Teil mit Parametern aufrufen',
                'Datum auf Italienisch formatieren', 'Theme «Basalt» im Dunkelmodus']), $run->pick(['Frage', 'Gelöst', null])],
            'module-und-plugins', 'schnittstellen', 'webhooks' => [$run->pick(['Eigenes Modul: Route registrieren', 'Migration im Plugin wird nicht ausgeführt', 'Rechte für eine neue Rolle', 'PHPStan meldet Fehler im Plugin',
                'Webhook-Signatur prüfen', 'Eigener Block mit Schema', 'REST-API: Einträge seitenweise holen']), $run->pick(['Frage', 'Gelöst', null])],
            'off-topic' => [$run->pick(['Stellt euch vor!', 'Welche Musik hört ihr beim Programmieren?', 'Fotos vom Ausflug auf den Pilatus', 'Lieblings-Editor 2026?', 'Kaffee oder Tee?', 'Velotour am Sonntag']), null],
            'testforum' => [$run->pick(['Test', 'Test mit Umlauten äöü ÄÖÜ ß und «Guillemets»', '<b>HTML im Titel</b> & Co.', '🚀 Emoji im Titel 🎉', 'Ein sehr, sehr langer Titel, der in keiner Liste auf eine einzige Zeile passt und deshalb sauber umbrechen oder abgeschnitten werden muss',
                TestdataText::UNBREAKABLE[1]]), null],
            'team-intern' => [$run->pick(['Release-Planung 1.3', 'Moderationsrichtlinien überarbeiten', 'Urlaubsvertretung Dezember']), null],
            'archiv-1-0', 'archiv-1-1' => ['Update von '.$run->pick(['0.9', '1.0', '1.1']).' auf '.$run->pick(['1.0', '1.1']).': '.$symptom, $run->pick(['Frage', 'Gelöst'])],
            default => [$run->pick(['Wie richte ich '.$feature.' ein?', ucfirst(Str::after($feature, ' ')).': '.$symptom.' nach Update auf '.$version, 'Frage zu '.Str::after($feature, ' '),
                $symptom.' bei '.Str::after($feature, ' ')]), $run->pick(['Frage', 'Frage', 'Gelöst', 'Gelöst', null])],
        };
    }

    /** @return array<string, mixed> */
    private function firstBody(TestdataRun $run, string $slug, string $locale): array
    {
        $blocks = match ($slug) {
            'fehlermeldungen', 'erledigte-fehlermeldungen' => ['p', 'ol', 'code'],
            'themes-und-module', 'module-und-plugins', 'schnittstellen', 'webhooks' => $run->pick([['p', 'code', 'p'], ['p', 'code']]),
            'ankundigungen' => ['marks', 'ul', 'p'],
            'testforum' => $run->pick([['long'], ['p', 'hr', 'spoiler'], ['marks', 'quote', 'ul', 'code', 'spoiler']]),
            default => $run->pick([['p'], ['p', 'p'], ['p', 'ul'], ['marks', 'p']]),
        };

        return TestdataText::doc($run, $blocks, $locale);
    }

    /** @return array<string, mixed> */
    private function replyBody(TestdataRun $run, string $locale, int $n, bool $isAuthor): array
    {
        if ($isAuthor && $run->chance(40)) {
            return TestdataText::doc($run, [$run->pick(['p'])], $locale);
        }
        if ($run->chance(8)) {
            return ['type' => 'doc', 'content' => [TestdataText::p($run->pick(['+1', 'Danke!', 'Gleiches Problem hier.', 'Merci!', 'Grazie!', '👍']))]];
        }

        return TestdataText::doc($run, $run->pick([['p'], ['p'], ['quote', 'p'], ['p', 'code'], ['p', 'ul'], ['marks'], ['p', 'spoiler']]), $locale, $n > 0 ? 'Vorredner' : null);
    }

    /** @param  array<string, mixed>  $body */
    private function post(TestdataRun $run, int $threadId, Customer $by, CarbonImmutable $at, array $body, bool $official, bool $disabled): int
    {
        $attachment = null;
        if ($run->chance(3) && TestdataImages::available()) {
            $attachment = $this->attachments->store($run->site, $by, TestdataImages::cover($run));
            $body['content'][] = ['type' => 'image', 'attrs' => ['src' => $attachment->url(), 'alt' => 'Bildschirmfoto', 'attachment' => $attachment->id]];
        }
        $edited = $run->chance(6);
        $id = (int) DB::table('forum_posts')->insertGetId(['site_id' => $run->site->id, 'thread_id' => $threadId, 'customer_id' => $by->id,
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_disabled' => $disabled, 'is_official' => $official,
            'edited_at' => $edited ? $run->after($at, 6) : null, 'edit_count' => $edited ? $run->int(1, 3) : 0, 'edit_reason' => $edited && $run->chance(50) ? 'Tippfehler korrigiert' : null,
            'created_at' => $at, 'updated_at' => $at]);
        if ($attachment !== null) {
            $this->attachments->assign($by, [$attachment->id], Forum::ATTACHMENTS, $id);
            DB::table('community_attachments')->where('id', $attachment->id)->update(['created_at' => $at]);
        }
        $this->posts[$id] = $by->id;
        $run->count('Beiträge');

        return $id;
    }

    private function revisions(TestdataRun $run, int $postId, Customer $author, CarbonImmutable $created): void
    {
        $at = $created;
        $count = $run->int(1, 3);
        for ($i = 0; $i < $count; $i++) {
            $at = $run->after($at, 12);
            DB::table('forum_post_revisions')->insert(['site_id' => $run->site->id, 'post_id' => $postId, 'customer_id' => $author->id,
                'body' => json_encode(TestdataText::doc($run, ['p']), JSON_UNESCAPED_UNICODE), 'title' => null, 'reason' => $run->pick([null, 'Ergänzt', 'Log angehängt']), 'created_at' => $at]);
        }
        DB::table('forum_posts')->where('id', $postId)->update(['edited_at' => $at, 'edit_count' => $count]);
        $run->count('Bearbeitungen im Verlauf', $count);
    }

    /** @param  list<Customer>  $members */
    private function poll(TestdataRun $run, int $threadId, CarbonImmutable $created, array $members): void
    {
        $this->polls++;
        [$question, $options] = $run->pick([['Wie wichtig ist Ihnen das?', ['Ja, unbedingt', 'Wäre nett', 'Brauche ich nicht']], ['Welchen Browser nutzen Sie?', ['Chrome', 'Firefox', 'Safari', 'Edge']],
            ['Wie oft spielen Sie Updates ein?', ['Täglich', 'Wöchentlich', 'Monatlich', 'Selten', 'Nie']], ['Was trinken Sie beim Arbeiten?', ['Kaffee', 'Tee']]]);
        $max = count($options) > 3 && $run->chance(40) ? 2 : 1;
        $ended = $run->chance(30);
        $pollId = (int) DB::table('forum_polls')->insertGetId(['site_id' => $run->site->id, 'thread_id' => $threadId, 'question' => $question,
            'max_choices' => $max, 'results_after_vote' => $run->chance(60), 'ends_at' => $ended ? $run->after($created, 24 * 10) : ($run->chance(50) ? $run->now()->addDays($run->int(2, 30)) : null),
            'voters' => 0, 'created_at' => $created, 'updated_at' => $created]);
        $optionIds = [];
        foreach ($options as $i => $label) {
            $optionIds[] = (int) DB::table('forum_poll_options')->insertGetId(['poll_id' => $pollId, 'label' => $label, 'position' => $i, 'votes' => 0]);
        }
        $voters = $run->picks($members, $run->int(0, min(40, count($members))));
        foreach ($voters as $voter) {
            foreach ($run->picks($optionIds, $run->int(1, $max)) as $optionId) {
                DB::table('forum_poll_votes')->insert(['poll_id' => $pollId, 'option_id' => $optionId, 'customer_id' => $voter->id, 'created_at' => $created]);
            }
        }
        foreach ($optionIds as $optionId) {
            DB::table('forum_poll_options')->where('id', $optionId)->update(['votes' => DB::table('forum_poll_votes')->where('option_id', $optionId)->count()]);
        }
        DB::table('forum_polls')->where('id', $pollId)->update(['voters' => count($voters)]);
        $run->count('Umfragen');
    }

    /**
     * Verschiebe-Hinweise: ein Thema zog um, im alten Forum steht ein Verweis (eine Zeit lang).
     *
     * @param  array<string, Node>  $boards
     */
    private function moved(TestdataRun $run, array $boards): void
    {
        $from = $boards['off-topic'] ?? null;
        $to = DB::table('forum_threads')->where('site_id', $run->site->id)->whereIn('id', $this->threads)->whereNull('deleted_at')->where('node_id', $boards['hilfe-und-support']->id ?? 0)->first();
        if ($from === null || $to === null) {
            return;
        }
        DB::table('forum_threads')->insert(['site_id' => $run->site->id, 'node_id' => $from->id, 'customer_id' => $to->customer_id, 'title' => $to->title, 'slug' => $to->slug, 'locale' => $to->locale,
            'moved_to_id' => $to->id, 'redirect_until' => $run->now()->addDays(10), 'created_at' => $to->created_at, 'updated_at' => $to->created_at, 'last_posted_at' => $to->created_at]);
        $run->count('Verschiebe-Hinweise');
    }

    /** @param  list<Customer>  $members */
    private function reactions(TestdataRun $run, array $members): void
    {
        $types = ['like' => 50, 'love' => 15, 'thanks' => 15, 'haha' => 10, 'wow' => 6, 'sad' => 4];
        $rows = [];
        foreach ($this->posts as $postId => $owner) {
            if (! $run->chance(30)) {
                continue;
            }
            foreach ($run->picks($members, $run->int(1, 8)) as $customer) {
                if ($customer->id === $owner) {
                    continue;
                }
                $rows[] = ['site_id' => $run->site->id, 'customer_id' => $customer->id, 'subject_type' => Forum::ATTACHMENTS, 'subject_id' => $postId, 'reaction' => $run->weighted($types), 'created_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('community_reactions')->insertOrIgnore($chunk);
        }
        $counts = DB::table('community_reactions')->where('subject_type', Forum::ATTACHMENTS)->whereIn('subject_id', array_keys($this->posts))
            ->selectRaw('subject_id, count(*) as n')->groupBy('subject_id')->pluck('n', 'subject_id');
        $received = [];
        foreach ($counts as $postId => $n) {
            DB::table('forum_posts')->where('id', $postId)->update(['reactions' => (int) $n]);
            $owner = $this->posts[(int) $postId] ?? null;
            if ($owner !== null) {
                $received[$owner] = ($received[$owner] ?? 0) + (int) $n;
            }
        }
        foreach ($received as $customerId => $n) {
            DB::table('community_profiles')->where('customer_id', $customerId)->update(['reactions_received' => $n]);
        }
        $run->count('Reaktionen', count($rows));
    }

    /**
     * Meldungen (offen, erledigt), Verwarnungen mit Folgen, eine Sperre und eine Schreibsperre.
     *
     * @param  list<Customer>  $members
     * @param  list<Customer>  $team
     */
    private function moderation(TestdataRun $run, array $members, array $team): void
    {
        $site = $run->site;
        $staff = $this->staffUser($run);
        $by = $staff !== null ? Moderator::staff($staff) : Moderator::system();
        $postIds = array_keys($this->posts);
        foreach ($run->picks($postIds, $run->times(3)) as $i => $postId) {
            foreach ($run->picks($members, $run->int(1, 3)) as $reporter) {
                $report = $this->reports->file($site->id, Moderation::REPORT_TYPE, $postId, $run->pick(array_keys(CommunityReport::REASONS)), $run->chance(50) ? TestdataText::sentence($run) : null, $reporter, null);
                if ($report !== null && $i % 3 === 2) {
                    $report->forceFill(['status' => $run->pick([CommunityReport::HIDDEN, CommunityReport::KEPT]), 'resolved_by' => $staff?->getAuthIdentifier(), 'resolved_at' => now()])->save();
                }
                $run->count('Meldungen');
            }
        }
        $candidates = array_values(array_filter($members, static fn (Customer $c): bool => ! in_array($c->id, array_map(static fn (Customer $t): int => $t->id, $team), true)));
        $warned = array_slice($candidates, -min(4 + $run->scale, count($candidates)));
        foreach ($warned as $i => $customer) {
            $this->warnings->warn($site, $customer, $by, $run->pick(['Werbung im Beitrag', 'Ton im Off-Topic', 'Doppelt gepostet', 'Persönliche Daten veröffentlicht']), $i === 0 ? 100 : $run->int(5, 40),
                $run->pick([null, 30, 90]), $run->chance(50) ? 'Bitte beachten Sie die Regeln.' : null);
            $run->count('Verwarnungen');
        }
        if (count($warned) >= 3) {
            $this->sanctions->ban($site, $warned[1], $by, $run->now()->addDays(14), 'Wiederholt Werbung');
            $this->sanctions->mute($site, $warned[2], $by, $run->now()->addDays(3), 'Abkühlen nach Streit');
            $run->remember('gesperrt', [$warned[1]]);
            $run->count('Sperren', 2);
        }
        $this->sanctions->forget();
    }

    /** Der erste Team-Benutzer der Site als Moderator für Protokoll und Verwarnungen. */
    private function staffUser(TestdataRun $run): ?Authenticatable
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = $userModel::query()->where('email', 'like', '%@'.TestdataRun::DOMAIN)->whereIn('id', DB::table('site_user')->where('site_id', $run->site->id)->select('user_id'))->orderBy('id')->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Gelesen und abonniert: Ein Teil der Mitglieder hat Themen gelesen, Foren als gelesen markiert, Themen beobachtet
     * oder ignoriert — damit «ungelesen» und Abos verschieden aussehen.
     *
     * @param  list<Customer>  $members
     * @param  array<string, Node>  $boards
     */
    private function reads(TestdataRun $run, array $members, array $boards): void
    {
        foreach (array_slice($members, 0, max(1, intdiv(count($members), 2))) as $customer) {
            foreach ($run->picks($this->threads, $run->int(0, 25)) as $threadId) {
                DB::table('forum_reads')->updateOrInsert(['customer_id' => $customer->id, 'thread_id' => $threadId], ['read_at' => $run->ago(30)]);
            }
            if ($run->chance(30)) {
                DB::table('forum_node_reads')->updateOrInsert(['customer_id' => $customer->id, 'node_id' => $run->pick($boards)->id], ['read_at' => $run->ago(20)]);
            }
            foreach ($run->picks($this->threads, $run->int(0, 3)) as $threadId) {
                DB::table('community_subscriptions')->updateOrInsert(['customer_id' => $customer->id, 'subject_type' => 'forum_thread', 'subject_id' => $threadId],
                    ['site_id' => $run->site->id, 'mode' => $run->chance(85) ? 'watch' : 'ignore', 'created_at' => now()]);
                $run->count('Abos');
            }
        }
    }
}
