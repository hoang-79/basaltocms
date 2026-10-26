<?php

declare(strict_types=1);

namespace Basalto\Core\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Zeitraum einer Auswertung (TB 54/55): die letzten 7, 30 oder 90 Tage bis heute in der Zeitzone der Site, dazu der
 * gleich lange Zeitraum davor für den Vergleich. Tageswerte rechnet die Datenbank in dieser Zeitzone; der Abstand in
 * Minuten geht als Parameter mit.
 */
final readonly class Period
{
    /** @var array<string, int> Schlüssel → Tage */
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    private function __construct(public string $range, public int $days, public CarbonImmutable $from, public CarbonImmutable $to, public int $offset) {}

    public static function make(mixed $range, string $zone): self
    {
        $range = self::key($range);
        $days = self::RANGES[$range];
        $now = CarbonImmutable::now($zone);

        return new self($range, $days, $now->startOfDay()->subDays($days - 1), $now->endOfDay(), (int) $now->utcOffset());
    }

    public static function key(mixed $range): string
    {
        return is_string($range) && array_key_exists($range, self::RANGES) ? $range : '7d';
    }

    /** Der gleich lange Zeitraum unmittelbar davor. */
    public function previous(): self
    {
        $to = $this->from->subSecond();

        return new self($this->range, $this->days, $to->startOfDay()->subDays($this->days - 1), $to, $this->offset);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} Grenzen in UTC für `whereBetween` */
    public function between(): array
    {
        return [$this->from->utc(), $this->to->utc()];
    }

    /**
     * Anzahl je Tag (Zeitzone der Site), lückenlos über den ganzen Zeitraum.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder|EloquentBuilder<TModel>  $query  schon auf Site und Zeitraum eingeschränkt
     * @param  literal-string  $column
     * @return list<array{date: string, value: int}>
     */
    public function daily(Builder|EloquentBuilder $query, string $column = 'created_at'): array
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $rows = $base->selectRaw(self::localDate($column).' as d', [$this->offset])->selectRaw('COUNT(*) as n')->groupBy('d')->get()
            ->keyBy(static fn (object $row): string => substr((string) $row->d, 0, 10));
        $counts = [];
        foreach ($rows as $date => $row) {
            $counts[$this->clamp((string) $date)] = ($counts[$this->clamp((string) $date)] ?? 0) + (int) $row->n;
        }
        $series = [];
        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $series[] = ['date' => $day->toDateString(), 'value' => $counts[$day->toDateString()] ?? 0];
        }

        return $series;
    }

    /**
     * Tag am Rand: Der Abstand zu UTC gilt für den ganzen Zeitraum (der von heute). Über eine Zeitumstellung rutscht
     * ein Eintrag der ersten oder letzten Stunde auf den Tag daneben — er zählt dann zum ersten bzw. letzten Tag, so
     * stimmt die Summe mit den Kennzahlen.
     */
    public function clamp(string $date): string
    {
        return max($this->from->toDateString(), min($this->to->toDateString(), $date));
    }

    /**
     * Datum einer Spalte in der Zeitzone der Site — je Datenbank anders geschrieben; der Abstand in Minuten ist der
     * Parameter `?`.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function localDate(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'DATE('.$column.", ? || ' minutes')",
            'pgsql' => 'CAST('.$column." + (CAST(? AS INTEGER) * INTERVAL '1 minute') AS DATE)",
            default => 'DATE(DATE_ADD('.$column.', INTERVAL ? MINUTE))',
        };
    }

    /**
     * Stunde einer Spalte in der Zeitzone der Site (0–23).
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function localHour(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', ".$column.", ? || ' minutes') AS INTEGER)",
            'pgsql' => 'CAST(EXTRACT(HOUR FROM '.$column." + (CAST(? AS INTEGER) * INTERVAL '1 minute')) AS INTEGER)",
            default => 'HOUR(DATE_ADD('.$column.', INTERVAL ? MINUTE))',
        };
    }
}
