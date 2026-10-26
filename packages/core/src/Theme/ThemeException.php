<?php

declare(strict_types=1);

namespace Basalto\Core\Theme;

use Basalto\Lava\Diagnostics\Diagnostic;
use RuntimeException;

/** Das Theme einer Site lässt sich nicht kompilieren — mit allen Befunden, damit die Fehlerseite sie zeigen kann (L-13). */
final class ThemeException extends RuntimeException
{
    /** @param  list<Diagnostic>  $diagnostics */
    public function __construct(public readonly string $themeName, public readonly array $diagnostics)
    {
        // Nur der Rahmen folgt der Sprache der Oberfläche; die Befunde sind Teil von Lava und bleiben deutsch
        parent::__construct(__('Theme «:name» hat Fehler: :findings', ['name' => $themeName, 'findings' => implode('; ', array_map(static fn (Diagnostic $d): string => $d->format(), $diagnostics))]));
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->diagnostics);
    }
}
