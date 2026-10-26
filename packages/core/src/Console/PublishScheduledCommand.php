<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\WorkflowException;
use Basalto\Core\Models\EntryVersion;
use Illuminate\Console\Command;

/**
 * Geplante Veröffentlichung (M-08): jede Minute die fälligen Fassungen veröffentlichen. Scheitert eine an einer Regel
 * (Slug vergeben, Alt-Text fehlt), bleibt sie geplant und das Protokoll sagt warum — das Cockpit zeigt sie weiter.
 */
final class PublishScheduledCommand extends Command
{
    protected $signature = 'basalto:publish-scheduled';

    protected $description = 'Veröffentlicht geplante Fassungen, deren Zeitpunkt erreicht ist';

    public function handle(Recorder $recorder): int
    {
        $due = EntryVersion::query()->where('status', EntryStatus::Scheduled->value)->where('scheduled_for', '<=', now())->orderBy('scheduled_for')->with('entry')->get();
        $published = 0;
        foreach ($due as $version) {
            try {
                $version->publish();
                $published++;
            } catch (WorkflowException $e) {
                $recorder->record('version.publish_failed', $version->entry, null, ['locale' => $version->locale, 'number' => $version->number, 'reason' => $e->getMessage()], $version->entry->site);
                $this->warn(sprintf('%s · Fassung %d (%s): %s', $version->title, $version->number, $version->locale, $e->getMessage()));
            }
        }
        $this->info(sprintf('%d von %d fälligen Fassungen veröffentlicht.', $published, $due->count()));

        return self::SUCCESS;
    }
}
