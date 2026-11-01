<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Testdata;

use App\Models\User;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRichText;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Modules\Forum\Console\ForumSeedCommand;
use Basalto\Modules\Forum\Counters;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumSearch;
use Basalto\Modules\Forum\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Testdaten «Forum-Geschichte»: ein Forum, das wie gelebt aussieht — Mitglieder mit verschiedenen Beitrittsdaten,
 * rund hundert Themen in allen Foren mit Fragen, Antworten untereinander und vom Team, Querverweisen, besten
 * Antworten und Reaktionen, alles mit Datum und Uhrzeit aus {@see ForumStory::FILE}. Für Entwicklung und
 * Test-Umgebungen; nie in der Demo und wie alle Testdaten nicht in Produktion. Die Konten tragen
 * `forum.<name>@testdaten.example`; jeder Lauf räumt die eigenen Daten zuerst ab und legt sie gleich wieder an.
 */
final class ForumStoryTestdata
{
    public const KEY = 'forum-geschichte';

    private const LOCAL = 'forum.';

    /** @var array<string, array<string, int>> Reaktionen je Art des Beitrags (Gewichte) */
    private const REACTIONS = [
        'team' => ['like' => 55, 'love' => 15, 'thanks' => 25, 'wow' => 5],
        'beste' => ['thanks' => 55, 'like' => 35, 'love' => 10],
        'frage' => ['like' => 70, 'wow' => 10, 'sad' => 20],
        'antwort' => ['like' => 55, 'thanks' => 25, 'haha' => 12, 'love' => 8],
        'offtopic' => ['haha' => 40, 'like' => 35, 'love' => 20, 'wow' => 5],
    ];

    /** @var array<string, int> Schlüssel des Themas → ID */
    private array $threadIds = [];

    /** @var array<string, string> Schlüssel des Themas → Slug */
    private array $slugs = [];

    /** @var array<string, array<int, int>> Schlüssel des Themas → Nummer des Beitrags (ab 0) → ID */
    private array $postIds = [];

    public function __construct(private readonly Profiles $profiles, private readonly VisitorAccess $access, private readonly VisitorGroups $groups,
        private readonly Counters $counters) {}

    public function seed(TestdataRun $run): void
    {
        $site = $run->site;
        $story = ForumStory::parse((string) file_get_contents(ForumStory::FILE));
        $problems = ForumStory::problems($story);
        if ($problems !== []) {
            throw new RuntimeException("Die Forum-Geschichte hat Fehler:\n".implode("\n", array_slice($problems, 0, 20)));
        }
        $this->remove($site);
        $made = [];
        ForumSeedCommand::build($site, ForumSeedCommand::STRUCTURE, $this->access, $this->groups, [], null, $made);
        app(Forum::class)->forget();
        // Läuft die Geschichte allein (`--nur=forum-geschichte`), braucht die Community trotzdem ihre Seite
        app(ForumTestdata::class)->page($run);
        $nodes = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('slug');
        $prefixes = ForumSeedCommand::prefixes($site);
        $base = rtrim(app(Forum::class)->basePath($site, $site->default_locale) ?? '/community', '/');
        $last = [];
        foreach ($story['threads'] as $thread) {
            foreach ($thread['posts'] as $post) {
                $last[$post['author']] = max($last[$post['author']] ?? $post['at'], $post['at']);
            }
        }
        $customers = $this->members($run, $story['members'], $last);

        // Alle Beiträge nach der Zeit: IDs wachsen mit dem Datum, und jeder Verweis zeigt auf schon Angelegtes
        $events = [];
        foreach ($story['threads'] as $t => $thread) {
            foreach ($thread['posts'] as $p => $post) {
                $events[] = [$post['at'], $t, $p];
            }
        }
        usort($events, static fn (array $a, array $b): int => [$a[0]->getTimestamp(), $a[1], $a[2]] <=> [$b[0]->getTimestamp(), $b[1], $b[2]]);
        $this->threadIds = [];
        $this->slugs = [];
        $this->postIds = [];
        $kinds = [];
        $link = fn (string $ref): string => $this->href($ref, $base);
        foreach ($events as [$at, $t, $p]) {
            $thread = $story['threads'][$t];
            $post = $thread['posts'][$p];
            $author = $customers[$post['author']];
            $team = $story['members'][$post['author']]['team'];
            $utc = $at->utc();
            if ($p === 0) {
                $slug = Str::limit(Str::slug($thread['title'], '-', 'de') ?: 'thema', 100, '');
                $this->threadIds[$thread['key']] = (int) DB::table('forum_threads')->insertGetId(['site_id' => $site->id, 'node_id' => $nodes[$thread['forum']]->id,
                    'customer_id' => $author->id, 'title' => $thread['title'], 'slug' => $slug, 'locale' => $site->default_locale,
                    'prefix_id' => $thread['prefix'] === null ? null : ($prefixes[$thread['prefix']] ?? null),
                    'form_values' => $thread['fields'] === [] ? null : json_encode($thread['fields'], JSON_UNESCAPED_UNICODE),
                    'is_sticky' => in_array('angeheftet', $thread['flags'], true), 'is_closed' => in_array('geschlossen', $thread['flags'], true),
                    'is_announcement' => in_array('ankuendigung', $thread['flags'], true), 'views' => 0, 'created_at' => $utc, 'updated_at' => $utc]);
                $this->slugs[$thread['key']] = $slug;
                $run->count('Themen (Geschichte)');
            }
            $body = CommunityRichText::clean(ForumStory::doc($post['text'], $link)) ?? throw new RuntimeException('Beitrag leer: Zeile '.$post['line']);
            $id = (int) DB::table('forum_posts')->insertGetId(['site_id' => $site->id, 'thread_id' => $this->threadIds[$thread['key']], 'customer_id' => $author->id,
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_disabled' => false, 'is_official' => $team, 'edit_count' => 0,
                'created_at' => $utc, 'updated_at' => $utc]);
            $this->postIds[$thread['key']][$p] = $id;
            $kinds[$id] = [$author->id, $utc, match (true) {
                $team => 'team',
                in_array('beste', $post['flags'], true) => 'beste',
                $thread['forum'] === 'off-topic' => 'offtopic',
                $p === 0 => 'frage',
                default => 'antwort',
            }];
            $run->count('Beiträge (Geschichte)');
        }

        foreach ($story['threads'] as $thread) {
            $ids = $this->postIds[$thread['key']];
            ksort($ids);
            $best = null;
            foreach ($thread['posts'] as $p => $post) {
                $best = in_array('beste', $post['flags'], true) ? $ids[$p] : $best;
            }
            $lastPost = $thread['posts'][count($thread['posts']) - 1];
            $replies = count($ids) - 1;
            DB::table('forum_threads')->where('id', $this->threadIds[$thread['key']])->update(['first_post_id' => reset($ids), 'last_post_id' => end($ids),
                'last_customer_id' => $customers[$lastPost['author']]->id, 'last_posted_at' => $lastPost['at']->utc(), 'replies' => $replies, 'best_post_id' => $best,
                'is_done' => $best !== null || in_array($thread['prefix'], ['Gelöst', 'Behoben', 'Umgesetzt'], true),
                'views' => $run->int(20 + $replies * 12, 60 + $replies * 45)]);
        }
        $this->reactions($run, $customers, $story['members'], $kinds);
        $this->counters->all($site);
        foreach ($this->threadIds as $id) {
            app(ForumSearch::class)->thread($id);
        }
        app(PageCache::class)->invalidate($site->id);
    }

