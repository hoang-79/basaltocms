<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Lava\Runtime\Context;
use Illuminate\Support\Carbon;

/**
 * Das Kundenkonto als Ganzes (TB 48, neu geordnet in TB 63; Tag `{% account %}`): Gäste sehen, was das Konto bringt,
 * und melden sich per Code an; angemeldet ein kompakter Kopf, links die Bereiche, rechts immer einer — zuerst die
 * Übersicht mit «Als Nächstes», Kacheln und «Zuletzt». Was in den Bereichen steht, liefern die Module
 * ({@see AccountArea}); ohne Skript bedienbar (alle Bereiche untereinander), Wechsel und Kopierknöpfe kommen mit Skript dazu.
 */
final class AccountHub
{
    private const TONES = ['lava' => 0, 'warn' => 1, '' => 2];

    public function __construct(private readonly AccountAreas $areas, private readonly LoginForm $login) {}

    /**
     * @param  array<string, mixed>  $named
     * @param  list<string>|null  $only  nur diese Bereiche (für ältere Tags eines Moduls)
     */
    public function render(array $named, Context $ctx, ?array $only = null): string
    {
        $page = AccountPage::from($ctx, $named);
        if ($page === null) {
            return '';
        }
        $areas = $this->areas->resolve($only);

        // CR-013: Formulare mit Autosave bringen ihr Skript hinter dem Konto mit
        return AccountStyles::once().($page->customer === null ? $this->guest($page, $areas) : $this->signedIn($page, $areas)).self::script().Html::autosaveScript();
    }

    /**
     * Nur die Abschnitte bestimmter Bereiche, ohne Kopf und ohne Anmeldung — für Tags, die neben einem anderen Konto
     * stehen (`marketplace.purchases`). Gäste sehen nichts.
     *
     * @param  array<string, mixed>  $named
     * @param  list<string>  $only
     */
    public function sections(array $named, Context $ctx, array $only): string
    {
        $page = AccountPage::from($ctx, $named);
        if ($page?->customer === null) {
            return '';
        }
        $html = '';
        foreach ($this->areas->resolve($only) as $area) {
            $summary = $area->summary($page);
            $html .= $summary === null ? '' : self::notices([$summary]).$this->section($page, $area, $summary);
        }

        return $html === '' ? '' : AccountStyles::once().'<div class="konto konto--eingebettet">'.$html.'</div>'.self::script().Html::autosaveScript();
    }

    /** @param  list<AccountArea>  $areas */
    private function guest(AccountPage $page, array $areas): string
    {
        $items = '';
        foreach ($areas as $area) {
            $pitch = $area->pitch($page);
            if ($pitch !== null) {
                $items .= sprintf('<li><span class="konto-sechseck konto-sechseck--hell" aria-hidden="true">%s</span><div><b>%s</b><p>%s</p></div></li>', Icons::svg($pitch->icon), Html::e($pitch->title), Html::e($pitch->text));
            }
        }
        $promise = $items === '' ? '' : sprintf('<section class="konto-versprechen" aria-labelledby="konto-versprechen-titel"><h2 id="konto-versprechen-titel">%s</h2><p>%s</p><ul>%s</ul></section>',
            Html::e($page->t('Ein Konto für alles bei :site', ['site' => $page->site->name])),
            Html::e($page->t('Melden Sie sich mit Ihrer E-Mail-Adresse an: Sie bekommen einen Code, ein Passwort brauchen Sie nie.')), $items);

        return sprintf('<div class="konto konto--gast%s">%s<div class="konto-anmeldung">%s</div></div>', $promise === '' ? ' konto--schmal' : '', $promise,
            $this->login->render($page->request, $page->lang, $page->path));
    }

