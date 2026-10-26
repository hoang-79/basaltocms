<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Saas\Move\InstanceMover;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** TB 12 Scheibe 4: Umzug auf dem Ziel — Archiv holen, Konto übernehmen, Quelle benachrichtigen ({@see InstanceMover::import()}). */
final class ReceiveInstanceMove implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $moveId) {}

    public function handle(InstanceMover $mover): void
    {
        $move = InstanceMove::query()->find($this->moveId);
        if ($move !== null) {
            $mover->import($move);
        }
    }
}
