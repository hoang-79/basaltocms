<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

use Illuminate\Support\Facades\Log;

/**
 * Hinweise von Stripe (Kopfzeile `Stripe-Notice`, etwa «We recommend building your integration using Accounts v2»)
 * gibt stripe-php als PHP-Warnung aus; Laravel macht daraus eine Ausnahme, und eine Anfrage, die bei Stripe gelungen
 * ist oder einen echten Fehler meldet, ginge dahinter verloren. Hier landen sie im Log — andere Warnungen bleiben, wie
 * sie sind.
 */
final class StripeNotices
{
    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function quietly(callable $call): mixed
    {
        $previous = null;
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            if (! str_contains(str_replace('\\', '/', $file), '/stripe/stripe-php/')) {
                return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
            }
            Log::notice('Hinweis von Stripe: '.$message);

            return true;
        }, E_USER_WARNING);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