    /**
     * Angemeldet (TB 63): kompakter Kopf, links die Bereiche als Navigation, rechts immer nur einer — zuerst die
     * Übersicht mit «Als Nächstes», einer Kachel je Bereich und «Zuletzt». Gewechselt wird über Anker
     * (`#konto-<bereich>`); das Skript zeigt den Bereich, in dem der Anker liegt — so öffnen Links wie `#lizenz-12`
     * nach einer Aktion den richtigen Bereich. Ohne Skript stehen alle Bereiche untereinander.
     *
     * @param  list<AccountArea>  $areas
     */
    private function signedIn(AccountPage $page, array $areas): string
    {
        $entries = [];
        foreach ($areas as $area) {
            $summary = $area->summary($page);
            if ($summary !== null) {
                $entries[] = [$area, $summary];
            }
        }
        $summaries = array_map(static fn (array $e): AreaSummary => $e[1], $entries);
        $panels = $this->overview($page, $entries);
        foreach ($entries as [$area, $summary]) {
            $panels .= $this->section($page, $area, $summary);
        }

        return '<div class="konto konto--app" id="konto">'.self::status($page).self::notices($summaries).$this->hero($page, $entries)
            .'<div class="konto-app">'.$this->navigation($page, $entries).'<div class="konto-inhalt">'.$panels.'</div></div></div>'.self::tabs();
    }

    /**
     * Die Bereiche als Navigation — Symbol, Name und Zahl; Achtung (lava) und Hinweis (warn) als Punkt mit Text für
     * Screenreader. Auf breiten Schirmen eine Spalte links, schmal ein umbrechendes Raster (nie waagrecht scrollen).
     *
     * @param  list<array{0: AccountArea, 1: AreaSummary}>  $entries
     */
    private function navigation(AccountPage $page, array $entries): string
    {
        $item = static fn (string $anchor, string $icon, string $label, ?int $count, string $tone, string $hint): string => sprintf(
            '<li><a class="konto-nav__punkt%s" href="#%s" data-konto-tafel="%s">%s<span class="konto-nav__name">%s</span>%s%s</a></li>',
            $tone === '' ? '' : ' konto-nav__punkt--'.Html::e($tone), $anchor, $anchor, Icons::svg($icon), Html::e($label),
            $count === null || $count === 0 ? '' : '<b class="konto-nav__zahl">'.Html::e(number_format($count, 0, '.', '\'')).'</b>',
            $hint === '' ? '' : '<span class="sr-only"> — '.Html::e($hint).'</span>');
        $items = $item('konto-uebersicht', 'grid', $page->t('Übersicht'), null, '', '');
        foreach ($entries as [$area, $summary]) {
            $items .= $item(AccountPage::anchor($area->key()), $summary->icon, $summary->label, $summary->count, $summary->tone, $summary->caption);
        }

        return sprintf('<nav class="konto-nav" aria-label="%s"><ul>%s</ul></nav>', Html::e($page->t('Bereiche des Kontos')), $items);
    }

    /**
     * Die Übersicht: «Als Nächstes», je Bereich eine Kachel mit Stand und «Zuletzt».
     *
     * @param  list<array{0: AccountArea, 1: AreaSummary}>  $entries
     */
    private function overview(AccountPage $page, array $entries): string
    {
        $summaries = array_map(static fn (array $e): AreaSummary => $e[1], $entries);
        $tiles = '';
        foreach ($entries as [$area, $summary]) {
            $anchor = AccountPage::anchor($area->key());
            $tiles .= sprintf('<li><a class="konto-kachel%s" href="#%s" data-konto-tafel="%s"><span class="konto-sechseck%s" aria-hidden="true">%s</span><span class="konto-kachel__text"><b>%s</b><small>%s</small></span>%s</a></li>',
                $summary->tone === '' ? '' : ' konto-kachel--'.Html::e($summary->tone), $anchor, $anchor, $summary->tone === 'lava' ? ' konto-sechseck--lava' : ($summary->tone === 'warn' ? ' konto-sechseck--warn' : ''),
                Icons::svg($summary->icon), Html::e($summary->label), Html::e($summary->caption), $summary->count === null || $summary->count === 0 ? '' : '<span class="konto-kachel__zahl">'.Html::e(number_format($summary->count, 0, '.', '\'')).'</span>');
        }
        $grid = $tiles === '' ? '' : sprintf('<section class="konto-kacheln" aria-labelledby="konto-kacheln-titel"><h3 class="konto-kleintitel" id="konto-kacheln-titel">%s</h3><ul>%s</ul></section>',
            Html::e($page->t('Ihre Bereiche')), $tiles);

        return sprintf('<section class="konto-bereich konto-tafel" id="konto-uebersicht" aria-labelledby="konto-uebersicht-titel" tabindex="-1"><header class="konto-bereich__kopf"><h2 id="konto-uebersicht-titel">%s%s</h2></header>%s%s%s</section>',
            Icons::svg('grid'), Html::e($page->t('Übersicht')), $this->todos($page, $entries), $grid, $this->recent($page, $summaries));
    }

