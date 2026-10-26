<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Console;

use Basalto\Core\Community\MountRequest;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\PageType;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Modules\Forum\Counters;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Frontend\ForumMount;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\NodeType;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lasttest des Forums (CR-006 FO-29): legt eine Wegwerf-Site mit vielen Beiträgen an (Vorgabe 100 000, ein Thema mit
 * 2 000 Antworten), misst Themenliste und Thema (erste und letzte Seite) auf dem Server mit Zahl der Abfragen und
 * räumt danach auf. Ziel: jede Ansicht unter 300 ms, Abfragen unabhängig von der Datenmenge (kein N+1).
 */
final class ForumBenchmarkCommand extends Command
{
    protected $signature = 'basalto:forum-benchmark {--posts=100000 : Beiträge insgesamt} {--limit=300 : Grenze in Millisekunden} {--keep : Site nicht entfernen}';

    protected $description = 'Misst Themenliste und Thema des Forums mit vielen Beiträgen (Wegwerf-Site)';

    private const HANDLE = 'bench-forum';

    public function handle(Counters $counters): int
    {
        $this->clean();
        $total = max(1000, (int) $this->option('posts'));
        $started = microtime(true);
        [$site, $entry, $version, $board, $big] = $this->seed($total);
        $counters->all($site);
        $this->line(sprintf('Angelegt: %d Beiträge in %.1f s.', $total, microtime(true) - $started));
        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($big);
        $lastBoard = (int) ceil(Thread::query()->withoutGlobalScopes()->where('node_id', $board)->count() / ForumMount::THREADS_PER_PAGE);
        $lastThread = (int) ceil(($thread->replies + 1) / ForumMount::POSTS_PER_PAGE);
        $limit = (int) $this->option('limit');
        $rows = [];
        $ok = true;
        $node = Node::query()->withoutGlobalScopes()->findOrFail($board);
        foreach ([['Forenübersicht', ''], ['Themenliste, Seite 1', $node->slug], ['Themenliste, letzte Seite', $node->slug.'?seite='.$lastBoard],
            ['Thema, Seite 1', 'thema/'.$thread->pathSegment()], ['Thema, letzte Seite', 'thema/'.$thread->pathSegment().'?seite='.$lastThread]] as [$label, $path]) {
            [$ms, $queries] = $this->measure($site, $entry, $version, $path);
            $ok = $ok && $ms < $limit;
            $rows[] = [$label, sprintf('%.0f ms', $ms), $queries, $ms < $limit ? 'ok' : 'zu langsam'];
        }
        $this->table(['Ansicht', 'Zeit (Server)', 'Abfragen', 'Ergebnis'], $rows);
        if (! $this->option('keep')) {
            $this->clean();
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: float, 1: int} Millisekunden (Median aus drei Läufen) und Abfragen */
    private function measure(Site $site, Entry $entry, EntryVersion $version, string $path): array
    {
        [$rest, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $times = [];
        $queries = 0;
        for ($i = 0; $i < 3; $i++) {
            app(Forum::class)->forget();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $result = app(ForumMount::class)->handle(new MountRequest($site, 'de', $entry, $version, '/community', $rest, Request::create('http://'.self::HANDLE.'.test/community/'.$path, 'GET', $params)));
            $times[] = (hrtime(true) - $start) / 1e6;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            if ($result->kind !== 'page') {
                $this->warn($path.': '.$result->kind);
            }
        }
        sort($times);

        return [$times[1], $queries];
    }

    /** @return array{0: Site, 1: Entry, 2: EntryVersion, 3: int, 4: int} */
    private function seed(int $total): array
    {
        $site = Site::query()->create(['handle' => self::HANDLE, 'account_id' => Account::installation()->id, 'name' => 'Bench Forum', 'default_locale' => 'de', 'timezone' => 'Europe/Zurich', 'theme' => 'starter']);
        $site->locales()->create(['locale' => 'de', 'is_default' => true, 'is_required' => true, 'sort_order' => 0]);
        $site->setModuleEnabled('forum', true);
        $site->save();
        $entry = new Entry(['site_id' => $site->id, 'type' => PageType::Page->value, 'parent_id' => null]);
        $entry->settings = ['expert' => ['mount' => Forum::MOUNT]];
        $entry->save();
        $version = $entry->newDraft('de', ['title' => 'Community', 'slug' => 'community'], null);
        $version->forceFill(['status' => EntryStatus::Approved])->save();
        $version->publish();
        $now = now()->subDays(30);
        $category = Node::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'type' => NodeType::Category, 'slug' => 'allgemein', 'title' => ['de' => 'Allgemein'], 'position' => 1]);
        $board = Node::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'parent_id' => $category->id, 'type' => NodeType::Board, 'slug' => 'hilfe', 'title' => ['de' => 'Hilfe'], 'position' => 2]);
        $customers = [];
        for ($c = 0; $c < 200; $c++) {
            $customers[] = ['site_id' => $site->id, 'email' => sprintf('bench-%d@example.test', $c), 'locale' => 'de', 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('customers')->insert($customers);
        $ids = DB::table('customers')->where('site_id', $site->id)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        DB::table('community_profiles')->insert(array_map(static fn (int $id, int $i): array => ['site_id' => $site->id, 'customer_id' => $id, 'name' => 'Mitglied '.$i, 'name_key' => 'mitglied '.$i,
            'created_at' => $now, 'updated_at' => $now], $ids, array_keys($ids)));
        $body = json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ein Beitrag mit etwas Text, damit die Seite nicht leer ist. Basalto ist aus einem Guss.']]]]]);
        $bigReplies = min(2000, intdiv($total, 10));
        $threads = max(1, intdiv($total - $bigReplies - 1, 20));
        $bigId = 0;
        $made = 0;
        for ($t = 0; $t <= $threads; $t++) {
            $count = $t === 0 ? $bigReplies + 1 : min(20, $total - $made);
            if ($count <= 0) {
                break;
            }
            $author = $ids[$t % count($ids)];
            $threadId = (int) DB::table('forum_threads')->insertGetId(['site_id' => $site->id, 'node_id' => $board->id, 'customer_id' => $author, 'title' => 'Thema Nummer '.$t,
                'slug' => 'thema-nummer-'.$t, 'locale' => 'de', 'created_at' => $now, 'updated_at' => $now, 'last_posted_at' => $now->copy()->addMinutes($t)]);
            $bigId = $t === 0 ? $threadId : $bigId;
            $rows = [];
            for ($p = 0; $p < $count; $p++) {
                $rows[] = ['site_id' => $site->id, 'thread_id' => $threadId, 'customer_id' => $ids[($t + $p) % count($ids)], 'body' => $body, 'created_at' => $now, 'updated_at' => $now];
                if (count($rows) === 500) {
                    DB::table('forum_posts')->insert($rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                DB::table('forum_posts')->insert($rows);
            }
            $made += $count;
        }

        return [$site, $entry, $version, $board->id, $bigId];
    }

    private function clean(): void
    {
        $site = Site::query()->where('handle', self::HANDLE)->first();
        if ($site === null) {
            return;
        }
        DB::table('search_documents')->where('site_id', $site->id)->delete();
        $threads = DB::table('forum_threads')->where('site_id', $site->id)->select('id');
        DB::table('forum_posts')->whereIn('thread_id', $threads)->delete();
        DB::table('forum_threads')->where('site_id', $site->id)->delete();
        DB::table('forum_nodes')->where('site_id', $site->id)->delete();
        DB::table('community_profiles')->where('site_id', $site->id)->delete();
        DB::table('customers')->where('site_id', $site->id)->delete();
        DB::table('entry_versions')->whereIn('entry_id', DB::table('entries')->where('site_id', $site->id)->select('id'))->delete();
        DB::table('entries')->where('site_id', $site->id)->delete();
        $site->delete();
        unset($site);
    }
}
