<?php

declare(strict_types=1);

namespace Basalto\Lava\Runtime;

/**
 * Die Grenzen der Logik (R-99, ADR-013): höchstens 10 000 Schleifendurchläufe je Rendern, 2 Sekunden,
 * Verschachtelung (Einbindung, Makro, Schleife, Bedingung) bis Tiefe 8. Darüber bricht das Rendern mit Fehler ab.
 */
final class Limits
{
    private int $iterations = 0;

    private int $depth = 0;

    private readonly float $deadline;

    public function __construct(
        public readonly int $maxIterations = 10_000,
        public readonly float $maxSeconds = 2.0,
        public readonly int $maxDepth = 8,
    ) {
        $this->deadline = microtime(true) + $maxSeconds;
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
        if (microtime(true) > $this->deadline) {
            throw new LavaRuntimeException(sprintf('Grenze erreicht: Renderzeit über %.1f s', $this->maxSeconds), $line);
        }
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
