<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Community\CommunityTerms;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\PrivacyRequest;
use Illuminate\Support\Collection;

/**
 * Bereich «Datenschutz» im Kundenkonto (CR-011 TB 61) — die Rechte nach DSGVO und DSG zum Selbermachen: Auskunft und
 * Daten herunterladen (Art. 15, 20), Angaben berichtigen (Art. 16), Einwilligungen ansehen und widerrufen (Art. 7
 * Abs. 3), Einschränkung, Widerspruch und weitere Anliegen mit Frist (Art. 18, 21, 12 Abs. 3), Konto löschen (Art. 17)
 * und der Weg zur Aufsichtsbehörde (Art. 77). Alles nur für diese Site.
 */
final class PrivacyArea implements AccountArea
{
    /** @var Collection<int, PrivacyRequest> */
    private Collection $requests;

    public function __construct()
    {
        $this->requests = new Collection;
    }

    public function key(): string
    {
        return 'datenschutz';
    }

    public function position(): int
    {
        return 95;
    }

    public function pitch(AccountPage $page): AreaPitch
    {
        return new AreaPitch('shield', $page->t('Datenschutz'), $page->t('Ihre Daten herunterladen, berichtigen oder löschen — selbst, jederzeit.'));
    }

    public function summary(AccountPage $page): ?AreaSummary
    {
        $customer = $page->customer;
        if ($customer === null) {
            return null;
        }
        $this->requests = PrivacyRequest::query()->withoutGlobalScopes()->where('site_id', $page->site->id)->where('customer_id', $customer->id)->latest('received_at')->limit(10)->get();
        $open = $this->requests->where('status', PrivacyRequest::OPEN)->count();

        return new AreaSummary($page->t('Datenschutz'), 'shield', $open > 0 ? $open : null,
            $open > 0 ? trans_choice(':count offenes Anliegen|:count offene Anliegen', $open, ['count' => $open], $page->lang) : $page->t('Ihre Rechte'));
    }

