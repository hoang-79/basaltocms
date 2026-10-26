<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Der Scheduler-Nachweis: jede Minute ein Zeitstempel im Cache (Valkey auf Cloud) — sichtbar unter /admin/status. */
final class HeartbeatCommand extends Command
{
    public const KEY = 'basalto.heartbeat';

    protected $signature = 'basalto:heartbeat';

    protected $description = 'Schreibt einen Zeitstempel in den Cache (Scheduler-Nachweis)';

    public function handle(): int
    {
        Cache::put(self::KEY, now()->toIso8601String(), now()->addMinutes(10));
        $this->info('Herzschlag '.now()->toIso8601String());

        return self::SUCCESS;
    }
}
