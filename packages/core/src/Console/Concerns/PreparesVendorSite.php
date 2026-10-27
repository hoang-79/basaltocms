<?php

declare(strict_types=1);

namespace Basalto\Core\Console\Concerns;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Database\Seeders\BasaltoSiteSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Die Site des Herstellers auf einem eigenen Host einrichten — gemeinsam für die lokale Landschaft unter Herd
 * (`basalto:local`) und die Testumgebung auf cyon (`basalto:testing`, TB 64): Site anlegen, Host zur primären Domain
 * machen, Inhalte einspielen, Doku auffrischen und die Adressen der Produktion in den Inhalten umschreiben.
 *
 * @mixin Command
 */
trait PreparesVendorSite
{
    /** Die Site des Herstellers — angelegt (BasaltoSiteSeeder), falls sie fehlt. */
    private function vendorSite(): Site
    {
        $site = Site::query()->where('handle', BasaltoSiteSeeder::HANDLE)->first();
        if ($site === null) {
            Artisan::call('db:seed', ['--class' => BasaltoSiteSeeder::class, '--force' => true]);
            $site = Site::query()->where('handle', BasaltoSiteSeeder::HANDLE)->firstOrFail();
            $this->components->twoColumnDetail('Hersteller-Site', sprintf('«%s» angelegt', $site->handle));
        } else {
            $this->components->twoColumnDetail('Hersteller-Site', sprintf('«%s» vorhanden', $site->handle));
        }

        return $site;
    }

    /** Den Host zur primären Domain der Site machen; false, wenn er einer anderen Site gehört. */
    private function makePrimaryHost(Site $site, string $host): bool
    {
        if (SiteDomain::query()->where('host', $host)->where('site_id', '!=', $site->id)->exists()) {
            $this->error(sprintf('%s gehört schon einer anderen Site — dort entfernen und erneut ausführen.', $host));

            return false;
        }
        DB::transaction(static function () use ($site, $host): void {
            SiteDomain::query()->where('site_id', $site->id)->where('host', '!=', $host)->update(['is_primary' => false]);
            SiteDomain::query()->updateOrCreate(['site_id' => $site->id, 'host' => $host], ['kind' => 'custom', 'is_primary' => true, 'verified_at' => now()]);
        });
        $this->components->twoColumnDetail('Domain', 'https://'.$host.' (primär)');

        return true;
    }

    /** Startinhalte einspielen, solange die Site keine hat, und die Doku aus docs/ auffrischen. */
    private function fillContent(Site $site, bool $publish): void
    {
        // Seiten zählen — die Doku (Typ «doc») legt der Seeder schon an, sie ersetzt keine Startinhalte
        $hasContent = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('type', '!=', 'doc')->exists();
        if (! $hasContent && $publish) {
            Artisan::call('basalto:publish', ['site' => $site->handle]);
            $this->components->twoColumnDetail('Inhalte', 'aus dem Seed des Themes eingespielt');
        }

        // Die Doku aus docs/ auffrischen (Typ «doc» im Theme basalto) — vor dem Umschreiben der Adressen
        if (Artisan::call('basalto:docs-import', ['site' => $site->handle]) === 0) {
            $this->components->twoColumnDetail('Doku', trim(collect(explode("\n", Artisan::output()))->filter()->last() ?? ''));
        }
        $demo = self::pointDemoLinks($site);
        if ($demo > 0) {
            $this->components->twoColumnDetail('Demo-Links', sprintf('%d Fassungen zeigen direkt auf die Demo', $demo));
        }
    }

    /** Wohin die Demo-Links zeigen — lokal macht `basalto:lokal` daraus demo-basalto.test. */
    public const DEMO_URL = 'https://demo.basaltocms.com';

    /**
     * Knöpfe auf die Zwischenseite /demo zeigen direkt auf die Demo, in der Sprache der Seite (Hoang, 27.10.2026: «der
     * link von diesem button ist falsch … es müsste demo.basaltocms.com sein») — wie der Menüpunkt «Demo».
     */
    public static function pointDemoLinks(Site $site): int
    {
        $changed = 0;
        $entryIds = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->pluck('id');
        EntryVersion::query()->whereIn('entry_id', $entryIds)->orderBy('id')->each(static function (EntryVersion $version) use (&$changed): void {
            $data = $version->data;
            $pointed = self::pointDemo($data);
            if ($pointed !== $data) {
                $version->forceFill(['data' => $pointed])->saveQuietly();
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function pointDemo(array $data): array
    {
        if (is_string($data['href'] ?? null) && preg_match('#^(?:/(en|fr|it))?/demo/?$#', $data['href'], $match) === 1) {
            $data['href'] = self::DEMO_URL.(($match[1] ?? '') !== '' ? '/'.$match[1] : '');
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::pointDemo($value);
            }
        }

        return $data;
    }

    /**
     * Ersetzt die Adressen in allen Fassungen der Site und in den Zielen ihrer Weiterleitungen — nur in Zeichenketten,
     * Struktur bleibt.
     *
     * @param  array<string, string>  $map
     */
    private function localizeLinks(Site $site, array $map): int
    {
        $changed = 0;
        $entryIds = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->pluck('id');
        EntryVersion::query()->whereIn('entry_id', $entryIds)->orderBy('id')->each(static function (EntryVersion $version) use ($map, &$changed): void {
            $data = $version->data;
            $localized = self::replaceIn($data, $map);
            if ($localized !== $data) {
                $version->forceFill(['data' => $localized])->saveQuietly();
                $changed++;
            }
        });
        Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNotNull('redirect_to')->orderBy('id')->each(static function (Entry $entry) use ($map, &$changed): void {
            $target = $entry->redirect_to;
            $localized = is_array($target) ? self::replaceIn($target, $map) : $target;
            if ($localized !== $target) {
                $entry->forceFill(['redirect_to' => $localized])->saveQuietly();
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $map
     * @return array<array-key, mixed>
     */
    private static function replaceIn(array $data, array $map): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = strtr($value, $map);
            } elseif (is_array($value)) {
                $data[$key] = self::replaceIn($value, $map);
            }
        }

        return $data;
    }
}
