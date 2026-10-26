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
}