    /** Adresse zu `@thema:schlüssel` oder `@beitrag:schlüssel:n` — das Ziel ist schon angelegt (ForumStory::problems prüft die Richtung). */
    private function href(string $ref, string $base): string
    {
        if (preg_match('/^@(thema|beitrag):([a-z0-9-]+)(?::(\d+))?$/', $ref, $m) !== 1 || ! isset($this->threadIds[$m[2]], $this->slugs[$m[2]])) {
            throw new RuntimeException('Verweis ohne Ziel: '.$ref);
        }
        $url = $base.'/thema/'.$this->threadIds[$m[2]].'-'.$this->slugs[$m[2]];
        if ($m[1] !== 'beitrag') {
            return $url;
        }
        $post = $this->postIds[$m[2]][(int) ($m[3] ?? 1) - 1] ?? throw new RuntimeException('Verweis ohne Ziel: '.$ref);

        return $url.'#beitrag-'.$post;
    }

    /** Räumt ab, was die Geschichte angelegt hat: Konten `forum.…@testdaten.example` mit Themen, Beiträgen, Reaktionen und Profilen. */
    public function remove(Site $site): void
    {
        $customers = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', 'like', self::LOCAL.'%@'.TestdataRun::DOMAIN)->pluck('id')->all();
        if ($customers === []) {
            return;
        }
        $threads = DB::table('forum_threads')->where('site_id', $site->id)->whereIn('customer_id', $customers)->pluck('id')->all();
        $posts = DB::table('forum_posts')->where('site_id', $site->id)->where(static fn ($q) => $q->whereIn('customer_id', $customers)->orWhereIn('thread_id', $threads))->pluck('id')->all();
        foreach (array_chunk($posts, 500) as $chunk) {
            DB::table('community_reactions')->where('subject_type', Forum::ATTACHMENTS)->whereIn('subject_id', $chunk)->delete();
            DB::table('forum_posts')->whereIn('id', $chunk)->delete();
        }
        DB::table('community_reactions')->where('site_id', $site->id)->whereIn('customer_id', $customers)->delete();
        foreach (array_chunk($threads, 500) as $chunk) {
            DB::table('search_documents')->where('source', ForumSearch::SOURCE)->whereIn('source_id', $chunk)->delete();
            DB::table('forum_threads')->whereIn('id', $chunk)->delete();
        }
        DB::table('notifications')->where('notifiable_type', (new Customer)->getMorphClass())->whereIn('notifiable_id', $customers)->delete();
        CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('customer_id', $customers)->delete();
        $emails = Customer::query()->withoutGlobalScopes()->whereIn('id', $customers)->pluck('email')->all();
        Customer::query()->withoutGlobalScopes()->whereIn('id', $customers)->delete();
        User::query()->whereIn('email', $emails)->delete();
        app(Forum::class)->forget();
        $this->counters->all($site);
    }

