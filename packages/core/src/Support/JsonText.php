<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/** Eine JSON-Spalte als Text für `LIKE` — PostgreSQL vergleicht `json` nicht mit Text, MySQL braucht eine Umwandlung. */
final class JsonText
{
    /** @param  'data'|'entry_versions.data'|'before'|'after'  $column  nur bekannte Spalten, nie Eingaben */
    public static function column(string $column, bool $lower = false): Expression
    {
        // `before` ist in MySQL ein reserviertes Wort — der Name steht deshalb immer in Anführungszeichen
        $quote = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? '`' : '"';
        $wrapped = match ($column) {
            'entry_versions.data' => $quote.'entry_versions'.$quote.'.'.$quote.'data'.$quote,
            default => $quote.$column.$quote,
        };
        $sql = match (DB::getDriverName()) {
            'pgsql' => $wrapped.'::text',
            'mysql', 'mariadb' => 'CAST('.$wrapped.' AS CHAR)',
            default => $wrapped,
        };

        return DB::raw($lower ? 'LOWER('.$sql.')' : $sql);
    }
}
