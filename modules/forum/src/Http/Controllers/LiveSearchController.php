<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers;

use Basalto\Core\Search\SiteSearch;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\ForumSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live-Suche im Forum (TB 71): Während jemand im Suchfeld der Forumsleiste tippt, schlägt die Insel bis zu fünf Themen
 * vor — aus demselben Index wie die Suchseite (nur Themen, die Gäste lesen dürfen), in der Sprache der Seite. Der
 * Auszug ist maskiert und trägt nur `<mark>` für den Suchbegriff; er stammt aus dem passenden Beitrag, `meta` nennt wer
 * und wann, der Link führt zum Beitrag.
 */
final class LiveSearchController
{
    public const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $site = app(CurrentSite::class)->get();
        abort_if($site === null, 404);
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        if (mb_strlen($q) < 2) {
            return response()->json(['total' => 0, 'items' => []]);
        }
        $locale = in_array($request->query('sprache'), $site->localeCodes(), true) ? (string) $request->query('sprache') : $site->default_locale;
        $result = app(SiteSearch::class)->search($site, $locale, $q, 1, ForumSearch::SOURCE);

        return response()->json(['total' => $result['total'], 'items' => array_map(static fn (array $item): array => ['title' => $item['title'], 'url' => $item['path'],
            'excerpt' => $item['excerpt'], 'meta' => $item['meta']], array_slice($result['items'], 0, self::LIMIT))]);
    }
}