    /**
     * Mitglieder als Kundenkonten mit Profil; das Team zusätzlich als Benutzer, mit dem Profil verknüpft (Abzeichen «Team»).
     *
     * @param  array<string, array{name: string, joined: CarbonImmutable, about: string, team: bool}>  $members
     * @param  array<string, CarbonImmutable>  $last  letzter Beitrag je Mitglied
     * @return array<string, Customer>
     */
    private function members(TestdataRun $run, array $members, array $last): array
    {
        $site = $run->site;
        $out = [];
        foreach ($members as $key => $member) {
            $email = TestdataRun::email(self::LOCAL.$key);
            $joined = $member['joined']->utc();
            if ($member['team']) {
                $user = User::query()->where('email', $email)->first() ?? new User;
                $user->forceFill(['name' => $member['name'], 'email' => $email, 'password' => Hash::make(Str::random(48)), 'email_verified_at' => $joined,
                    'created_at' => $joined, 'updated_at' => $joined])->save();
                $profile = $this->profiles->linkTeam($user, $site);
                $customer = $profile->customer ?? throw new RuntimeException('Team ohne Kundenkonto: '.$key);
            } else {
                $customer = new Customer(['site_id' => $site->id, 'email' => $email, 'name' => $member['name'], 'locale' => $site->default_locale]);
                $customer->save();
                $wanted = $member['name'];
                $profile = $this->profiles->save($customer, $this->profiles->nameError($site, $wanted) === null ? $wanted : $this->profiles->suggestion($site, $wanted));
            }
            $active = ($last[$key] ?? $member['joined'])->utc();
            $customer->forceFill(['created_at' => $joined, 'updated_at' => $joined, 'last_login_at' => $active])->save();
            $profile->forceFill(['about' => $member['about'], 'created_at' => $joined, 'last_activity_at' => $active])->save();
            $out[$key] = $customer;
            $run->count('Mitglieder (Geschichte)');
        }

        return $out;
    }

    /**
     * Reaktionen von Mitgliedern, die zur Zeit des Beitrags schon dabei waren — mehr für beste Antworten und das Team.
     *
     * @param  array<string, Customer>  $customers
     * @param  array<string, array{name: string, joined: CarbonImmutable, about: string, team: bool}>  $members
     * @param  array<int, array{0: int, 1: CarbonImmutable, 2: string}>  $kinds  Beitrag → Autor, Zeit, Art
     */
    private function reactions(TestdataRun $run, array $customers, array $members, array $kinds): void
    {
        $rows = [];
        $received = [];
        $counts = [];
        $now = $run->now();
        foreach ($kinds as $postId => [$owner, $at, $kind]) {
            [$chance, $min, $max] = match ($kind) {
                'team' => [70, 2, 9],
                'beste' => [95, 2, 6],
                'offtopic' => [60, 1, 6],
                'frage' => [25, 1, 2],
                default => [35, 1, 4],
            };
            if (! $run->chance($chance)) {
                continue;
            }
            $pool = array_values(array_filter($customers, static fn (Customer $c): bool => $c->id !== $owner));
            $pool = array_values(array_filter($pool, static function (Customer $c) use ($members, $customers, $at): bool {
                $key = array_search($c, $customers, true);

                return is_string($key) && ! $members[$key]['team'] && $members[$key]['joined']->lte($at);
            }));
            foreach ($run->picks($pool, min(count($pool), $run->int($min, $max))) as $customer) {
                $when = $at->addMinutes($run->int(5, 60 * 30));
                $rows[] = ['site_id' => $run->site->id, 'customer_id' => $customer->id, 'subject_type' => Forum::ATTACHMENTS, 'subject_id' => $postId,
                    'reaction' => $run->weighted(self::REACTIONS[$kind]), 'created_at' => $when->gt($now) ? $now : $when];
                $counts[$postId] = ($counts[$postId] ?? 0) + 1;
                $received[$owner] = ($received[$owner] ?? 0) + 1;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('community_reactions')->insertOrIgnore($chunk);
        }
        foreach ($counts as $postId => $n) {
            DB::table('forum_posts')->where('id', $postId)->update(['reactions' => $n]);
        }
        foreach ($received as $customerId => $n) {
            DB::table('community_profiles')->where('customer_id', $customerId)->update(['reactions_received' => $n]);
        }
        $run->count('Reaktionen (Geschichte)', count($rows));
    }
}
