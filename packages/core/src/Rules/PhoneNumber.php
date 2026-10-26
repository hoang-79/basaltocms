<?php

declare(strict_types=1);

namespace Basalto\Core\Rules;

use Basalto\Core\Support\Countries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Telefonnummer im gewählten Land (Profil, K-03): national «079 123 45 67» oder international mit «+»/«00» und
 * Landesvorwahl, in der Länge des Landes — die Regeln stehen in `Countries::parsePhone`. Die Meldung nennt das Land,
 * die erwarteten Ziffern und ein Beispiel im Land.
 */
final class PhoneNumber implements ValidationRule
{
    /** Aus seinen ersten Ziffern entsteht das Beispiel in der Meldung (CH: «079 123 45 67»). */
    private const EXAMPLE_DIGITS = '791234567891234';

    public function __construct(private readonly string $country) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '' || Countries::parsePhone($value, $this->country) !== null) {
            return;
        }
        $c = Countries::find($this->country);
        if ($c === null) {
            $fail(__('Bitte eine Telefonnummer mit Landesvorwahl angeben (z. B. +41 79 123 45 67).'));

            return;
        }
        $digits = $c['min'] === $c['max']
            ? __(':count Ziffern', ['count' => $c['min']])
            : __(':min bis :max Ziffern', ['min' => $c['min'], 'max' => $c['max']]);
        $example = ($c['keep_zero'] ? '' : '0').Countries::groupDigits(substr(self::EXAMPLE_DIGITS, 0, $c['min']), $c['groups']);

        $fail(__('Bitte eine gültige Nummer für :country angeben — :digits nach der Vorwahl +:dial (z. B. :example), andere Länder mit «+» und Landesvorwahl.', [
            'country' => $c['name'], 'digits' => $digits, 'dial' => $c['dial'], 'example' => $example,
        ]));
    }
}
