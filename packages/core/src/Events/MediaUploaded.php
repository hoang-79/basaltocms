<?php

declare(strict_types=1);

namespace Basalto\Core\Events;

use Basalto\Core\Models\Media;

/** Domänenereignis (ADR-011): ein Medium wurde hochgeladen — Varianten, Kontingente und Protokoll hängen daran. */
final class MediaUploaded
{
    public function __construct(public readonly Media $media) {}
}
