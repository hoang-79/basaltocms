<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Legal\Cancellation\ContractCancellations;
use Illuminate\Console\Command;

/**
 * CR-011 TB 59: vorgemerkte Kündigungen aus «Verträge hier kündigen» ausführen, sobald das nächste Ende des Vertrags das
 * Wunschdatum erreicht — täglich, idempotent.
 */
final class CancellationsCommand extends Command
{
    protected $signature = 'basalto:kuendigungen';

    protected $description = 'Vorgemerkte Kündigungen ausführen, sobald das Wunschdatum erreicht ist';

    public function handle(ContractCancellations $cancellations): int
    {
        $applied = $cancellations->applyScheduled();
        $this->info(sprintf('%d vorgemerkte Kündigung(en) ausgeführt.', $applied));

        return self::SUCCESS;
    }
}
