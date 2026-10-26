<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\License\LicenseStatus;
use Basalto\Core\Marketplace\MarketplaceClient;
use Basalto\Core\Models\SiteTheme;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Was ist aktuell? (CR-007, U-02/U-03/U-09/U-10) — Kern gegen das Release-Manifest des Herstellers, Plugins und Themes
 * gegen den Katalog des Marktplatzes, dazu der Update-Anspruch der Lizenz. Das Ergebnis bleibt sechs Stunden im Cache
 * (ein Fehler fünf Minuten); «Jetzt prüfen» holt es neu. Die Seite und die Zahl im Menü lesen nur den Cache.
 *
 * @phpstan-import-type Release from ReleaseManifest
 *
 * @phpstan-type Item array{name: string, title: string, installed: string|null, available: string|null, newer: bool}
 * @phpstan-type ThemeItem array{site_id: int, site: string, theme: string, name: string, installed: string|null, available: string|null, newer: bool}
 * @phpstan-type Result array{checked_at: string, source: string, stage: string, error: string|null, marketplace_error: string|null, core: array{installed: string, known: bool, available: string|null, newer: bool, size: int|null, notes: list<array{version: string, date: string|null, notes: string}>}, plugins: list<Item>, themes: list<ThemeItem>, updates_until: string|null, updates_expired: bool, count: int}
 */
final class UpdateCheck
{
    public const CACHE = 'basalto.updates.check';

    public function __construct(private readonly ReleaseManifest $manifest, private readonly MarketplaceClient $marketplace) {}

    /** @return Result|null der letzte Stand ohne Netz — null, wenn noch nie geprüft */
    public function cached(): ?array
    {
        $cached = Cache::get(self::CACHE);

        /** @var Result|null */
        return is_array($cached) && isset($cached['core'], $cached['count']) ? $cached : null;
    }

    /** @return Result */
    public function run(bool $fresh = false): array
    {
        if (! $fresh && ($cached = $this->cached()) !== null) {
            return $cached;
        }
        $installation = Installation::current();
        $installed = $installation->version();
        $error = null;
        $release = null;
        try {
            $release = $this->manifest->fetch();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        $catalog = $this->marketplace->catalog($fresh);
        $latest = [];
        foreach ($catalog['entries'] as $entry) {
            $latest[$entry['kind'].':'.$entry['name']] = $entry;
        }
        $plugins = [];
        foreach ($installation->plugins() as $name => $version) {
            $entry = $latest['plugin:'.$name] ?? null;
            $plugins[] = ['name' => $name, 'title' => $entry['title'] ?? $name, 'installed' => $version !== '' ? $version : null, 'available' => $entry['version'] ?? null,
                'newer' => $entry !== null && Versions::newer($entry['version'], $version)];
        }
        $themes = [];
        foreach (SiteTheme::query()->withoutGlobalScopes()->with('site')->where('origin', 'marktplatz')->whereNotNull('origin_name')->orderBy('site_id')->orderBy('name')->get() as $theme) {
            $entry = $latest['theme:'.$theme->origin_name] ?? null;
            $themes[] = ['site_id' => (int) $theme->site_id, 'site' => (string) ($theme->site->name ?? ''), 'theme' => (string) $theme->name, 'name' => (string) $theme->origin_name,
                'installed' => $theme->version, 'available' => $entry['version'] ?? null, 'newer' => $entry !== null && Versions::newer($entry['version'], $theme->version)];
        }
        $key = LicenseStatus::current()->key;
        // Ein Git-Stand trägt keinen Build-Stempel: er ist nur hinten, wenn die eigentliche Version neuer ist
        $coreNewer = $release !== null && ($installation->mode() === 'git'
            ? Versions::newer(Versions::base($release['version']), Versions::base($installed))
            : Versions::newer($release['version'], $installed) || (! $installation->knowsVersion() && $release['version'] !== $installed));
        $notes = $release === null ? [] : array_values(array_filter($release['notes'], static fn (array $n): bool => Versions::newer($n['version'], $installed)));
        $result = [
            'checked_at' => now()->toIso8601String(), 'source' => UpdateSource::url(), 'stage' => UpdateSource::stage(), 'error' => $error,
            'marketplace_error' => $catalog['ok'] ? null : $catalog['error'],
            'core' => ['installed' => $installed, 'known' => $installation->knowsVersion(), 'available' => $release['version'] ?? null, 'newer' => $coreNewer,
                'size' => $release['size'] ?? null, 'notes' => $notes],
            'plugins' => $plugins, 'themes' => $themes,
            'updates_until' => $key?->updatesUntil?->format('d.m.Y'), 'updates_expired' => $key !== null && $key->updatesExpired(),
        ];
        $result['count'] = (int) $coreNewer + count(array_filter($plugins, static fn (array $p): bool => $p['newer'])) + count(array_filter($themes, static fn (array $t): bool => $t['newer']));
        Cache::put(self::CACHE, $result, $error === null ? 6 * 3600 : 300);

        return $result;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE);
    }
}
