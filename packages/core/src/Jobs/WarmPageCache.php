<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Content\Pages;
use Basalto\Core\Http\Controllers\FrontendController;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Vorwärmen nach dem Veröffentlichen (M-16): rendert die veröffentlichte Seite in jeder Sprache und die Startseite
 * einmal über die Auslieferung, damit der erste Besuch schon aus dem Cache kommt. Ohne `entryId` die ganze Site
 * (`basalto:page-cache warm`). Läuft ohne HTTP-Umweg: eine Anfrage ohne Sitzung und ohne Person an den Controller.
 */
final class WarmPageCache implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $siteId, public readonly ?int $entryId = null) {}

    public function handle(Pages $pages, CurrentSite $current, PageCache $cache): int
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $cache->enabled($site)) {
            return 0;
        }
        $pages->forget();
        $entries = $this->entryId === null ? $pages->entries($site) : Entry::query()->withoutGlobalScopes()->whereKey($this->entryId)->get();
        $paths = [];
        foreach ($site->localeCodes() as $locale) {
            $paths[] = $pages->urlPath($site, $locale, '/');
            foreach ($entries as $entry) {
                $entry->setRelation('site', $site);
                $url = $pages->urlOf($entry, $locale);
                if ($url !== null) {
                    $paths[] = $url;
                }
            }
        }
        $base = self::base($site);
        $warmed = 0;
        $locale = app()->getLocale();
        foreach (array_unique($paths) as $path) {
            try {
                $response = $current->within($site, static fn (): mixed => app(FrontendController::class)(Request::create($base.$path, 'GET')));
                $warmed += $response->headers->get('X-Basalto-Cache') === 'MISS' ? 1 : 0;
            } catch (Throwable) {
                // eine Seite, die nicht rendert, wärmt eben nicht — der erste Besuch rendert sie selbst
            }
        }
        app()->setLocale($locale);

        return $warmed;
    }

    private static function base(Site $site): string
    {
        $appUrl = (string) config('app.url');
        $host = SiteDomain::query()->where('site_id', $site->id)->orderByDesc('is_primary')->value('host');
        if (is_string($host) && $host !== '') {
            return (string) (parse_url($appUrl, PHP_URL_SCHEME) ?: 'https').'://'.$host;
        }

        return rtrim($appUrl, '/');
    }
}
