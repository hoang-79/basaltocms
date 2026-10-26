<?php

declare(strict_types=1);

namespace Basalto\Core\Jobs;

use Basalto\Core\Cloud\CloudCli;
use Basalto\Core\Cloud\CloudCliException;
use Basalto\Core\Models\SiteDomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Legt eine Kundendomain auf Laravel Cloud an (`cloud domain:create`) und merkt sich Kennung, Status und DNS-Einträge. */
final class AttachSiteDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $siteDomainId) {}

    public function handle(CloudCli $cloud): void
    {
        $domain = SiteDomain::query()->findOrFail($this->siteDomainId);
        try {
            $result = $cloud->domainCreate($domain->host, wildcard: false);
        } catch (CloudCliException $e) {
            $domain->forceFill(['cloud_status' => 'failed', 'last_error' => $e->getMessage()])->save();
            throw $e;
        }
        $domain->forceFill([
            'cloud_domain_id' => isset($result['id']) ? (string) $result['id'] : null,
            'cloud_status' => isset($result['status']) ? (string) $result['status'] : 'pending',
            'cloud_response' => $result,
            'last_error' => null,
        ])->save();
    }
}