    private static function status(AccountPage $page): string
    {
        $status = $page->request->hasSession() ? $page->request->session()->get('account.status') : null;

        return is_string($status) && $status !== '' ? Html::status($status, 'konto-meldung') : '';
    }

    /** @param  list<AreaSummary>  $summaries */
    private static function notices(array $summaries): string
    {
        $html = '';
        foreach ($summaries as $summary) {
            foreach ($summary->notices as $notice) {
                $html .= sprintf('<p class="konto-meldung%s" role="status">%s</p>', $notice['tone'] === '' ? '' : ' konto-meldung--'.Html::e($notice['tone']), Html::e($notice['text']));
            }
        }

        return $html;
    }

    /**
     * Der Kopf (TB 63): Profilbild, Begrüssung, E-Mail und Konto seit, die wichtigsten Wege (je Bereich der erste,
     * höchstens drei) und — abgesetzt — Abmelden.
     *
     * @param  list<array{0: AccountArea, 1: AreaSummary}>  $entries
     */
    private function hero(AccountPage $page, array $entries): string
    {
        $customer = $page->customer;
        $profile = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $page->site->id)->where('customer_id', $customer?->id)->first();
        $name = trim((string) $customer?->name) !== '' ? trim((string) $customer?->name) : ($profile->name ?? (string) strstr((string) $customer?->email, '@', true));
        $hour = (int) now()->setTimezone($page->site->timezone ?: (string) config('app.timezone'))->format('G');
        $greeting = match (true) {
            $hour >= 5 && $hour < 11 => $page->t('Guten Morgen, :name.', ['name' => $name]),
            $hour >= 11 && $hour < 18 => $page->t('Guten Tag, :name.', ['name' => $name]),
            default => $page->t('Guten Abend, :name.', ['name' => $name]),
        };
        $created = $customer?->created_at === null ? null : Carbon::instance($customer->created_at);
        $created?->locale($page->lang);
        $since = $created === null ? '' : ' · '.$page->t('Konto seit :date', ['date' => $created->isoFormat('MMMM YYYY')]);
        $actions = [];
        foreach ($entries as [, $summary]) {
            if ($summary->actions !== []) {
                $actions[] = $summary->actions[0];
            }
        }
        usort($actions, static fn (AreaAction $a, AreaAction $b): int => (int) $b->primary <=> (int) $a->primary);
        // Ein Hauptknopf (der erste mit Vorrang), die übrigen zurückhaltend
        $buttons = '';
        foreach (array_slice($actions, 0, 3) as $i => $action) {
            $buttons .= self::button($action, $i === 0 && $action->primary ? 'lava' : 'hell');
        }
        $logout = sprintf('<form class="konto-abmelden" method="post" action="/account/logout">%s<button type="submit" class="konto-knopf konto-knopf--hell konto-knopf--leise">%s %s</button></form>',
            Html::hidden($page->path, $page->lang, true), Icons::mini('logout'), Html::e($page->t('Abmelden')));

