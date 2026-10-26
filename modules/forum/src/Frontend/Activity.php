<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\Reactions;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Poll;
use Basalto\Modules\Forum\Models\Post;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Markup der Aktivität an einem Beitrag (CR-006 FO-12): Reaktionen (Formulare ohne Skript, die Insel schickt sie ohne
 * Neuladen), Umfrage (abstimmen oder Ergebnis) und kleine Formulare für Abo, Ignorieren, gelesen.
 */
final class Activity
{
    /**
     * Reaktionen unter einem Beitrag (TB 47): die Zählung als Chips für alle — die eigene hervorgehoben —, für
     * angemeldete fremde Konten ein Knopf «Reagieren», der die Auswahl als Blase öffnet. Ohne Skript ist jede
     * Reaktion ein Formular-Knopf.
     *
     * @param  array<string, int>  $counts
     */
    public static function reactions(Post $post, array $counts, ?string $mine, bool $canReact, string $pagePath, string $lang): string
    {
        $summary = '';
        // TB 52: nur die Arten, die die Site anbietet
        $types = Reactions::enabled(app(CurrentSite::class)->get());
        foreach ($types as $type => $symbol) {
            if (($counts[$type] ?? 0) > 0) {
                $summary .= sprintf('<span class="forum-reaktion%s" title="%s">%s <b>%d</b></span>', $mine === $type ? ' ist-meine' : '', Markup::e(Reactions::label($type, $lang)), $symbol, $counts[$type]);
            }
        }
        $buttons = '';
        if ($canReact) {
            foreach ($types as $type => $symbol) {
                $buttons .= sprintf('<button type="submit" name="reaction" value="%s" class="forum-reaktion-knopf" aria-pressed="%s" title="%s" aria-label="%3$s">%s</button>',
                    $type, $mine === $type ? 'true' : 'false', Markup::e(Reactions::label($type, $lang)), $symbol);
            }
            $label = Markup::e(__('Reagieren', [], $lang));
            $buttons = sprintf('<details class="forum-reagieren-menue"><summary aria-label="%s" title="%1$s">%s</summary><form method="post" action="%s" class="forum-reagieren" data-insel="reaktion">%s<input type="hidden" name="subject" value="%s"><input type="hidden" name="id" value="%d"><input type="hidden" name="_anker" value="beitrag-%6$d">%s</form></details>',
                $label, Markup::icon('smile'), Markup::e(route('basalto.community.react', absolute: false)), Html::hidden($pagePath, $lang, true), Forum::ATTACHMENTS, $post->id, $buttons);
        }

        return $summary === '' && $buttons === '' ? '' : sprintf('<div class="forum-reaktionen"><p class="forum-reaktionen__summe" data-reaktion-summe>%s</p>%s</div>', $summary, $buttons);
    }

