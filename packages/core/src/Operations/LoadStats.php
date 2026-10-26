<?php

declare(strict_types=1);

namespace Basalto\Core\Operations;

/**
 * Auswertung eines Lasttests (N-08): Antwortzeiten in Millisekunden, Statuscodes, Fehler und Cache-Treffer — Perzentile
 * nach der Nearest-Rank-Methode, damit p95 ein tatsächlich gemessener Wert ist.
 */
final class LoadStats
{
    /** @var list<float> */
    private array $durations = [];

    /** @var array<int|string, int> Statuscode (oder «Fehler») → Anzahl */
    private array $statuses = [];

    private int $errors = 0;

    private int $cacheHits = 0;

    public function record(float $milliseconds, int $status, bool $cacheHit = false, ?string $error = null): void
    {
        $this->durations[] = $milliseconds;
        $key = $error !== null ? 'Fehler' : (string) $status;
        $this->statuses[$key] = ($this->statuses[$key] ?? 0) + 1;
        if ($error !== null || $status >= 400 || $status === 0) {
            $this->errors++;
        }
        if ($cacheHit) {
            $this->cacheHits++;
        }
    }

    public function count(): int
    {
        return count($this->durations);
    }

    public function errors(): int
    {
        return $this->errors;
    }

    /** Perzentil nach Nearest Rank (1–100); 0 ohne Messung. */
    public function percentile(float $p): float
    {
        if ($this->durations === []) {
            return 0.0;
        }
        $sorted = $this->durations;
        sort($sorted);
        $rank = (int) ceil($p / 100 * count($sorted));

        return $sorted[max(0, min(count($sorted), $rank) - 1)];
    }

    /**
     * @return array{requests: int, seconds: float, rps: float, errors: int, error_rate: float, cache_hits: int, p50: float, p95: float, p99: float, max: float, statuses: array<int|string, int>}
     */
    public function summary(float $seconds): array
    {
        $n = $this->count();
        ksort($this->statuses);

        return [
            'requests' => $n,
            'seconds' => round($seconds, 2),
            'rps' => $seconds > 0 ? round($n / $seconds, 1) : 0.0,
            'errors' => $this->errors,
            'error_rate' => $n > 0 ? round($this->errors / $n * 100, 2) : 0.0,
            'cache_hits' => $this->cacheHits,
            'p50' => round($this->percentile(50), 1),
            'p95' => round($this->percentile(95), 1),
            'p99' => round($this->percentile(99), 1),
            'max' => round($this->durations === [] ? 0.0 : max($this->durations), 1),
            'statuses' => $this->statuses,
        ];
    }
}
