<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\WorkflowException;
use Basalto\Core\Database\Factories\EntryVersionFactory;
use Basalto\Core\Events\VersionPublished;
use Basalto\Core\Events\VersionTransitioned;
use Basalto\Core\Media\MediaUsages;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\SiteScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Sprachfassung eines Inhalts mit Nummer, Status, Titel, Slug (je Sprache) und Feldern als JSON.
 *
 * @property int $id
 * @property int $entry_id
 * @property string $locale
 * @property int|null $source_version_id
 * @property int $number
 * @property EntryStatus $status
 * @property string $title
 * @property string $slug
 * @property array<string, mixed> $data
 * @property int|null $author_id
 * @property Carbon|null $submitted_at
 * @property int|null $submitted_by
 * @property string|null $comment
 * @property int|null $reviewed_by
 * @property int|null $approved_by
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class EntryVersion extends Model
{
    /** @use HasFactory<EntryVersionFactory> */
    use HasFactory;

    protected $fillable = ['entry_id', 'locale', 'source_version_id', 'submitted_at', 'submitted_by', 'comment', 'reviewed_by', 'number', 'status', 'title', 'slug', 'data', 'author_id', 'approved_by', 'scheduled_for', 'published_at'];

    protected function casts(): array
    {
        return ['status' => EntryStatus::class, 'data' => 'array', 'scheduled_for' => 'datetime', 'published_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    protected static function newFactory(): EntryVersionFactory
    {
        return EntryVersionFactory::new();
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * Die Fassung der Standardsprache, auf der diese Übersetzung beruht (M-06).
     *
     * @return BelongsTo<self, $this>
     */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_version_id');
    }

    public function submit(?int $userId = null): void
    {
        $this->assertCanBecome(EntryStatus::InReview);
        $this->forceFill(['submitted_at' => now(), 'submitted_by' => $userId, 'comment' => null, 'reviewed_by' => null])->save();
        $this->transition(EntryStatus::InReview);
    }

    public function backToDraft(): void
    {
        $this->transition(EntryStatus::Draft);
    }

    /** Zurückziehen (M-08): die Einreichende holt ihre Fassung aus der Prüfung zurück. */
    public function withdraw(): void
    {
        if ($this->status !== EntryStatus::InReview) {
            throw new WorkflowException(__('Zurückziehen geht nur aus «In Prüfung»'));
        }
        $this->transition(EntryStatus::Draft);
    }

    /** Zurückweisen (M-08): mit Kommentar zurück in den Entwurf — der Kommentar steht am Entwurf, bis er wieder eingereicht wird. */
    public function reject(int $reviewerId, string $comment): void
    {
        if ($this->status !== EntryStatus::InReview) {
            throw new WorkflowException(__('Zurückweisen geht nur aus «In Prüfung»'));
        }
        if (trim($comment) === '') {
            throw new WorkflowException(__('Eine Rückweisung braucht einen Kommentar'));
        }
        $this->forceFill(['comment' => trim($comment), 'reviewed_by' => $reviewerId])->save();
        $this->transition(EntryStatus::Draft, trim($comment));
    }

    /** Planung aufheben: zurück auf «Freigegeben». */
    public function unschedule(): void
    {
        if ($this->status !== EntryStatus::Scheduled) {
            throw new WorkflowException(__('Nur eine geplante Fassung lässt sich entplanen'));
        }
        $this->forceFill(['scheduled_for' => null])->save();
        $this->transition(EntryStatus::Approved);
    }

    /** Offline nehmen: die live Fassung wird archiviert — die Sprache hat danach keine veröffentlichte Fassung mehr. */
    public function unpublish(): void
    {
        if ($this->status !== EntryStatus::Published) {
            throw new WorkflowException(__('Offline nehmen geht nur mit der veröffentlichten Fassung'));
        }
        app(Pages::class)->trackPaths($this->entry->site, fn () => $this->transition(EntryStatus::Archived));
    }

    /**
     * Freigabe (R-22): nur aus «In Prüfung»; mit Vier-Augen darf die Autorin nicht selbst freigeben;
     * alle Pflichtsprachen der Site müssen eine eingereichte oder spätere Fassung haben.
     */
    public function approve(int $approverId): void
    {
        $this->assertCanBecome(EntryStatus::Approved);
        $site = $this->entry->site;
        if ($this->fourEyesBlocks($approverId)) {
            throw new WorkflowException(__('Vier-Augen-Prinzip: Wer eingereicht hat, darf nicht selbst freigeben'));
        }
        $missing = $this->missingRequiredLocales();
        if ($missing !== []) {
            throw new WorkflowException(__('Freigabe erst mit allen Pflichtsprachen — es fehlen: :locales', ['locales' => implode(', ', $missing)]));
        }
        $this->forceFill(['approved_by' => $approverId])->save();
        $this->transition(EntryStatus::Approved);
    }

    /** Vier-Augen (M-08): Wer die Fassung geschrieben oder eingereicht hat, gibt sie nicht selbst frei. */
    public function fourEyesBlocks(int $userId): bool
    {
        if (app(Settings::class)->get('four_eyes', false, $this->entry->site) !== true) {
            return false;
        }

        return ($this->author_id !== null && $this->author_id === $userId) || ($this->submitted_by !== null && $this->submitted_by === $userId);
    }

    public function schedule(CarbonInterface $at): void
    {
        $this->assertCanBecome(EntryStatus::Scheduled);
        // Eloquent speichert die Wanduhrzeit: immer als UTC ablegen, egal in welcher Zone der Aufrufer rechnet
        $this->forceFill(['scheduled_for' => CarbonImmutable::instance($at)->utc()])->save();
        $this->transition(EntryStatus::Scheduled);
    }

    /**
     * Veröffentlichen: Slug muss unter den veröffentlichten Fassungen der Site in dieser Sprache eindeutig sein; mit
     * Alt-Text-Pflicht der Site (K-10) braucht jedes Bild der Fassung einen Alt-Text; die bisherige live Fassung wird archiviert.
     */
    public function publish(): void
    {
        $this->assertCanBecome(EntryStatus::Published);
        $from = $this->status;
        if ($this->slugIsTaken()) {
            throw new WorkflowException(__('Slug «:slug» ist in der Sprache :locale schon veröffentlicht', ['slug' => $this->slug, 'locale' => $this->locale]));
        }
        if (app(Settings::class)->get('media.alt_required', false, $this->entry->site) === true) {
            $missing = app(MediaUsages::class)->missingAltTexts($this);
            if ($missing !== []) {
                throw new WorkflowException(__('Veröffentlichung abgewiesen — Alt-Text fehlt (:locale): :files. Alt-Texte stehen in der Mediathek je Sprache.', ['locale' => strtoupper($this->locale), 'files' => implode(', ', array_map(static fn (string $n): string => '«'.$n.'»', $missing))]));
            }
        }
        app(Pages::class)->trackPaths($this->entry->site, function (): void {
            $this->entry->versions()->where('locale', $this->locale)->where('status', EntryStatus::Published->value)->whereKeyNot($this->id)
                ->update(['status' => EntryStatus::Archived->value]);
            $this->forceFill(['status' => EntryStatus::Published, 'published_at' => now()])->save();
        });
        event(new VersionTransitioned($this, $from, EntryStatus::Published));
        event(new VersionPublished($this));
    }

    public function archive(): void
    {
        $this->transition(EntryStatus::Archived);
    }

    /** @return list<string> */
    public function missingRequiredLocales(): array
    {
        $entry = $this->entry;
        $present = $entry->versions()->where('status', '!=', EntryStatus::Draft->value)->distinct()->pluck('locale')->all();
        $present[] = $this->locale;

        return array_values(array_diff($entry->site->requiredLocales(), $present));
    }

    public function slugIsTaken(): bool
    {
        $siteId = $this->entry->site_id;

        return self::query()
            ->where('status', EntryStatus::Published->value)
            ->where('locale', $this->locale)
            ->where('slug', $this->slug)
            ->where('entry_id', '!=', $this->entry_id)
            ->whereHas('entry', static fn ($query) => $query->withoutGlobalScope(SiteScope::class)->where('site_id', $siteId))
            ->exists();
    }

    private function transition(EntryStatus $to, ?string $comment = null): void
    {
        $this->assertCanBecome($to);
        $from = $this->status;
        $this->forceFill(['status' => $to])->save();
        event(new VersionTransitioned($this, $from, $to, $comment));
    }

    private function assertCanBecome(EntryStatus $to): void
    {
        if (! $this->status->canBecome($to)) {
            throw new WorkflowException(__('Übergang :from → :to ist nicht erlaubt', ['from' => $this->status->label(), 'to' => $to->label()]));
        }
    }
}