    /** Die Umfrage: abstimmen, solange offen und noch nicht abgestimmt — sonst (oder wenn erlaubt) das Ergebnis. */
    public static function poll(Poll $poll, ?int $customerId, bool $canVote, string $pagePath, string $lang, MessageBag $errors): string
    {
        $options = $poll->options()->get();
        $voted = $customerId !== null && DB::table('forum_poll_votes')->where('poll_id', $poll->id)->where('customer_id', $customerId)->exists();
        $mine = $customerId === null ? [] : DB::table('forum_poll_votes')->where('poll_id', $poll->id)->where('customer_id', $customerId)->pluck('option_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $showResults = $voted || ! $poll->isOpen() || ! $poll->results_after_vote;
        $head = sprintf('<h3>%s%s</h3><p class="forum-umfrage__info">%s%s</p>', Markup::icon('list-check'), Markup::e($poll->question),
            Markup::e(trans_choice(':count Stimme|:count Stimmen', $poll->voters, ['count' => $poll->voters], $lang)),
            $poll->ends_at === null ? '' : ' · '.($poll->isOpen() ? Markup::e(__('läuft bis :date', ['date' => self::date($poll->ends_at, $lang)], $lang)) : Markup::e(__('beendet', [], $lang))));
        $error = $errors->first('umfrage');
        $html = $head.($error !== '' ? sprintf('<p class="form-error" role="alert">%s</p>', Markup::e($error)) : '');
        if ($canVote && ! $voted && $poll->isOpen()) {
            $type = $poll->max_choices > 1 ? 'checkbox' : 'radio';
            $items = '';
            foreach ($options as $option) {
                $items .= sprintf('<label class="forum-umfrage__wahl"><input type="%s" name="optionen[]" value="%d"> %s</label>', $type, $option->id, Markup::e($option->label));
            }
            $hint = $poll->max_choices > 1 ? sprintf('<p class="form-help">%s</p>', Markup::e(trans_choice('Höchstens :count Antwort.|Höchstens :count Antworten.', $poll->max_choices, ['count' => $poll->max_choices], $lang))) : '';
            $html .= sprintf('<form method="post" action="%s">%s<fieldset><legend class="visually-hidden">%s</legend>%s</fieldset>%s<p><button type="submit" class="forum-button">%s</button></p></form>',
                Markup::e(route('basalto.forum.vote', ['poll' => $poll->id], false)), Html::hidden($pagePath, $lang, true), Markup::e($poll->question), $items, $hint, Markup::e(__('Abstimmen', [], $lang)));
            if (! $showResults) {
                return sprintf('<section class="forum-umfrage" id="umfrage">%s<p class="form-help">%s</p></section>', $html, Markup::e(__('Das Ergebnis erscheint nach Ihrer Stimme.', [], $lang)));
            }
        }
        if ($showResults) {
            $total = max(1, $options->sum('votes'));
            $bars = '';
            foreach ($options as $option) {
                $percent = (int) round($option->votes * 100 / $total);
                $bars .= sprintf('<li%s><span class="forum-umfrage__text">%s%s</span><span class="forum-umfrage__balken"><span style="width:%d%%"></span></span><span class="forum-umfrage__zahl">%d %% (%d)</span></li>',
                    in_array($option->id, $mine, true) ? ' class="ist-gewaehlt"' : '', Markup::e($option->label), in_array($option->id, $mine, true) ? ' ✓' : '', $percent, $percent, $option->votes);
            }
            $html .= '<ol class="forum-umfrage__ergebnis">'.$bars.'</ol>';
        } elseif (! $canVote) {
            $html .= sprintf('<p class="form-help">%s</p>', Markup::e(__('Zum Abstimmen bitte anmelden.', [], $lang)));
        }

        return sprintf('<section class="forum-umfrage" id="umfrage">%s</section>', $html);
    }

    private static function date(\DateTimeInterface $date, string $lang): string
    {
        $local = Carbon::instance($date);
        $local->locale($lang);

        return $local->isoFormat(Markup::dayFormat($lang, true));
    }

    /**
     * Ein kleines Formular mit einem Knopf (Abo, Ignorieren, gelesen, Moderation) — ohne Skript bedienbar. Mit Symbol
     * und Art (`gut`, `gefahr`, `leise`) für die Gestalt.
     *
     * @param  array<string, string|int>  $fields
     */
    public static function button(string $action, array $fields, string $label, string $pagePath, string $lang, bool $pressed = false, string $icon = '', string $variant = ''): string
    {
        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= sprintf('<input type="hidden" name="%s" value="%s">', Markup::e($name), Markup::e($value));
        }

        return sprintf('<form method="post" action="%s" class="forum-knopfform">%s%s<button type="submit" class="forum-aktion%s"%s>%s%s</button></form>', Markup::e($action),
            Html::hidden($pagePath, $lang, true), $inputs, $variant === '' ? '' : ' forum-aktion--'.$variant, $pressed ? ' aria-pressed="true"' : '', $icon === '' ? '' : Markup::mini($icon).' ', Markup::e($label));
    }
}
