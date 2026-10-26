<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Illuminate\Support\Str;

/**
 * Prüfung eines gewünschten Alias (G-11, D-02): 3–40 Zeichen aus `a–z`, `0–9` und Bindestrich, nicht reserviert
 * (`basalto.saas.reserved_aliases`), noch frei — weder als Handle einer Site noch als Host einer Domain (auch nicht als
 * Umleitung eines alten Alias). Ist er vergeben, schlägt die Prüfung einen freien vor, zuerst aus dem Site-Namen.
 *
 * @phpstan-type Result array{state: string, alias: string, host: string, message: string, suggestion: string|null}
 */
final class AliasCheck
{
    public const FREE = 'free';

    public const TAKEN = 'taken';

    public const RESERVED = 'reserved';

    public const INVALID = 'invalid';

    /** @return Result */
    public static function check(string $name, ?string $siteName = null): array
    {
        $alias = strtolower(trim($name));
        $host = $alias.'.'.Saas::aliasDomain();
        if (! self::valid($alias)) {
            return ['state' => self::INVALID, 'alias' => $alias, 'host' => $host, 'suggestion' => self::suggest($siteName ?? $alias),
                'message' => __('3–40 Zeichen: Kleinbuchstaben a–z, Ziffern und Bindestrich, nicht am Anfang oder Ende.')];
        }
        if (SiteDomain::isReservedAlias($alias)) {
            return ['state' => self::RESERVED, 'alias' => $alias, 'host' => $host, 'suggestion' => self::suggest($siteName ?? $alias, $alias),
                'message' => __(':alias ist reserviert.', ['alias' => $alias])];
        }
        if (LocalAliasFolders::occupied($alias)) {
            return ['state' => self::TAKEN, 'alias' => $alias, 'host' => $host, 'suggestion' => self::suggest($siteName ?? $alias, $alias),
                'message' => __('Im lokalen Projektordner gibt es «:alias» schon — wählen Sie einen anderen Alias.', ['alias' => $alias])];
        }
        if (! self::isFree($alias)) {
            $suggestion = self::suggest($siteName ?? $alias, $alias);

            return ['state' => self::TAKEN, 'alias' => $alias, 'host' => $host, 'suggestion' => $suggestion,
                'message' => $suggestion === null ? __(':alias ist schon vergeben.', ['alias' => $alias]) : __(':alias ist schon vergeben — Vorschlag: :suggestion', ['alias' => $alias, 'suggestion' => $suggestion])];
        }

        return ['state' => self::FREE, 'alias' => $alias, 'host' => $host, 'suggestion' => null, 'message' => __(':host ist frei ✓', ['host' => $host])];
    }

    /** Nur die Form: 3–40 Zeichen, a–z, 0–9, Bindestrich nicht am Rand und nicht doppelt. */
    public static function valid(string $alias): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $alias) === 1 && ! str_contains($alias, '--');
    }

    public static function isFree(string $alias): bool
    {
        return ! Site::query()->where('handle', $alias)->exists()
            && ! SiteDomain::query()->where('host', $alias.'.'.Saas::aliasDomain())->exists()
            && ! LocalAliasFolders::occupied($alias);
    }

    /** Ein freier, gültiger Alias aus einem Text (Site-Name oder gewünschter Alias) — oder null. */
    public static function suggest(string $text, ?string $taken = null): ?string
    {
        $base = self::slug($text);
        $candidates = [];
        if ($base !== '' && $base !== $taken) {
            $candidates[] = $base;
        }
        // Rechtsform am Ende weglassen: «müller-garten-ag» → «mueller-garten»
        $short = (string) preg_replace('/-(ag|gmbh|sa|sarl|sagl|kg|klg|eg|ev|verein|ltd|inc|llc)$/', '', $base);
        if ($short !== $base && $short !== '' && $short !== $taken) {
            array_unshift($candidates, $short);
        }
        $stem = substr($taken ?? ($short !== '' ? $short : $base), 0, 37);
        for ($i = 2; $i <= 9; $i++) {
            $candidates[] = rtrim($stem, '-').'-'.$i;
        }
        foreach ($candidates as $candidate) {
            if (self::valid($candidate) && ! SiteDomain::isReservedAlias($candidate) && self::isFree($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Text → Alias-Form: Umlaute als ae/oe/ue, nur a–z, 0–9 und Bindestrich, höchstens 40 Zeichen. */
    public static function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return trim(substr(Str::slug($text), 0, 40), '-');
    }
}
