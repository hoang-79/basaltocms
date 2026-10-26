<?php

declare(strict_types=1);

namespace Basalto\Core\Publish;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Jobs\WarmPageCache;
use Basalto\Core\Models\Site;

/**
 * `basalto:publish` (L-06, CR-002 W-16): Startinhalte aus `seed` einspielen (idempotent), prüfen, bei null Fehlern den
 * Seiten-Cache vorwärmen und die Site live schalten. Ohne `--dry-run` schreibt nur ein grüner Lauf; der Probelauf
 * schreibt nie — auch keinen Seed.
 *
 * @phpstan-import-type Finding from PublishChecks
 */
final class Publisher
{
    public function __construct(private readonly SeedImporter $seeds, private readonly PublishChecks $checks, private readonly PageCache $cache, private readonly Recorder $recorder) {}

    /**
     * @return array{seed: array{created: list<string>, kept: list<string>}|null, findings: list<Finding>, counted: array<string, int>, checks: list<string>, errors: int, warnings: int, live: bool, was_live: bool, warmed: int}
     */
    public function run(Site $site, bool $dryRun = false, bool $seed = true): array
    {
        $wasLive = $site->isLive();
        $seeded = $seed && ! $dryRun ? $this->seeds->import($site) : null;
        $report = $this->checks->run($site);
        $errors = count(array_filter($report['findings'], static fn (array $f): bool => $f['severity'] === 'error'));
        $warnings = count($report['findings']) - $errors;
        $warmed = 0;
        $live = $wasLive;
        if (! $dryRun && $errors === 0) {
            $this->cache->invalidate($site->id);
            $warmed = (int) app()->call([new WarmPageCache($site->id), 'handle']);
            if (! $wasLive) {
                $site->forceFill(['live_at' => now()])->save();
            }
            $live = true;
            $this->recorder->record('site.published', $site, null, ['seed' => count($seeded['created'] ?? []), 'warnings' => $warnings, 'warmed' => $warmed, 'first' => ! $wasLive], $site);
        } elseif (! $dryRun) {
            $first = array_values(array_filter($report['findings'], static fn (array $f): bool => $f['severity'] === 'error'))[0];
            $this->recorder->record('site.publish_refused', $site, null, ['errors' => $errors, 'first' => $first['message']], $site);
        }

        return ['seed' => $seeded] + $report + ['errors' => $errors, 'warnings' => $warnings, 'live' => $live, 'was_live' => $wasLive, 'warmed' => $warmed];
    }
}