        return sprintf('<header class="konto-held" aria-labelledby="konto-gruss"><div class="konto-held__person">%s<div><h2 class="konto-held__gruss" id="konto-gruss">%s</h2><p class="konto-held__meta">%s%s</p></div></div><div class="konto-held__knoepfe">%s%s</div></header>',
            self::avatar($profile, $name), Html::e($greeting), Html::e((string) $customer?->email), Html::e($since), $buttons, $logout);
    }

    /** Profilbild der Community oder Initialen — im Sechseck der Marke. */
    public static function avatar(?CommunityProfile $profile, string $name): string
    {
        $url = $profile?->avatarUrl();
        if ($url !== null) {
            return sprintf('<img class="konto-avatar" src="%s" alt="" width="96" height="96">', Html::e($url));
        }
        $words = preg_split('/[\s.@_-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $initials = mb_strtoupper(mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));

        return sprintf('<span class="konto-avatar konto-avatar--f%d" aria-hidden="true">%s</span>', 1 + crc32($name) % 6, Html::e($initials));
    }

    /** @param  list<array{0: AccountArea, 1: AreaSummary}>  $entries */
    private function todos(AccountPage $page, array $entries): string
    {
        $todos = [];
        foreach ($entries as [, $summary]) {
            array_push($todos, ...$summary->todos);
        }
        if ($todos === []) {
            return sprintf('<p class="konto-tipp">%s %s</p>', Icons::mini('check'), Html::e($page->t('Alles erledigt — nichts wartet auf Sie.')));
        }
        usort($todos, static fn (AreaTodo $a, AreaTodo $b): int => (self::TONES[$a->tone] ?? 2) <=> (self::TONES[$b->tone] ?? 2));
        $items = '';
        foreach (array_slice($todos, 0, 4) as $todo) {
            $items .= sprintf('<li class="konto-aufgabe%s"><span class="konto-sechseck konto-sechseck--klein%s" aria-hidden="true">%s</span><p>%s</p>%s</li>', $todo->tone === '' ? '' : ' konto-aufgabe--'.Html::e($todo->tone),
                $todo->tone === '' ? '' : ' konto-sechseck--'.Html::e($todo->tone), Icons::svg($todo->icon), Html::e($todo->text), self::button($todo->action, $todo->tone === 'lava' ? 'lava' : ''));
        }

        return sprintf('<section class="konto-naechstes" aria-labelledby="konto-naechstes-titel"><h3 class="konto-kleintitel" id="konto-naechstes-titel">%s</h3><ul>%s</ul></section>', Html::e($page->t('Als Nächstes')), $items);
    }

    /** @param  list<AreaSummary>  $summaries */
    private function recent(AccountPage $page, array $summaries): string
    {
        $events = [];
        foreach ($summaries as $summary) {
            array_push($events, ...$summary->events);
        }
        if ($events === []) {
            return '';
        }
        usort($events, static fn (AreaEvent $a, AreaEvent $b): int => $b->at->getTimestamp() <=> $a->at->getTimestamp());
        $items = '';
        foreach (array_slice($events, 0, 5) as $event) {
            $at = Carbon::instance($event->at);
            $at->locale($page->lang);
            $text = $event->href === null ? Html::e($event->text) : sprintf('<a href="%s">%s</a>', Html::e($event->href), Html::e($event->text));
            $items .= sprintf('<li%s><span class="konto-sechseck konto-sechseck--klein%s" aria-hidden="true">%s</span><div><p>%s</p><time datetime="%s" title="%s">%s</time></div></li>',
                $event->tone === 'lava' ? ' class="ist-neu"' : '', $event->tone === '' ? '' : ' konto-sechseck--'.Html::e($event->tone), Icons::svg($event->icon), $text,
                Html::e($at->toIso8601String()), Html::e($at->isoFormat('LLLL')), Html::e($at->diffForHumans()));
        }

        return sprintf('<section class="konto-zuletzt" aria-labelledby="konto-zuletzt-titel"><h3 class="konto-kleintitel" id="konto-zuletzt-titel">%s</h3><ol class="konto-zeitleiste">%s</ol></section>',
            Html::e($page->t('Zuletzt')), $items);
    }

    private function section(AccountPage $page, AccountArea $area, AreaSummary $summary): string
    {
        $anchor = AccountPage::anchor($area->key());
        $tools = implode('', array_map(static fn (AreaAction $a): string => self::button($a, $a->primary ? 'lava' : ''), $summary->tools));

        return sprintf('<section class="konto-bereich konto-tafel%s" id="%s" aria-labelledby="%s-titel" tabindex="-1"><header class="konto-bereich__kopf"><h2 id="%s-titel">%s%s%s</h2>%s</header>%s</section>',
            $summary->wide ? ' konto-bereich--breit' : '', $anchor, $anchor, $anchor, Icons::svg($summary->icon), Html::e($summary->label),
            $summary->caption === '' ? '' : '<small>'.Html::e($summary->caption).'</small>', $tools === '' ? '' : '<div class="konto-bereich__werkzeuge">'.$tools.'</div>', $area->render($page, $summary));
    }

    /** Ein Verweis als Knopf — Art `lava`, `hell` (auf dunklem Grund) oder leer. */
    public static function button(AreaAction $action, string $kind = ''): string
    {
        return sprintf('<a class="konto-knopf%s" href="%s">%s %s</a>', $kind === '' ? '' : ' konto-knopf--'.$kind, Html::e($action->href), Icons::mini($action->icon), Html::e($action->label));
    }

    /**
     * Ein Bereich zur Zeit (TB 63): Läuft gleich nach dem Markup, bevor die Seite gezeichnet wird. Der Anker der Adresse
     * bestimmt den Bereich — ist er ein Element in einem Bereich (`#lizenz-12`), öffnet sich dieser und scrollt hin.
     * Zurück im Browser wechselt den Bereich zurück; nach dem Wechsel geht der Fokus auf die Überschrift.
     */
    private static function tabs(): string
    {
        return '<script>(function(){var k=document.getElementById("konto");if(!k||!k.classList.contains("konto--app")||k.dataset.tafeln){return}k.dataset.tafeln="1";k.classList.add("konto--tafeln");'
            .'var tafeln=[].slice.call(k.querySelectorAll(".konto-tafel")),nav=[].slice.call(k.querySelectorAll(".konto-nav [data-konto-tafel]"));'
            .'function zeige(fokus){var h=decodeURIComponent(location.hash.slice(1)),ziel=h?document.getElementById(h):null,tafel=ziel&&k.contains(ziel)?ziel.closest(".konto-tafel"):null;'
            .'if(!tafel){tafel=document.getElementById("konto-uebersicht")}if(!tafel){return}'
            .'tafeln.forEach(function(t){t.hidden=t!==tafel});nav.forEach(function(a){var an=a.getAttribute("data-konto-tafel")===tafel.id;a.classList.toggle("ist-aktiv",an);if(an){a.setAttribute("aria-current","page")}else{a.removeAttribute("aria-current")}});'
            .'if(ziel&&ziel!==tafel){ziel.scrollIntoView({block:"start"})}else if(fokus){var top=k.getBoundingClientRect().top+window.scrollY-16;if(window.scrollY>top){window.scrollTo(0,top)}}'
            .'if(fokus){tafel.focus({preventScroll:true})}}'
            .'zeige(false);window.addEventListener("hashchange",function(){zeige(true)})})()</script>';
    }

    /** Kopieren mit Skript: Knöpfe `data-konto-kopieren="<id>"` erscheinen und kopieren Wert oder Text des Ziels. */
    private static function script(): string
    {
        return '<script>document.querySelectorAll("[data-konto-kopieren]").forEach(function(b){if(!navigator.clipboard||b.dataset.kontoAktiv){return}b.dataset.kontoAktiv="1";b.hidden=false;b.addEventListener("click",function(){var t=document.getElementById(b.getAttribute("data-konto-kopieren"));if(!t){return}navigator.clipboard.writeText(t.value!==undefined?t.value:t.textContent.trim()).then(function(){var l=b.querySelector("[data-label]")||b,o=l.textContent;l.textContent=b.getAttribute("data-fertig");b.classList.add("ist-kopiert");setTimeout(function(){l.textContent=o;b.classList.remove("ist-kopiert")},2000)})})})</script>';
    }
}
