<?php

declare(strict_types=1);

namespace Basalto\Core\Cache;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Events\HostnameReady;
use Basalto\Core\Events\VersionPublished;
use Basalto\Core\Jobs\WarmPageCache;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Models\Setting;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Models\SiteLocale;
use Basalto\Core\Models\SiteTheme;
use Basalto\Lava\Theme\LavaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Wann der Seiten-Cache einer Site veraltet (M-16): bei jeder Änderung, die eine ausgelieferte Seite betreffen kann —
 * Veröffentlichen, Offline nehmen und Archivieren (nicht beim Speichern eines Entwurfs), Seiteneinstellungen und
 * Baum, Navigation, Medien (Alt-Text, Fokuspunkt), Site, Sprachen, Domains, Einstellungen, Lava-Quellen. Nach einer
 * Veröffentlichung wärmt ein Job die Seite und die Startseite vor; antwortet eine beim Hoster eingerichtete Unterdomain
 * (CR-017), die ganze Site. Der Stand beim Hoster an einer Domain (TB 91) macht nichts ungültig.
 */
final class PageCacheInvalidation
{
    public static function register(): void
    {
        $bySiteId = static fn (Model $model): ?int => is_numeric($model->getAttribute('site_id')) ? (int) $model->getAttribute('site_id') : null;
        foreach ([Entry::class, NavigationItem::class, Media::class, SiteLocale::class, Setting::class, LavaSource::class, SiteTheme::class] as $class) {
            $class::saved(static fn (Model $model) => self::invalidate($bySiteId($model)));
            $class::deleted(static fn (Model $model) => self::invalidate($bySiteId($model)));
        }
        Entry::restored(static fn (Entry $entry) => self::invalidate($entry->site_id));
        // TB 91: `basalto:hostnamen` schreibt den Stand beim Hoster alle paar Minuten — sonst wäre ein vorgewärmtes
        // Schaufenster bis zum fertigen Zertifikat mehrfach wieder kalt
        SiteDomain::created(static fn (SiteDomain $domain) => self::invalidate($domain->site_id));
        SiteDomain::updated(static function (SiteDomain $domain): void {
            if (array_diff(array_keys($domain->getChanges()), SiteDomain::HOSTING_COLUMNS) !== []) {
                self::invalidate($domain->site_id);
            }
        });
        SiteDomain::deleted(static fn (SiteDomain $domain) => self::invalidate($domain->site_id));
        Site::saved(static fn (Site $site) => self::invalidate($site->id));
        // Fassungen: nur was live war oder live wird — ein Entwurf ändert keine ausgelieferte Seite
        $versionChange = static function (EntryVersion $version): void {
            $published = EntryStatus::Published->value;
            $status = $version->status->value;
            $original = $version->getOriginal('status');
            $before = $original instanceof EntryStatus ? $original->value : (string) $original;
            if ($status === $published || $before === $published || ! $version->exists) {
                self::invalidate(Entry::query()->withoutGlobalScopes()->whereKey($version->entry_id)->value('site_id'));
            }
        };
        EntryVersion::saved($versionChange);
        EntryVersion::deleted($versionChange);
        Event::listen(VersionPublished::class, static function (VersionPublished $event): void {
            $siteId = Entry::query()->withoutGlobalScopes()->whereKey($event->version->entry_id)->value('site_id');
            if (is_numeric($siteId) && (bool) config('basalto.cache.pages', true)) {
                WarmPageCache::dispatch((int) $siteId, $event->version->entry_id);
            }
        });
        // TB 91: Eine Unterdomain antwortet jetzt über https — Crawler kommen mit dem Zertifikat; was bis dahin aus dem
        // Cache fiel, rendert der Job vor, schon Gecachtes kostet ihn nur einen Treffer
        Event::listen(HostnameReady::class, static function (HostnameReady $event): void {
            if ((bool) config('basalto.cache.pages', true)) {
                WarmPageCache::dispatch((int) $event->domain->site_id);
            }
        });
    }

    private static function invalidate(mixed $siteId): void
    {
        if (is_numeric($siteId)) {
            app(PageCache::class)->invalidate((int) $siteId);
        }
    }
}
