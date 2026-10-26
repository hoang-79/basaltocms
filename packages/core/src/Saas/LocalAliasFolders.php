<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Models\SiteDomain;

/**
 * SaaS lokal (CR-004, P-08): Unter Laravel Herd liefert ein geparkter Ordner jeden Unterordner als `<ordner>.test` aus.
 * Damit eine Registrierung auf basalto.test sofort unter `<alias>.test` erreichbar ist, legt Basalto im eingestellten
 * Ordner (`BASALTO_SAAS_LOCAL_PATH`, z. B. `~/Development`) einen **Verweis** `<alias>` auf die eigene Installation an —
 * eine Datenbank wie in Produktion. Nur bei `APP_ENV=local`, nur Namen nach den Alias-Regeln, nur Verweise. Entfernt
 * werden ausschliesslich Verweise, die Basalto selbst angelegt hat (Liste in `storage/app/saas-local-links.json`).
 */
final class LocalAliasFolders
{
    public static function enabled(): bool
    {
        $path = self::path();

        return app()->environment('local') && $path !== null && is_dir($path);
    }

    public static function path(): ?string
    {
        $path = config('basalto.saas.local_path');

        return is_string($path) && $path !== '' ? rtrim($path, '/') : null;
    }

    /** Ein vorhandener Ordner oder fremder Verweis dieses Namens — der Alias würde ihn überdecken. */
    public static function occupied(string $alias): bool
    {
        if (! self::enabled() || ! AliasCheck::valid($alias)) {
            return false;
        }
        $target = self::path().'/'.$alias;

        return (file_exists($target) || is_link($target)) && ! self::ours($alias);
    }

    /** Verweis `<pfad>/<alias>` → Installation anlegen (vorhanden und eigen: nichts zu tun). */
    public static function link(string $alias): bool
    {
        if (! self::enabled() || ! AliasCheck::valid($alias) || self::occupied($alias)) {
            return false;
        }
        $target = self::path().'/'.$alias;
        if (! is_link($target) && ! @symlink(base_path(), $target)) {
            return false;
        }
        self::remember($alias, true);

        return true;
    }

    /**
     * Eigene Verweise entfernen, deren Alias keiner Site mehr gehört — weder als Alias noch als Umleitung (Austritt,
     * Ablauf der Umleitung nach einem Alias-Wechsel).
     *
     * @return list<string> entfernte Namen
     */
    public static function prune(): array
    {
        if (! self::enabled()) {
            return [];
        }
        $removed = [];
        foreach (self::registry() as $alias) {
            if (SiteDomain::query()->where('host', $alias.'.'.Saas::aliasDomain())->exists()) {
                continue;
            }
            if (self::ours($alias)) {
                @unlink(self::path().'/'.$alias);
            }
            self::remember($alias, false);
            $removed[] = $alias;
        }

        return $removed;
    }

    /** Lokal ohne Zertifikat je Verweis: Aliasse über http (Herd liefert verlinkte Ordner ohne `herd secure` nur so). */
    public static function scheme(): ?string
    {
        return self::enabled() && Saas::aliasDomain() === 'test' ? 'http' : null;
    }

    private static function ours(string $alias): bool
    {
        $target = self::path().'/'.$alias;
        if (! is_link($target) || ! in_array($alias, self::registry(), true)) {
            return false;
        }
        $points = readlink($target);

        return is_string($points) && realpath($points) === realpath(base_path());
    }

    /** @return list<string> */
    private static function registry(): array
    {
        $file = self::registryFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    private static function remember(string $alias, bool $add): void
    {
        $list = array_values(array_diff(self::registry(), [$alias]));
        if ($add) {
            $list[] = $alias;
            sort($list);
        }
        $file = self::registryFile();
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT)."\n", LOCK_EX);
    }

    private static function registryFile(): string
    {
        $file = config('basalto.saas.local_registry');

        return is_string($file) && $file !== '' ? $file : storage_path('app/saas-local-links.json');
    }
}
