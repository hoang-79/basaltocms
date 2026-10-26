<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers;

use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * «Ähnliche Themen» beim Eröffnen (CR-006 § 3.2): Während jemand den Titel tippt, schlägt die Insel bis zu fünf
 * Themen vor, deren Titel dieselben Wörter tragen — nur aus Foren, die die Person lesen darf. Wörter unter vier
 * Zeichen zählen nicht; mehr gemeinsame Wörter stehen weiter oben.
 */
final class SimilarController
{
    public const LIMIT = 5;

    public function __construct(private readonly Forum $forum) {}

    public function __invoke(Request $request): JsonResponse
    {
        $site = app(CurrentSite::class)->get();
        abort_if($site === null, 404);
        $words = self::words((string) $request->query('titel', ''));
        if ($words === []) {
            return response()->json([]);
        }
        $customer = CustomerAuth::current();
        $readable = $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, $customer, Forum::READ, $n))->keys()->all();
        $candidates = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')
            ->whereIn('node_id', $readable)->where(static function ($q) use ($words): void {
                foreach ($words as $word) {
                    $q->orWhereRaw('LOWER(title) LIKE ?', ['%'.$word.'%']);
                }
            })->orderByDesc('last_posted_at')->limit(50)->get();
        $base = Html::pagePath($request->query('basis'));
        $base = $base === '/' ? '' : rtrim($base, '/');
        $scored = $candidates->map(static function (Thread $t) use ($words): array {
            $title = mb_strtolower($t->title);

            return ['thread' => $t, 'score' => count(array_filter($words, static fn (string $w): bool => str_contains($title, $w)))];
        })->sortByDesc('score')->take(self::LIMIT);

        return response()->json(array_values($scored->map(static fn (array $s): array => ['title' => $s['thread']->title, 'url' => $base.'/thema/'.$s['thread']->pathSegment(),
            'replies' => $s['thread']->replies, 'done' => $s['thread']->is_done])->all()));
    }

    /** @return list<string> die Suchwörter eines Titels, klein, ohne Satzzeichen, höchstens sechs */
    public static function words(string $title): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Str::limit($title, 160, ''))) ?: [];

        return array_slice(array_values(array_unique(array_filter($parts, static fn (string $w): bool => mb_strlen($w) >= 4))), 0, 6);
    }
}
