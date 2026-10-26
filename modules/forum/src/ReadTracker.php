<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gelesen und ungelesen (CR-006 FO-10): je Konto der Zeitpunkt, zu dem ein Thema zuletzt gelesen wurde, dazu «Forum als
 * gelesen markieren» und «alles gelesen» (Forum 0). Neu ist ein Thema, wenn sein letzter Beitrag später kam — und
 * höchstens 30 Tage alt ist, damit ein neues Konto nicht mit dem ganzen Archiv als «neu» beginnt.
 */
final class ReadTracker
{
    public const HORIZON_DAYS = 30;

    public function markThread(Customer $customer, Thread $thread): void
    {
        DB::table('forum_reads')->updateOrInsert(['customer_id' => $customer->id, 'thread_id' => $thread->id], ['read_at' => now()]);
    }

    /** Ein Forum (oder mit 0 alles) als gelesen markieren. */
    public function markNode(Customer $customer, int $nodeId): void
    {
        DB::table('forum_node_reads')->updateOrInsert(['customer_id' => $customer->id, 'node_id' => $nodeId], ['read_at' => now()]);
    }

    /**
     * Ab wann ein Thema für die Person neu ist: das späteste aus eigenem Lesen, Forum, «alles gelesen» und Horizont.
     *
     * @param  list<Thread>  $threads
     * @return array<int, CarbonInterface>
     */
    public function readSince(Customer $customer, array $threads): array
    {
        if ($threads === []) {
            return [];
        }
        $reads = DB::table('forum_reads')->where('customer_id', $customer->id)->whereIn('thread_id', array_map(static fn (Thread $t): int => $t->id, $threads))->pluck('read_at', 'thread_id');
        $nodes = DB::table('forum_node_reads')->where('customer_id', $customer->id)->pluck('read_at', 'node_id');
        $horizon = now()->subDays(self::HORIZON_DAYS);
        $floor = $customer->created_at !== null && $customer->created_at->gt($horizon) ? $customer->created_at : $horizon;
        $out = [];
        foreach ($threads as $thread) {
            $times = [$floor];
            foreach ([$reads[$thread->id] ?? null, $nodes[$thread->node_id] ?? null, $nodes[0] ?? null] as $time) {
                if ($time !== null) {
                    $times[] = Carbon::parse((string) $time);
                }
            }
            $out[$thread->id] = max($times);
        }

        return $out;
    }

    /**
     * Die Kennungen ungelesener Themen.
     *
     * @param  list<Thread>  $threads
     * @return list<int>
     */
    public function unread(?Customer $customer, array $threads): array
    {
        if ($customer === null) {
            return [];
        }
        $since = $this->readSince($customer, $threads);

        return array_values(array_map(static fn (Thread $t): int => $t->id, array_filter($threads, static fn (Thread $t): bool => $t->last_posted_at !== null && $t->last_posted_at->gt($since[$t->id]))));
    }

    /** Der erste Beitrag nach dem letzten Lesen — oder null, wenn alles gelesen ist. */
    public function firstUnread(Customer $customer, Thread $thread): ?Post
    {
        $since = $this->readSince($customer, [$thread])[$thread->id];

        return Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_disabled', false)->where('created_at', '>', $since)->orderBy('id')->first();
    }

    /**
     * Foren mit ungelesenen Themen (für die Übersicht).
     *
     * @param  iterable<Node>  $nodes
     * @return list<int>
     */
    public function unreadNodes(?Customer $customer, iterable $nodes, Forum $forum): array
    {
        if ($customer === null) {
            return [];
        }
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node->id;
        }
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->whereIn('node_id', $ids)->where('is_disabled', false)->whereNull('moved_to_id')->where('last_posted_at', '>', now()->subDays(self::HORIZON_DAYS))->get()->all();
        $unread = $this->unread($customer, array_values($threads));
        $out = [];
        foreach ($threads as $thread) {
            if (in_array($thread->id, $unread, true)) {
                $out[] = $thread->node_id;
            }
        }

        return array_values(array_unique($out));
    }
}
