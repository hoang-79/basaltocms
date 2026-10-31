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
 *
 * TB 91: In der Warteschlange gilt ein Zeitbudget je Lauf (`basalto.cache.warm_seconds`) — der Worker auf einfachem
 * Hosting bricht Jobs nach 60 s ab, ein grosses Schaufenster hat Hunderte Seiten. Ist es aufgebraucht, übernimmt eine
 * Fortsetzung ab der nächsten Adresse; die Startseiten kommen zuerst.
 */
final class WarmPageCache implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  int  $from  Fortsetzung: ab dieser Stelle der Adressen */
    public function __construct(public readonly int $siteId, public readonly ?int $entryId = null, public readonly int $from = 0) {}

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
        $budget = (float) config('basalto.cache.warm_seconds', 40);
        $started = hrtime(true);
        foreach (array_slice(array_values(array_unique($paths)), $this->from, preserve_keys: true) as $index => $path) {
            if ($this->job !== null && $index > $this->from && (hrtime(true) - $started) / 1e9 >= $budget) {
                self::dispatch($this->siteId, $this->entryId, $index);
                break;
            }
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
