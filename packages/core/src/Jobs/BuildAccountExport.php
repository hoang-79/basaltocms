<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Models\AccountExport;
use Basalto\Core\Saas\Offboarding\AccountExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** TB 12 Scheibe 4: einen Export aus dem Konto-Bereich bauen (je Site oder ganzes Konto) — siehe {@see AccountExports}. */
final class BuildAccountExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $exportId) {}

    public function handle(AccountExports $exports): void
    {
        $export = AccountExport::query()->find($this->exportId);
        if ($export !== null) {
            $exports->build($export);
        }
    }

    public function failed(?Throwable $e): void
    {
        AccountExport::query()->whereKey($this->exportId)->whereIn('status', [AccountExport::QUEUED, AccountExport::RUNNING])
            ->update(['status' => AccountExport::FAILED, 'error' => $e?->getMessage()]);
    }
}
