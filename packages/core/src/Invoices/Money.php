<?php

declare(strict_types=1);

namespace Basalto\Core\Invoices;

/**
 * Beträge in Rappen/Cent ausgeben (TB 11, seit TB 12 Scheibe 2 im Kern für Lizenzverkauf und SaaS): «CHF 1'190.00» in
 * Deutsch, Französisch und Italienisch (Schweizer Schreibweise), «CHF 1,190.00» in Englisch; kurz ohne Nachkommastellen,
 * wenn sie null sind («CHF 249»). Und die MwSt., die in einem Bruttobetrag steckt.
 */
final class Money
{
    public static function format(int $cents, string $currency, string $locale = 'de', bool $short = false): string
    {
        $decimals = $short && $cents % 100 === 0 ? 0 : 2;
        $thousands = $locale === 'en' ? ',' : "'";

        return $currency.' '.number_format($cents / 100, $decimals, '.', $thousands);
    }

    /** Die MwSt. in einem Bruttobetrag; Satz in Basispunkten (810 = 8,1 %). */
    public static function includedVat(int $gross, int $rateBasisPoints): int
    {
        if ($rateBasisPoints <= 0) {
            return 0;
        }

        return (int) round($gross * $rateBasisPoints / (10000 + $rateBasisPoints));
    }

    /** «8,1 %» bzw. «8.1 %» in Englisch. */
    public static function rate(int $rateBasisPoints, string $locale = 'de'): string
    {
        $text = rtrim(rtrim(number_format($rateBasisPoints / 100, 2, '.', ''), '0'), '.');

        return ($locale === 'en' ? $text : str_replace('.', ',', $text)).' %';
    }
}
