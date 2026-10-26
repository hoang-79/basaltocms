<?php

declare(strict_types=1);

namespace Basalto\Core\Invoices;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Fortlaufende Rechnungsnummern je Jahr (TB 11 im Lizenzverkauf, seit TB 12 Scheibe 2 im Kern): die höchste laufende
 * Nummer des Nummernkreises unter Sperre lesen, eins dazuzählen, anlegen — in einer Transaktion. Den Rest hält der
 * eindeutige Index der Tabelle: legen zwei Läufe zugleich an, versucht es der zweite bis zu zweimal neu.
 */
final class InvoiceSequence
{
    /**
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $scope  Abfrage auf den Nummernkreis (z. B. Site und Jahr)
     * @param  Closure(int): TModel  $create  legt die Rechnung mit der nächsten laufenden Nummer an
     * @return TModel
     */
    public static function issue(Closure $scope, Closure $create): Model
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(static function () use ($scope, $create): Model {
                    $last = $scope()->orderByDesc('sequence')->lockForUpdate()->value('sequence');

                    return $create((is_numeric($last) ? (int) $last : 0) + 1);
                });
            } catch (QueryException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }
}
