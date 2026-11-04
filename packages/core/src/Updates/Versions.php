<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

/** Versionen vergleichen (CR-007): `1.2.0`, `1.0.0-dev.202610231200` — ein führendes «v» zählt nicht. */
final class Versions
{
    public static function newer(?string $available, ?string $installed): bool
    {
        if ($available === null || $available === '') {
            return false;
        }
        if ($installed === null || $installed === '') {
            return true;
        }

        return version_compare(self::normalize($available), self::normalize($installed), '>');
    }

    /** Nur die eigentliche Version — `1.2.0-dev.202610231200` → `1.2.0` (für Git-Stände, die keinen Build-Stempel tragen). */
    public static function base(string $version): string
    {
        return (string) preg_replace('/[-+].*$/', '', self::normalize($version));
    }

    public static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /**
     * Erfüllt eine Version eine Bedingung wie in Composer? (CR-018: `require.basalto/core` eines Plugins.) Unterstützt
     * `*`, `1.3.2`, `1.3.*`, `^1.3`, `~1.3`, `>=`, `>`, `<=`, `<`, `!=`; «und» mit Leerzeichen oder Komma, «oder» mit
     * `||`. Verglichen wird die eigentliche Version (`1.3.25-dev…` zählt als `1.3.25`). Unbekanntes erfüllt nie.
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::base($version);
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $parts = preg_split('/\s*,\s*|\s+/', trim($alternative)) ?: [];
            $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
            if ($parts === []) {
                continue;
            }
            $all = true;
            foreach ($parts as $part) {
                if (! self::matches($version, $part)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $version, string $part): bool
    {
        if ($part === '*' || $part === 'x') {
            return true;
        }
        if (preg_match('/^(\^|~|>=|<=|>|<|!=|==|=)?\s*v?([0-9]+(?:\.(?:[0-9]+|\*|x)){0,3})$/i', $part, $m) !== 1) {
            return false;
        }
        $operator = $m[1];
        $target = $m[2];
        $numbers = explode('.', $target);
        // Platzhalter «1.3.*»: ab 1.3.0, unter 1.4.0
        $wild = array_search('*', $numbers, true);
        $wild = $wild === false ? array_search('x', array_map('strtolower', $numbers), true) : $wild;
        if ($wild !== false && $operator === '') {
            $fixed = array_slice($numbers, 0, (int) $wild);
            if ($fixed === []) {
                return true;
            }
            $upper = $fixed;
            $upper[count($upper) - 1] = (string) ((int) end($upper) + 1);

            return version_compare($version, implode('.', $fixed), '>=') && version_compare($version, implode('.', $upper), '<');
        }
        $lower = implode('.', array_pad($numbers, 3, '0'));
        switch ($operator) {
            case '^':
                // ^1.3 → >=1.3.0 <2.0.0; ^0.3 → >=0.3.0 <0.4.0
                $major = (int) $numbers[0];
                $upper = $major > 0 || count($numbers) === 1 ? ($major + 1).'.0.0' : '0.'.((int) ($numbers[1] ?? 0) + 1).'.0';

                return version_compare($version, $lower, '>=') && version_compare($version, $upper, '<');
            case '~':
                // ~1.3 → >=1.3.0 <2.0.0; ~1.3.2 → >=1.3.2 <1.4.0
                $upper = count($numbers) <= 2 ? ((int) $numbers[0] + 1).'.0.0' : $numbers[0].'.'.((int) $numbers[1] + 1).'.0';

                return version_compare($version, $lower, '>=') && version_compare($version, $upper, '<');
            case '>=':
            case '<=':
            case '>':
            case '<':
            case '!=':
                return version_compare($version, $lower, $operator);
            default:
                return version_compare($version, $lower, '==');
        }
    }
}
