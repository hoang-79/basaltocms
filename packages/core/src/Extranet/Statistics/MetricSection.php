<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet\Statistics;

use Basalto\Core\Extranet\ExtranetRegistry;

/**
 * Ein Abschnitt der Statistik im Extranet (TB 55): Kennzahlen mit Vergleich zum Zeitraum davor, Verläufe je Tag und
 * Ranglisten. Kern und Module bauen ihn in {@see ExtranetRegistry::statistics()}; die Seite
 * zeigt ihn als Reiter, die Kennzahlen mit `headline` zusätzlich im Überblick. Beträge in Rappen (bzw. Cent).
 *
 * @phpstan-type Format 'number'|'money'|'percent'
 * @phpstan-type Kpi array{label: string, value: int|float, previous: int|float|null, format: Format, currency: string|null, hint: string|null, headline: bool}
 * @phpstan-type Series array{label: string, points: list<array{date: string, value: int}>, summary: 'total'|'max'}
 * @phpstan-type Row array{label: string, value: int|float, hint: string|null, currency: string|null}
 * @phpstan-type Rows array{title: string, rows: list<Row>, format: Format, currency: string|null, empty: string|null}
 * @phpstan-type Section array{key: string, label: string, lead: string|null, kpis: list<Kpi>, series: list<Series>, lists: list<Rows>, link: array{label: string, href: string}|null}
 */
final class MetricSection
{
    /** @var list<Kpi> */
    private array $kpis = [];

    /** @var list<Series> */
    private array $series = [];

    /** @var list<Rows> */
    private array $lists = [];

    private ?string $lead = null;

    /** @var array{label: string, href: string}|null */
    private ?array $link = null;

    public function __construct(public readonly string $key, public readonly string $label) {}

    /** Ein Satz unter dem Titel — woher die Zahlen kommen. */
    public function lead(string $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    /** Verweis auf die Seite, auf der man mit diesen Daten arbeitet (z. B. Lizenzen). */
    public function link(string $label, string $href): self
    {
        $this->link = ['label' => $label, 'href' => $href];

        return $this;
    }

    /** @param  Format  $format */
    public function kpi(string $label, int|float $value, int|float|null $previous = null, string $format = 'number', ?string $hint = null, bool $headline = false, ?string $currency = null): self
    {
        $this->kpis[] = ['label' => $label, 'value' => $value, 'previous' => $previous, 'format' => $format, 'currency' => $format === 'money' ? ($currency ?? 'CHF') : null,
            'hint' => $hint, 'headline' => $headline];

        return $this;
    }

    /**
     * Verlauf je Tag; darüber die Summe — oder der Höchstwert, wenn sich Tage nicht addieren lassen (aktive
     * Installationen je Tag sind jeden Tag dieselben).
     *
     * @param  list<array{date: string, value: int}>  $points
     * @param  'total'|'max'  $summary
     */
    public function series(string $label, array $points, string $summary = 'total'): self
    {
        $this->series[] = ['label' => $label, 'points' => $points, 'summary' => $summary];

        return $this;
    }

    /**
     * @param  iterable<array{label: string, value: int|float, hint?: string|null, currency?: string|null}>  $rows
     * @param  Format  $format
     */
    public function list(string $title, iterable $rows, string $format = 'number', ?string $empty = null, ?string $currency = null): self
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['label' => $row['label'], 'value' => $row['value'], 'hint' => $row['hint'] ?? null, 'currency' => $row['currency'] ?? null];
        }
        $this->lists[] = ['title' => $title, 'rows' => $out, 'format' => $format, 'currency' => $format === 'money' ? ($currency ?? 'CHF') : null, 'empty' => $empty];

        return $this;
    }

    /** @return list<Kpi> */
    public function headlines(): array
    {
        return array_values(array_filter($this->kpis, static fn (array $kpi): bool => $kpi['headline']));
    }

    /** @return Section */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'lead' => $this->lead, 'kpis' => $this->kpis, 'series' => $this->series, 'lists' => $this->lists, 'link' => $this->link];
    }
}
