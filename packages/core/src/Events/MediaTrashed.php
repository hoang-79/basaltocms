<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\Media;

/** Domänenereignis (ADR-011): ein Medium kam in den Papierkorb — mit der Zahl der Inhalte, die es noch einbinden. */
final class MediaTrashed
{
    public function __construct(public readonly Media $media, public readonly int $usages) {}
}
