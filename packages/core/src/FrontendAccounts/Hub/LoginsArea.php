<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\Passkeys\CustomerPasskey;
use Basalto\Core\FrontendAccounts\Social\SocialButtons;
use Basalto\Core\FrontendAccounts\Social\SocialConnection;
use Basalto\Core\FrontendAccounts\Social\SocialIdentity;
use Basalto\Core\FrontendAccounts\Social\SocialProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bereich «Anmeldung» im Kundenkonto (TB 53): die Passkeys des Kontos (hinzufügen, entfernen) und welche Konten bei
 * Google, Microsoft, GitHub oder LinkedIn verknüpft sind — «Verknüpfen» geht über den Anbieter, «Trennen» löst es.
 * Der Code per Mail bleibt immer.
 */
final class LoginsArea implements AccountArea
{
    /** @var Collection<int, SocialIdentity> */
    private Collection $identities;

    /** @var Collection<int, CustomerPasskey> */
    private Collection $passkeys;

    /** @var list<SocialProvider> */
    private array $providers = [];

    public function __construct()
    {
        $this->identities = new Collection;
        $this->passkeys = new Collection;
    }

    public function key(): string
    {
        return 'zugang';
    }

    public function position(): int
    {
        return 95;
    }

    public function pitch(AccountPage $page): ?AreaPitch
    {
        return null;
    }

    public function summary(AccountPage $page): ?AreaSummary
    {
        if ($page->customer === null) {
            return null;
        }
        $this->providers = SocialConnection::enabledFor($page->site);
        $this->identities = SocialIdentity::query()->where('site_id', $page->site->id)->where('customer_id', $page->customer->id)->orderBy('id')->get();
        $this->passkeys = CustomerPasskey::query()->where('site_id', $page->site->id)->where('customer_id', $page->customer->id)->orderBy('id')->get();
        $parts = array_filter([
            $this->passkeys->isEmpty() ? '' : trans_choice(':count Passkey|:count Passkeys', $this->passkeys->count(), ['count' => $this->passkeys->count()], $page->lang),
            $this->identities->isEmpty() ? '' : trans_choice(':count Konto verknüpft|:count Konten verknüpft', $this->identities->count(), ['count' => $this->identities->count()], $page->lang),
        ]);
        $count = $this->passkeys->count() + $this->identities->count();

        return new AreaSummary($page->t('Anmeldung'), 'login', $count > 0 ? $count : null, $parts === [] ? $page->t('nur Code per Mail') : implode(' · ', $parts));
    }

    public function render(AccountPage $page, AreaSummary $summary): string
    {
        $html = sprintf('<h3 class="konto-kleintitel">%s</h3>', Html::e($page->t('Passkeys'))).$this->passkeyRows($page).$this->passkeyForm($page);
        $accounts = $this->accountRows($page);
        if ($accounts !== '') {
            $html .= sprintf('<h3 class="konto-kleintitel">%s</h3><ul class="konto-zeilen">%s</ul>', Html::e($page->t('Andere Konten')), $accounts);
        }

        return '<div class="konto-zugang">'.$html.sprintf('<p class="konto-tipp">%s %s</p>', Icons::mini('mail'),
            Html::e($page->t('Ein Code per Mail an :email geht immer — auch ohne Passkey und ohne verknüpftes Konto.', ['email' => (string) $page->customer?->email]))).'</div>';
    }

    private function passkeyRows(AccountPage $page): string
    {
        if ($this->passkeys->isEmpty()) {
            return sprintf('<p class="konto-zugang__leer">%s</p>', Html::e($page->t('Mit einem Passkey melden Sie sich per Fingerabdruck, Gesicht oder Gerätesperre an — ohne Code.')));
        }
        $rows = '';
        foreach ($this->passkeys as $passkey) {
            $created = $passkey->created_at === null ? null : Carbon::instance($passkey->created_at);
            $created?->locale($page->lang);
            $used = $passkey->last_used_at === null ? null : Carbon::instance($passkey->last_used_at);
            $used?->locale($page->lang);
            $meta = array_filter([
                $created === null ? null : $page->t('angelegt :date', ['date' => $created->isoFormat('D. MMMM YYYY')]),
                $used === null ? $page->t('noch nie benutzt') : $page->t('zuletzt :time', ['time' => self::ago($used, $page)]),
                is_string($passkey->authenticator) ? $passkey->authenticator : null,
            ]);
            $rows .= sprintf('<li class="konto-zeile" id="passkey-%d"><span class="konto-sechseck konto-sechseck--grau" aria-hidden="true">%s</span><div class="konto-zeile__text"><b>%s</b><small>%s</small></div>'
                .'<div class="konto-knoepfe"><details class="konto-klappe konto-trennen"><summary>%s %s</summary><form class="konto-klappe__inhalt" method="post" action="%s">%s<p>%s</p><button type="submit" class="konto-knopf konto-knopf--gefahr">%s</button></form></details></div></li>',
                $passkey->id, Icons::svg('key'), Html::e($passkey->name), Html::e(implode(' · ', $meta)), Icons::mini('x'), Html::e($page->t('Entfernen')),
                Html::e(route('basalto.account.passkey.destroy', ['key' => $passkey->id], false)), Html::hidden($page->path, $page->lang, true),
                Html::e($page->t('Danach meldet dieser Passkey Sie hier nicht mehr an. Löschen Sie ihn auch auf dem Gerät.')), Html::e($page->t('Passkey entfernen')));
        }

        return '<ul class="konto-zeilen">'.$rows.'</ul>';
    }

