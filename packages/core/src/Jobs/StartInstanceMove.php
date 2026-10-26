<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Saas\Move\InstanceMover;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** TB 12 Scheibe 4: Umzug auf der Quelle — Konto-Archiv bauen und der Zielinstanz melden ({@see InstanceMover::start()}). */
final class StartInstanceMove implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $moveId) {}

    public function handle(InstanceMover $mover): void
    {
        $move = InstanceMove::query()->find($this->moveId);
        if ($move !== null) {
            $mover->start($move);
        }
    }
}
