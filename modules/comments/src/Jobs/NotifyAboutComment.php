<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Jobs;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Level;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Support\BackendLocale;
use Basalto\Modules\Comments\Embedding;
use Basalto\Modules\Comments\Models\Comment;
use Basalto\Modules\Comments\Notifications\CommentPostedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Die Mail zu einem neuen Kommentar (M-27), als Auftrag wie die Mail eines Formulars: an die Redaktion der Site (wer
 * Inhalte bearbeiten darf — Betreiber, Administrator, Redaktion) oder an die Person, die den Inhalt verfasst hat.
 * Gelingt der Versand, trägt der Kommentar `notified_at`; scheitert er, bleibt der Kommentar unberührt in der Moderation.
 */
final class NotifyAboutComment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $commentId, public readonly string $notify = Embedding::NOTIFY_EDITORS) {}

    public function handle(): void
    {
        $comment = Comment::query()->withoutGlobalScopes()->with('entry')->find($this->commentId);
        $site = $comment === null ? null : Site::query()->find($comment->site_id);
        if ($comment === null || $site === null || $comment->notified_at !== null) {
            return;
        }
        $version = EntryVersion::query()->where('entry_id', $comment->entry_id)->where('locale', $comment->locale)->orderByDesc('number')->first();
        $title = $version !== null ? $version->title : '#'.$comment->entry_id;
        try {
            $url = route('basalto.comments.index', ['status' => $comment->status->value]);
        } catch (Throwable) {
            $url = null;
        }
        // TB 27: Glocke für alle mit dem Bereich «Kommentare» — Kommentare zu einem Inhalt stehen in einer Zeile
        try {
            Notify::make('comments.posted')->title(':name kommentiert «:title»', ['name' => $comment->author_name, 'title' => $title])
                ->titleMany(':count neue Kommentare zu «:title»')->body(':text', ['text' => Str::limit($comment->body, 160, '…')])
                ->url($url, 'Moderation öffnen')->collapse('entry:'.$comment->entry_id)->toArea($site)->send();
        } catch (Throwable $e) {
            report($e);
        }
        $recipients = $this->recipients($comment, $site);
        if ($recipients === []) {
            return;
        }
        BackendLocale::notify($recipients, new CommentPostedNotification($site->name, $title, $comment->author_name, Str::limit($comment->body, 600, '…'),
            $comment->status->value, $url));
        $comment->forceFill(['notified_at' => now()])->save();
    }

    /** @return list<string> */
    private function recipients(Comment $comment, Site $site): array
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');
        if ($this->notify === Embedding::NOTIFY_AUTHOR) {
            $version = EntryVersion::query()->where('entry_id', $comment->entry_id)->where('status', EntryStatus::Published->value)->orderByDesc('number')->first();
            $authorId = $version !== null ? $version->author_id : $comment->entry?->created_by;
            $email = $authorId === null ? null : $model::query()->whereKey($authorId)->value('email');

            return is_string($email) && $email !== '' ? [$email] : [];
        }
        // Wer im Bereich Kommentare bearbeiten darf (CR-005) — Vorlagen und eigene Rollen
        $access = app(Access::class);
        $roles = array_values(array_filter($access->keys($site->account_id), static fn (string $key): bool => $access->resolve($key, $site->account_id)?->level('comments')->includes(Level::Edit) === true));
        $ids = DB::table('site_user')->where('site_id', $site->id)->whereIn('role', $roles)->pluck('user_id')->all();
        // Deaktivierte Konten (Kern, Benutzerverwaltung) bekommen keine Mail mehr
        $emails = $model::query()->whereKey($ids)->whereNull('deactivated_at')->pluck('email')->all();

        return array_values(array_unique(array_filter(array_map(static fn (mixed $e): string => is_string($e) ? $e : '', $emails))));
    }
}
