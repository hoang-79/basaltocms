<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Testdata;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Core\Testdata\TestdataText;
use Basalto\Modules\Comments\CommentStatus;
use Basalto\Modules\Comments\Models\Comment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Testdaten der Kommentare: zu den Blogbeiträgen der Site (sonst zu Seiten) viele Kommentare in allen Zuständen —
 * freigegeben, wartend, Spam, gelöscht —, verschachtelte Antworten, Antworten der Redaktion, Gäste mit und ohne
 * Website, Kommentare in den anderen Sprachen und solche mit heiklem Inhalt (sehr lang, Markup, Links).
 */
final class CommentsTestdata
{
    public const KEY = 'kommentare';

    private const TEXTS = ['Danke für den Beitrag, sehr hilfreich!', 'Gibt es dazu auch eine Anleitung für Einsteiger?', 'Bei mir funktioniert der zweite Schritt nicht — fehlt da etwas?',
        'Toller Artikel. Wir setzen das genau so im Verein um.', 'Ich sehe das etwas anders: Für kleine Sites ist das zu viel Aufwand.', "Erste Zeile\nZweite Zeile\n\nNeuer Absatz nach einer Leerzeile.",
        '<script>alert("kommentar")</script> sollte als Text erscheinen.', 'Schaut mal hier: https://www.example.com/ein/sehr/langer/link/der/umbrechen/muss/damit/nichts/aus/der/spalte/faellt',
        'BILLIGE UHREN!!! Jetzt kaufen auf example.com', 'Merci pour cet article !', 'Grazie, molto utile.'];

    public function seed(TestdataRun $run): void
    {
        $site = $run->site;
        $entries = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('type', 'post')->orderBy('id')->limit(12)->get();
        if ($entries->isEmpty()) {
            $entries = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('type', 'page')->whereNull('parent_id')->orderBy('id')->limit(4)->get();
        }
        $customers = $run->pool('alle');
        $staff = $this->staff($run);
        $locales = $site->localeCodes();
        foreach ($entries as $e => $entry) {
            $count = $e === 0 ? 25 + 5 * $run->scale : $run->int(0, 3 + 3 * $run->scale);
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $customer = $customers !== [] && $run->chance(60) ? $run->pick($customers) : null;
                [$first, $last] = TestdataText::name($run);
                $text = $run->pick(self::TEXTS);
                $status = str_starts_with($text, 'BILLIGE') ? CommentStatus::Spam : CommentStatus::from($run->weighted(['approved' => 75, 'pending' => 15, 'spam' => 5, 'deleted' => 5]));
                $at = $run->ago(200);
                $parent = $ids !== [] && $run->chance(30) ? $run->pick($ids) : null;
                $comment = new Comment(['site_id' => $site->id, 'entry_id' => $entry->id, 'parent_id' => $parent, 'locale' => str_starts_with($text, 'Merci') ? 'fr' : (str_starts_with($text, 'Grazie') ? 'it' : $run->pick($locales)),
                    'author_name' => $customer->name ?? ($run->chance(10) ? $run->pick(TestdataText::TRICKY_NAMES) : $first.' '.$last),
                    'author_email' => $customer->email ?? TestdataRun::email(TestdataText::local($first, $last, 900 + $i)), 'author_url' => $run->chance(15) ? 'https://'.TestdataText::local($first, $last, 0).'.example' : null,
                    'body' => $run->chance(10) ? TestdataText::paragraph($run, 'de', 4, 8) : $text, 'status' => $status, 'ip_hash' => hash('sha256', 'testdaten'.$i), 'consent_at' => $at]);
                $comment->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
                $ids[] = $comment->id;
                $run->count('Kommentare');
                if ($staff !== null && $status === CommentStatus::Approved && $run->chance(15)) {
                    $reply = new Comment(['site_id' => $site->id, 'entry_id' => $entry->id, 'parent_id' => $comment->id, 'user_id' => $staff->getKey(), 'locale' => $comment->locale,
                        'author_name' => (string) $staff->getAttribute('name'), 'author_email' => (string) $staff->getAttribute('email'), 'body' => 'Danke für Ihren Kommentar! '.TestdataText::sentence($run),
                        'status' => CommentStatus::Approved, 'consent_at' => $at]);
                    $reply->forceFill(['created_at' => $run->after($at, 48), 'updated_at' => $run->after($at, 48)])->save();
                    $run->count('Antworten der Redaktion');
                }
            }
        }
    }

    public function remove(Site $site): void
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $users = $userModel::query()->where('email', 'like', '%@'.TestdataRun::DOMAIN)->pluck('id')->all();
        $ids = Comment::query()->withoutGlobalScopes()->where('site_id', $site->id)
            ->where(static fn ($q) => $q->where('author_email', 'like', '%@'.TestdataRun::DOMAIN)->orWhereIn('user_id', $users))->pluck('id')->all();
        // Antworten zuerst, dann die Kommentare selbst
        DB::table('comments')->whereIn('parent_id', $ids)->update(['parent_id' => null]);
        Comment::query()->withoutGlobalScopes()->whereIn('id', $ids)->delete();
    }

    private function staff(TestdataRun $run): ?Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $userModel::query()->where('email', 'like', '%@'.TestdataRun::DOMAIN)->whereIn('id', DB::table('site_user')->where('site_id', $run->site->id)->select('user_id'))->orderBy('id')->first();
    }
}
