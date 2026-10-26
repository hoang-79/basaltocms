<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Community\CommunityGroupRule;
use Basalto\Core\Community\CommunityTrophy;
use Basalto\Core\Community\Recognition;
use Basalto\Core\Models\Site;
use Illuminate\Console\Command;

/**
 * Trophäen vergeben und Gruppen nach Regeln zuordnen (CR-006 FO-20) — für alle Sites mit Regeln oder automatischen
 * Trophäen; läuft täglich um 03:30.
 */
final class CommunityRulesCommand extends Command
{
    protected $signature = 'basalto:community-rules {site? : Kennung der Site}';

    protected $description = 'Vergibt Trophäen und ordnet Mitglieder nach Regeln Gruppen zu';

    public function handle(Recognition $recognition): int
    {
        $handle = $this->argument('site');
        $ids = CommunityTrophy::query()->withoutGlobalScopes()->whereNotNull('rule')->pluck('site_id')
            ->merge(CommunityGroupRule::query()->withoutGlobalScopes()->pluck('site_id'))->unique()->all();
        $sites = Site::query()->whereIn('id', $ids)->when(is_string($handle) && $handle !== '', static fn ($q) => $q->where('handle', $handle))->get();
        foreach ($sites as $site) {
            $result = $recognition->run($site);
            $this->line(sprintf('%s: %d Trophäen vergeben, %d Gruppen beigetreten, %d verlassen.', $site->handle, $result['trophies'], $result['joined'], $result['left']));
        }

        return self::SUCCESS;
    }
}
