<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\Media;

/** Domänenereignis (ADR-011): ein Medium wurde aus dem Papierkorb geholt. */
final class MediaRestored
{
    public function __construct(public readonly Media $media) {}
}
