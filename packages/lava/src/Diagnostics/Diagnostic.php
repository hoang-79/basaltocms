<?php

declare(strict_types=1);

namespace Basalto\Lava\Diagnostics;

/**
 * Ein Befund des Prüfers: Schwere, Text, Ort und Schritt der roten Linie — mit Vorschlag, wo möglich.
 * Dieselbe Form liefert der Sprachdienst an Editoren und den Backend-Editor.
 */
final class Diagnostic
{
    public const ERROR = 'fehler';

    public const WARNING = 'warnung';

    public function __construct(
        public readonly string $severity,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly int $column,
        public readonly ?string $step = null,
        public readonly ?string $suggestion = null,
    ) {}

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function format(): string
    {
        $step = $this->step ? "[{$this->step}] " : '';
        $suggestion = $this->suggestion ? " — meinten Sie «{$this->suggestion}»?" : '';

        return sprintf('%s%s (%s:%d:%d)%s', $step, $this->message, $this->file, $this->line, $this->column, $suggestion);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity, 'message' => $this->message, 'file' => $this->file,
            'line' => $this->line, 'column' => $this->column, 'step' => $this->step, 'suggestion' => $this->suggestion,
        ];
    }
}
