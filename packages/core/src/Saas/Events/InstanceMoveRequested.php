<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Events;

use Basalto\Core\Models\Account;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ein Planwechsel führt über die Grenze der Instanzen (TB 12 Scheibe 2): Start liegt auf «start», Standard und Pro auf
 * «pro». Die Abrechnung setzt `accounts.instance_target` und meldet dieses Ereignis — umziehen (Export/Import, Alias
 * umleiten) und danach `instance` setzen und `instance_target` leeren gehört zu Scheibe 4.
 */
final class InstanceMoveRequested
{
    use Dispatchable;

    public function __construct(public readonly Account $account, public readonly string $from, public readonly string $to) {}
}