    public function render(AccountPage $page, AreaSummary $summary): string
    {
        $customer = $page->customer;
        if ($customer === null) {
            return '';
        }
        $lang = $page->lang;
        $t = static fn (string $text, array $replace = []): string => Html::e($page->t($text, $replace));
        $hidden = Html::hidden($page->path, $lang, true);
        $errors = Html::errors($page->request, 'datenschutz');
        $old = Html::old($page->request);

        $html = sprintf('<p class="konto-datenschutz__intro">%s</p>', $t('Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und Widerspruch und können Einwilligungen jederzeit widerrufen. Das meiste erledigen Sie hier selbst; alles andere beantworten wir innert eines Monats.'));

        // Auskunft und Übertragbarkeit
        $html .= sprintf('<h3 class="konto-kleintitel" id="datenschutz-auskunft">%s</h3><p>%s</p><form method="post" action="/account/datenschutz/export" class="konto-knoepfe">%s<button type="submit" class="konto-knopf konto-knopf--lava">%s %s</button></form>',
            $t('Auskunft und Daten herunterladen'), $t('Alles, was wir auf dieser Site zu Ihrem Konto speichern, als Datei (JSON) — maschinenlesbar, zum Mitnehmen zu einem anderen Anbieter.'),
            $hidden, Icons::mini('download'), $t('Meine Daten herunterladen'));

        // Berichtigung
        $html .= sprintf('<h3 class="konto-kleintitel" id="datenschutz-angaben">%s</h3>', $t('Angaben berichtigen'));
        $field = static fn (string $name, string $label, string $value, string $autocomplete, int $max): string => Html::field('datenschutz-'.$name, $name, Html::e($label),
            sprintf('<input type="text" id="datenschutz-%s" name="%s" value="%s" maxlength="%d" autocomplete="%s"{aria}>', $name, $name, Html::e($value), $max, $autocomplete), null, $errors);
        $value = static fn (string $name, ?string $current): string => is_string($old[$name] ?? null) ? $old[$name] : (string) $current;
        $html .= sprintf('<form method="post" action="/account/datenschutz/angaben" class="form konto-datenschutz__angaben">%s%s<div class="konto-datenschutz__felder">%s%s%s%s%s%s</div><div class="konto-knoepfe"><button type="submit" class="konto-knopf">%s %s</button></div></form>',
            $hidden, Html::errorSummary($errors, $lang),
            $field('first_name', $page->t('Vorname'), $value('first_name', $customer->first_name), 'given-name', 80).$field('last_name', $page->t('Nachname'), $value('last_name', $customer->last_name), 'family-name', 80), $field('company', $page->t('Firma'), $value('company', $customer->company), 'organization', 160),
            $field('street', $page->t('Strasse und Nummer'), $value('street', $customer->street), 'street-address', 160),
            $field('postal_code', $page->t('PLZ'), $value('postal_code', $customer->postal_code), 'postal-code', 20),
            $field('city', $page->t('Ort'), $value('city', $customer->city), 'address-level2', 120), $field('country', $page->t('Land (ISO, z. B. CH)'), $value('country', $customer->country), 'country', 2),
            Icons::mini('check'), $t('Angaben speichern'));
        $html .= sprintf('<p class="konto-tipp">%s %s</p>', Icons::mini('mail'), $t('Die E-Mail-Adresse :email ändern wir auf Anfrage — schreiben Sie uns unten ein Anliegen «Berichtigung».', ['email' => $customer->email]));

        // Einwilligungen
        $consents = sprintf('<li class="konto-zeile"><span class="konto-sechseck konto-sechseck--klein konto-sechseck--grau" aria-hidden="true">%s</span><div class="konto-zeile__text"><a href="#cookie-einstellungen">%s</a><small>%s</small></div></li>',
            Icons::svg('sparkles'), $t('Cookie-Einstellungen'), $t('Statistik und externe Inhalte — nur mit Ihrer Einwilligung, jederzeit änderbar.'));
        if (app(Backend::class)->moduleEnabled('forum', $page->site) && app(CommunityTerms::class)->required($page->site)) {
            $accepted = app(CommunityTerms::class)->current($page->site, $customer);
            $consents .= sprintf('<li class="konto-zeile"><span class="konto-sechseck konto-sechseck--klein konto-sechseck--grau" aria-hidden="true">%s</span><div class="konto-zeile__text"><a href="#bedingungen">%s</a><small>%s</small></div></li>',
                Icons::svg('chat'), $t('Bedingungen der Community'), $accepted === null ? $t('nicht zugestimmt') : $t('zugestimmt am :date', ['date' => $accepted->accepted_at->timezone($page->site->timezone)->format('d.m.Y')]));
        }
        $html .= sprintf('<h3 class="konto-kleintitel" id="datenschutz-einwilligungen">%s</h3><ul class="konto-zeilen">%s</ul>', $t('Einwilligungen'), $consents);

        // Anliegen
        $options = '';
        foreach (PrivacyRequest::KINDS as $kind => [$label, $article]) {
            $options .= sprintf('<option value="%s"%s>%s (%s)</option>', $kind, ($old['kind'] ?? '') === $kind ? ' selected' : '', Html::e(__($label, [], $lang)), Html::e($article));
        }
        $html .= sprintf('<h3 class="konto-kleintitel" id="datenschutz-anliegen">%s</h3><details class="konto-klappe"%s><summary>%s %s</summary><form class="form konto-klappe__inhalt" method="post" action="/account/datenschutz/anliegen">%s%s%s<div class="konto-knoepfe"><button type="submit" class="konto-knopf">%s %s</button></div></form></details>',
            $t('Einschränkung, Widerspruch und weitere Anliegen'), $errors->has('message') || $errors->has('kind') ? ' open' : '', Icons::mini('mail'), $t('Anliegen schreiben'), $hidden,
            Html::field('datenschutz-art', 'kind', $t('Worum geht es?'), sprintf('<select id="datenschutz-art" name="kind" required{aria}>%s</select>', $options), null, $errors),
            Html::field('datenschutz-text', 'message', $t('Ihr Anliegen').Html::required(), sprintf('<textarea id="datenschutz-text" name="message" rows="4" maxlength="5000" required{aria}>%s</textarea>', Html::e($old['message'] ?? '')),
                $page->t('Wir antworten per E-Mail innert eines Monats.'), $errors),
            Icons::mini('arrow'), $t('Absenden'));
        if ($this->requests->isNotEmpty()) {
            $rows = '';
            foreach ($this->requests as $request) {
                $zone = $page->site->timezone;
                $state = $request->status === PrivacyRequest::DONE
                    ? $page->t('erledigt am :date', ['date' => $request->handled_at?->timezone($zone)->format('d.m.Y') ?? ''])
                    : $page->t('offen — Antwort bis :date', ['date' => $request->due_at->timezone($zone)->format('d.m.Y')]);
                $rows .= sprintf('<li class="konto-zeile"><span class="konto-sechseck konto-sechseck--klein%s" aria-hidden="true">%s</span><div class="konto-zeile__text"><b>%s</b><small>%s · %s</small></div></li>',
                    $request->status === PrivacyRequest::OPEN ? ' konto-sechseck--lava' : ' konto-sechseck--grau', Icons::svg('mail'), Html::e(PrivacyRequest::kindLabel($request->kind, $lang)),
                    Html::e($page->t('eingegangen am :date', ['date' => $request->received_at->timezone($zone)->format('d.m.Y')])), Html::e($state));
            }
            $html .= '<ul class="konto-zeilen">'.$rows.'</ul>';
        }

        // Löschen
        $html .= sprintf('<h3 class="konto-kleintitel" id="datenschutz-loeschen">%s</h3><details class="konto-klappe"%s><summary>%s %s</summary><form class="form konto-klappe__inhalt" method="post" action="/account/datenschutz/loeschen">%s<p>%s</p><ul class="konto-datenschutz__liste"><li>%s</li><li>%s</li><li>%s</li></ul>%s<div class="form-field form-consent"><label><input type="checkbox" name="verstanden" value="1" required> %s</label></div><div class="konto-knoepfe"><button type="submit" class="konto-knopf konto-knopf--gefahr">%s %s</button></div></form></details>',
            $t('Konto löschen'), $errors->has('confirm') || $errors->has('verstanden') || $errors->has('contracts') ? ' open' : '', Icons::mini('x'), $t('Konto und Daten löschen'), $hidden,
            $t('Wir löschen Ihr Konto auf dieser Site sofort:'), $t('Name, Adresse, Anmeldungen, Passkeys und verknüpfte Konten, Profil und Bilder der Community, Kommentare und Formulareingänge.'),
            $t('Beiträge in der Community bleiben ohne Namen stehen, damit Diskussionen lesbar bleiben.'),
            $t('Rechnungen und Buchungen bewahren wir ohne Bezug zu Ihnen zehn Jahre auf, wie es das Gesetz verlangt.'),
            ($errors->has('contracts') ? sprintf('<p class="form-error" role="alert">%s</p>', Html::e($errors->first('contracts'))) : '')
            .Html::field('datenschutz-bestaetigung', 'confirm', $t('Zur Bestätigung Ihre E-Mail-Adresse').Html::required(),
                '<input type="email" id="datenschutz-bestaetigung" name="confirm" required autocomplete="off" spellcheck="false"{aria}>', null, $errors),
            $t('Ich verstehe, dass sich das Löschen nicht rückgängig machen lässt.'), Icons::mini('x'), $t('Endgültig löschen'));

        // Beschwerde
        $html .= sprintf('<p class="konto-tipp">%s %s</p>', Icons::mini('shield'),
            $t('Sie können sich jederzeit bei einer Aufsichtsbehörde beschweren — in der Schweiz beim Eidgenössischen Datenschutz- und Öffentlichkeitsbeauftragten (EDÖB), in der EU bei der Behörde Ihres Wohnsitzlands.'));

        return '<div class="konto-datenschutz">'.$html.'</div>';
    }
}
