<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Forms\FormCatalog;
use Basalto\Core\Models\Activity;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Form;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Models\NotFoundHit;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Löschlauf (K-07, K-15, M-09): Protokoll älter als `activity.retention_days`, archivierte Fassungen über
 * `workflow.versions_keep` je Inhalt und Sprache. Fassungen, auf denen eine Übersetzung beruht, bleiben
 * (der Fremdschlüssel würde sonst «veraltet» verlieren). Der Lauf schreibt sich selbst ins Protokoll.
 */
final class CleanupCommand extends Command
{
    protected $signature = 'basalto:cleanup';

    protected $description = 'Löscht Protokoll und archivierte Fassungen nach den Aufbewahrungsfristen';

    public function handle(Settings $settings, Recorder $recorder): int
    {
        $days = (int) $settings->get('activity.retention_days', 365);
        $activity = Activity::query()->where('created_at', '<', now()->subDays($days))->delete();
        $versions = 0;
        foreach (Site::query()->get() as $site) {
            $keep = (int) $settings->get('workflow.versions_keep', 50, $site);
            if ($keep <= 0) {
                continue;
            }
            $referenced = EntryVersion::query()->whereNotNull('source_version_id')->distinct()->pluck('source_version_id')->all();
            $archived = EntryVersion::query()->where('status', EntryStatus::Archived->value)
                ->whereHas('entry', static fn ($q) => $q->withoutGlobalScopes()->where('site_id', $site->id))
                ->orderByDesc('number')->get(['id', 'entry_id', 'locale', 'number']);
            foreach ($archived->groupBy(static fn (EntryVersion $v): string => $v->entry_id.':'.$v->locale) as $group) {
                $drop = $group->slice($keep)->pluck('id')->diff($referenced)->values()->all();
                if ($drop !== []) {
                    $versions += EntryVersion::query()->whereKey($drop)->delete();
                }
            }
        }
        // Formulareingänge nach Frist — je Formular «retain», sonst die Einstellung der Site; Dateien mit (N-05)
        $submissions = 0;
        foreach (Site::query()->get() as $site) {
            $default = (int) $settings->get('forms.retention_days', 90, $site);
            $retain = [];
            foreach (app(FormCatalog::class)->all($site) as $form) {
                $retain[$form->handle] = $form->retainDays($default);
            }
            foreach (Form::query()->withoutGlobalScopes()->where('site_id', $site->id)->get() as $form) {
                $expired = FormSubmission::query()->withoutGlobalScopes()->where('form_id', $form->id)->where('created_at', '<', now()->subDays($retain[$form->handle] ?? $default))->get();
                foreach ($expired as $submission) {
                    foreach ($submission->files ?? [] as $file) {
                        Storage::disk('local')->delete($file['path']);
                    }
                    $submission->delete();
                    $submissions++;
                }
            }
        }
        $notFoundDays = (int) $settings->get('seo.not_found_days', 90);
        $notFound = NotFoundHit::query()->withoutGlobalScopes()->where('last_seen_at', '<', now()->subDays($notFoundDays))->delete();
        // Fristen der Module (z. B. Kommentare, M-27): Closures mit dem Tag «basalto.cleanup», je Art eine Zahl
        $modules = [];
        foreach (app()->tagged('basalto.cleanup') as $task) {
            if ($task instanceof Closure) {
                foreach ((array) $task($settings) as $name => $count) {
                    $modules[(string) $name] = (int) $count;
                }
            }
        }
        $recorder->record('system.cleanup', null, null, ['activity' => $activity, 'versions' => $versions, 'not_found' => $notFound, 'form_submissions' => $submissions, 'retention_days' => $days] + $modules);
        $this->info(sprintf('Löschlauf: %d Protokolleinträge (älter als %d Tage), %d archivierte Fassungen, %d Zeilen im 404-Protokoll, %d Formulareingänge%s.', $activity, $days, $versions, $notFound, $submissions,
            implode('', array_map(static fn (string $name, int $count): string => sprintf(', %d %s', $count, $name), array_keys($modules), $modules))));

        return self::SUCCESS;
    }
}
