<?php

declare(strict_types=1);

namespace Basalto\Core\Listeners;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Events\MediaRestored;
use Basalto\Core\Events\MediaTrashed;
use Basalto\Core\Events\MediaUploaded;
use Basalto\Core\Events\SiteCreated;
use Basalto\Core\Events\SiteUpdated;
use Basalto\Core\Events\UserLoggedIn;
use Basalto\Core\Events\VersionPublished;
use Basalto\Core\Events\VersionTransitioned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/** Schreibt das Protokoll (K-15) aus den Domänenereignissen des Kerns — Module tun dasselbe für ihre Ereignisse. */
final class RecordActivity
{
    public function __construct(private readonly Recorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(SiteCreated::class, fn (SiteCreated $e) => $this->recorder->record('site.created', $e->site, null, ['handle' => $e->site->handle, 'name' => $e->site->name], $e->site));
        $events->listen(SiteUpdated::class, fn (SiteUpdated $e) => $this->recorder->record('site.updated', $e->site, $e->before, $e->after, $e->site));
        $events->listen(EntrySaved::class, fn (EntrySaved $e) => $this->recorder->record('entry.saved', $e->entry, null, ['locale' => $e->version->locale, 'number' => $e->version->number, 'title' => $e->version->title]));
        // Übergänge im Workflow (M-08): Status alt → neu, bei Rückweisung mit Kommentar; «veröffentlicht» schreibt VersionPublished
        $events->listen(VersionTransitioned::class, function (VersionTransitioned $e): void {
            if ($e->to === EntryStatus::Published) {
                return;
            }
            $action = match (true) {
                $e->to === EntryStatus::InReview => 'version.submitted',
                $e->from === EntryStatus::InReview && $e->to === EntryStatus::Draft => $e->comment !== null ? 'version.rejected' : 'version.withdrawn',
                $e->to === EntryStatus::Approved && $e->from === EntryStatus::Scheduled => 'version.unscheduled',
                $e->to === EntryStatus::Approved => 'version.approved',
                $e->to === EntryStatus::Scheduled => 'version.scheduled',
                $e->to === EntryStatus::Archived => 'version.unpublished',
                default => 'version.'.$e->to->value,
            };
            $this->recorder->record($action, $e->version->entry, ['status' => $e->from->value], array_filter(['status' => $e->to->value, 'locale' => $e->version->locale, 'number' => $e->version->number,
                'title' => $e->version->title, 'comment' => $e->comment, 'scheduled_for' => $e->to === EntryStatus::Scheduled ? $e->version->scheduled_for?->toIso8601String() : null], static fn (mixed $v): bool => $v !== null));
        });
        $events->listen(VersionPublished::class, fn (VersionPublished $e) => $this->recorder->record('version.published', $e->version->entry, null, ['locale' => $e->version->locale, 'number' => $e->version->number, 'slug' => $e->version->slug]));
        $events->listen(MediaUploaded::class, fn (MediaUploaded $e) => $this->recorder->record('media.uploaded', $e->media, null, ['name' => $e->media->name, 'size' => $e->media->size]));
        $events->listen(MediaTrashed::class, fn (MediaTrashed $e) => $this->recorder->record('media.trashed', $e->media, null, ['name' => $e->media->name, 'usages' => $e->usages]));
        $events->listen(MediaRestored::class, fn (MediaRestored $e) => $this->recorder->record('media.restored', $e->media, null, ['name' => $e->media->name]));
        $events->listen(UserLoggedIn::class, fn (UserLoggedIn $e) => $this->recorder->record('user.logged_in', $e->user instanceof Model ? $e->user : null, null, ['method' => $e->method], user: $e->user));
    }
}
