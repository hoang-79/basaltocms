<?php

declare(strict_types=1);

namespace Basalto\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * E-Mail-Adresse im üblichen Format «name@firma.ch» (Wunsch Hoang 15.11.2026): ein Teil vor dem «@», eine Domain mit
 * Punkt und einer Endung aus mindestens zwei Buchstaben, ohne Leerzeichen und ohne doppelte Punkte. Laravels `email`
 * (RFC) lässt «anna@firma» oder «anna@firma.c» durch — Adressen, an die nie eine Mail ankommt. Domains mit Umlauten
 * («müller.ch») sind erlaubt. Keine DNS-Abfrage: Die Prüfung bleibt schnell und ohne Netz.
 */
final class EmailAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }
        if (! is_string($value) || ! self::valid($value)) {
            $fail(__('Bitte eine E-Mail-Adresse im Format name@firma.ch angeben.'));
        }
    }

    /** Hat die Adresse das übliche Format? Leerzeichen am Rand zählen nicht. */
    public static function valid(string $address): bool
    {
        $address = trim($address);
        if ($address === '' || mb_strlen($address) > 254 || preg_match('/\s/u', $address) === 1 || substr_count($address, '@') !== 1) {
            return false;
        }
        [$local, $domain] = explode('@', $address);
        if ($local === '' || mb_strlen($local) > 64 || preg_match("/^[\\p{L}\\p{N}!#$%&'*+\\/=?^_`{|}~-]+(\\.[\\p{L}\\p{N}!#$%&'*+\\/=?^_`{|}~-]+)*$/u", $local) !== 1) {
            return false;
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (preg_match('/^[\p{L}\p{N}]([\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?$/u', $label) !== 1) {
                return false;
            }
        }
        $tld = (string) end($labels);
        if (preg_match('/^(\p{L}{2,63}|xn--[a-z0-9-]{2,59})$/iu', $tld) !== 1) {
            return false;
        }
        // Umlaut-Domains in ihrer ASCII-Form prüfen lassen, wo die Erweiterung intl da ist
        if (preg_match('/[^\x00-\x7F]/', $domain) === 1 && function_exists('idn_to_ascii')) {
            return idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) !== false;
        }

        return true;
    }
}
