<?php

declare(strict_types=1);

namespace Basalto\Lava\Runtime;

use Closure;

/**
 * Die Grenzen der Logik (R-99, ADR-013): höchstens 10 000 Schleifendurchläufe je Rendern, 2 Sekunden Rechenzeit,
 * Verschachtelung (Einbindung, Makro, Schleife, Bedingung) bis Tiefe 8. Darüber bricht das Rendern mit Fehler ab.
 *
 * Die Zeit ist Rechenzeit (CPU) des PHP-Prozesses ab dem ersten Lava-Lauf (TB 90) — wie `max_execution_time` von PHP
 * unter Linux. Wartet der Prozess auf einem geteilten Host auf die CPU, auf die Datenbank oder die Platte, zählt das
 * nicht: Ein Lastschub darf eine Seite langsam machen, aber nicht zum Fehler. Was vor dem ersten Lauf geschieht
 * (Felder aufbereiten, Theme laden), gehört nicht zur Logik des Themes. Ohne `getrusage` gilt die Wanduhr.
 */
final class Limits
{
    private int $iterations = 0;

    private int $depth = 0;

    /** Stand der Uhr beim ersten Lava-Lauf — null, solange keiner begonnen hat. */
    private ?float $startedAt = null;

    /** @var Closure(): float Sekunden; ohne Angabe die Rechenzeit des Prozesses ({@see self::cpuSeconds()}) */
    private readonly Closure $clock;

    /** @param  (Closure(): float)|null  $clock  nur für Tests: eine eigene Uhr in Sekunden */
    public function __construct(
        public readonly int $maxIterations = 10_000,
        public readonly float $maxSeconds = 2.0,
        public readonly int $maxDepth = 8,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? self::cpuSeconds(...);
    }

    /**
     * Beginn eines Lava-Laufs: Die Frist läuft ab dem ersten; weitere Läufe derselben Seite (Blöcke, Navigationen,
     * Layout) teilen sie.
     */
    public function begin(): void
    {
        $this->startedAt ??= ($this->clock)();
    }

    public function iteration(int $line): void
    {
        if (++$this->iterations > $this->maxIterations) {
            throw new LavaRuntimeException(sprintf('Grenze erreicht: mehr als %d Schleifendurchläufe', $this->maxIterations), $line);
        }
        if (($this->iterations % 64) === 0) {
            $this->checkTime($line);
        }
    }

    public function enter(int $line): void
    {
        if (++$this->depth > $this->maxDepth) {
            throw new LavaRuntimeException(sprintf('Grenze erreicht: Verschachtelung tiefer als %d', $this->maxDepth), $line);
        }
        $this->checkTime($line);
    }

    public function leave(): void
    {
        $this->depth--;
    }

    /** Die Zeitgrenze gilt für den ganzen Lauf — auch ohne Schleife, etwa bei teuren Funktionen. */
    public function checkTime(int $line): void
    {
        // Ein Kontext ohne Renderer::render() (Teile der Module) beginnt mit der ersten Prüfung
        $startedAt = $this->startedAt ??= ($this->clock)();
        if (($this->clock)() - $startedAt > $this->maxSeconds) {
            throw new LavaRuntimeException(sprintf('Grenze erreicht: Renderzeit über %.1f s', $this->maxSeconds), $line);
        }
    }

    /** Rechenzeit des Prozesses in Sekunden (Benutzer + System); ohne `getrusage` (gesperrt) die Wanduhr. */
    public static function cpuSeconds(): float
    {
        $usage = function_exists('getrusage') ? getrusage() : false;
        if (! is_array($usage) || ! isset($usage['ru_utime.tv_sec'], $usage['ru_utime.tv_usec'], $usage['ru_stime.tv_sec'], $usage['ru_stime.tv_usec'])) {
            return hrtime(true) / 1e9;
        }

        return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    public function depth(): int
    {
        return $this->depth;
    }
}
