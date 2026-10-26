<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Cloud\CloudCliException;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Domains;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stösst die Prüfung einer angelegten Domain an (`cloud domain:verify`) und setzt `verified_at`, sobald sie verbunden ist.
 * TB 12 Scheibe 3: über {@see Domains::verify()} — verbunden heisst dann auch Hauptadresse und Mail. Ein Fehler der CLI
 * bleibt an der Domain (im Reiter sichtbar) und geht ins Fehlerprotokoll (Fehleralarm), ohne den Job scheitern zu lassen:
 * Der Scheduler prüft ohnehin alle zehn Minuten wieder.
 */
final class VerifySiteDomain implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $siteDomainId) {}

    public function handle(Domains $domains): void
    {
        $domain = SiteDomain::query()->find($this->siteDomainId);
        if ($domain === null) {
            return;
        }
        try {
            $domains->verify($domain);
        } catch (CloudCliException $e) {
            report($e);
        }
    }
}