    /** «Passkey hinzufügen» — nur mit Skript und wenn der Browser es kann. */
    private function passkeyForm(AccountPage $page): string
    {
        return sprintf('<form class="form konto-zugang__neu" data-konto-passkey-neu data-konto-passkey-zeigen hidden data-optionen="%s" data-speichern="%s">'
            .'<div class="form-field"><label for="konto-passkey-name">%s</label><input type="text" id="konto-passkey-name" name="name" maxlength="60" required placeholder="%s" autocomplete="off"></div>'
            .'<button type="submit" class="konto-knopf" data-laeuft="%s" data-vorhanden="%s" data-geht="%s">%s <span>%s</span></button><p class="account-social-fehler" data-konto-passkey-fehler role="alert" hidden></p></form>%s',
            Html::e(Html::url(route('basalto.account.passkey.registration-options', [], false), ['_sprache' => $page->lang])),
            Html::e(Html::url(route('basalto.account.passkey.store', [], false), ['_seite' => $page->path, '_sprache' => $page->lang])),
            Html::e($page->t('Name des Passkeys')), Html::e($page->t('z. B. iPhone oder Laptop')), Html::e($page->t('Passkey wird angelegt …')),
            Html::e($page->t('Dieser Passkey ist schon angelegt.')), Html::e($page->t('Mit einem Passkey geht es in diesem Browser nicht. Melden Sie sich mit einem Code per Mail an.')),
            Icons::mini('plus'), Html::e($page->t('Passkey hinzufügen')), SocialButtons::styles().SocialButtons::script());
    }

    private function accountRows(AccountPage $page): string
    {
        $rows = '';
        foreach ($this->identities as $identity) {
            $provider = $identity->providerEnum();
            $last = $identity->last_login_at === null ? null : Carbon::instance($identity->last_login_at);
            $last?->locale($page->lang);
            $rows .= sprintf('<li class="konto-zeile" id="zugang-%d"><span class="konto-sechseck konto-sechseck--grau" aria-hidden="true">%s</span><div class="konto-zeile__text"><b>%s</b><small>%s%s</small></div>'
                .'<span class="konto-chip konto-chip--ok">%s %s</span><div class="konto-knoepfe"><details class="konto-klappe konto-trennen"><summary>%s %s</summary><form class="konto-klappe__inhalt" method="post" action="%s">%s<p>%s</p><button type="submit" class="konto-knopf konto-knopf--gefahr">%s</button></form></details></div></li>',
                $identity->id, $provider === null ? Icons::svg('login') : self::mark($provider), Html::e($provider?->label() ?? $identity->provider), Html::e((string) $identity->email),
                $last === null ? '' : Html::e(' · '.$page->t('zuletzt :time', ['time' => self::ago($last, $page)])), Icons::mini('link'), Html::e($page->t('verknüpft')),
                Icons::mini('unlink'), Html::e($page->t('Trennen')), Html::e(route('basalto.account.social.unlink', ['identity' => $identity->id], false)),
                Html::hidden($page->path, $page->lang, true), Html::e($page->t('Danach melden Sie sich hier nicht mehr über :provider an. Der Code per Mail geht weiter.', ['provider' => $provider?->label() ?? $identity->provider])),
                Html::e($page->t('Verknüpfung trennen')));
        }
        $linked = $this->identities->pluck('provider')->all();
        foreach ($this->providers as $provider) {
            if (in_array($provider->value, $linked, true)) {
                continue;
            }
            $rows .= sprintf('<li class="konto-zeile"><span class="konto-sechseck konto-sechseck--grau" aria-hidden="true">%s</span><div class="konto-zeile__text"><b>%s</b><small>%s</small></div><div class="konto-knoepfe"><a class="konto-knopf" href="%s">%s %s</a></div></li>',
                self::mark($provider), Html::e($provider->label()), Html::e($page->t('nicht verknüpft')),
                Html::e(SocialButtons::url($provider, $page->lang, $page->path, ['_verknuepfen' => '1'])), Icons::mini('link'), Html::e($page->t('Verknüpfen')));
        }

        return $rows;
    }

    /** Das Zeichen des Anbieters im Sechseck — mit der Klasse der Symbole, damit es über dem Grund liegt. */
    private static function mark(SocialProvider $provider): string
    {
        return str_replace('<svg ', '<svg class="konto-icon" ', $provider->icon());
    }

    /** «vor 5 Minuten» — in der ersten Minute «gerade eben» statt «vor 0 Sekunden». */
    private static function ago(Carbon $time, AccountPage $page): string
    {
        return abs($time->diffInSeconds(now())) < 60 ? $page->t('gerade eben') : $time->diffForHumans();
    }
}
