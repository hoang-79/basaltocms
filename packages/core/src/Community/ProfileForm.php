<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\Html;
use Illuminate\Http\Request;

/**
 * Das Formular des Community-Profils im Frontend (CR-006 FO-K2): Anzeigename, Profilbild und Signatur, seit TB 40
 * Über mich, Cover-Foto und die eigenen Felder der Site. Module zeigen
 * es dort, wo es passt (das Forum unter `/community/konto`); es sendet an `community/profile` und kommt mit Meldung
 * oder Fehlern auf dieselbe Seite zurück. Nur für angemeldete Konten, darum mit CSRF-Token.
 */
final class ProfileForm
{
    public function __construct(private readonly Profiles $profiles) {}

    public function render(Request $request, Customer $customer, string $pagePath, string $lang, string $heading = 'h2'): string
    {
        $profile = $this->profiles->of($customer);
        $errors = Html::errors($request, 'community');
        $old = Html::old($request);
        $e = Html::e(...);
        $name = is_string($old['name'] ?? null) ? $old['name'] : ($profile->name ?? '');
        $signature = is_string($old['signature'] ?? null) ? $old['signature'] : ($profile->signature ?? '');
        $status = $request->hasSession() ? $request->session()->get('community.profile_status') : null;
        $avatar = $profile?->avatarUrl();
        $picture = $avatar !== null
            ? sprintf('<img class="community-avatar community-avatar--gross" src="%s" alt="" width="96" height="96">', $e($avatar))
            : sprintf('<span class="community-avatar community-avatar--gross" aria-hidden="true">%s</span>', $e($profile?->initials() ?? '?'));

        $html = sprintf('<section class="community-profile-form" aria-labelledby="community-profil-titel"><%1$s id="community-profil-titel">%2$s</%1$s>', $heading, $e(__('Ihr Profil', [], $lang)));
        $html .= is_string($status) ? Html::status($status, 'community-status') : '';
        $html .= $profile === null ? sprintf('<p class="community-hint">%s</p>', $e(__('Wählen Sie einen Anzeigenamen — unter ihm erscheinen Ihre Beiträge. Die E-Mail-Adresse bleibt privat.', [], $lang))) : '';
        $html .= sprintf('<form class="form community-form" method="post" action="%s" enctype="multipart/form-data" novalidate>', $e(route('basalto.community.profile', absolute: false)));
        $html .= Html::hidden($pagePath, $lang, true).Html::errorSummary($errors, $lang);
        $html .= Html::field('community-name', 'name', $e(__('Anzeigename', [], $lang)).Html::required(),
            sprintf('<input id="community-name" name="name" type="text" value="%s" required minlength="%d" maxlength="%d" autocomplete="nickname"{aria}>', $e($name), Profiles::MIN, Profiles::MAX),
            __('3 bis 30 Zeichen, einmalig in dieser Community.', [], $lang), $errors);
        $html .= sprintf('<div class="community-avatar-field">%s', $picture);
        $html .= Html::field('community-avatar', 'avatar', $e(__('Profilbild', [], $lang)),
            '<input id="community-avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp"{aria}>', __('JPG, PNG oder WebP bis 4 MB — wird quadratisch zugeschnitten.', [], $lang), $errors);
        if ($avatar !== null) {
            $html .= sprintf('<label class="community-check"><input type="checkbox" name="avatar_delete" value="1"> %s</label>', $e(__('Profilbild entfernen', [], $lang)));
        }
        $html .= '</div>';
        $html .= Html::field('community-signature', 'signature', $e(__('Signatur', [], $lang)),
            sprintf('<textarea id="community-signature" name="signature" rows="3" maxlength="300"{aria}>%s</textarea>', $e($signature)),
            __('Erscheint unter Ihren Beiträgen, höchstens 300 Zeichen.', [], $lang), $errors);
        // TB 40 (FO-18): Über mich, Cover-Foto und eigene Felder
        $about = is_string($old['about'] ?? null) ? $old['about'] : ($profile->about ?? '');
        $html .= Html::field('community-about', 'about', $e(__('Über mich', [], $lang)),
            sprintf('<textarea id="community-about" name="about" rows="4" maxlength="2000"{aria}>%s</textarea>', $e($about)), __('Erscheint in Ihrem öffentlichen Profil.', [], $lang), $errors);
        $cover = $profile?->coverUrl();
        $html .= Html::field('community-cover', 'cover', $e(__('Cover-Foto', [], $lang)),
            '<input id="community-cover" name="cover" type="file" accept="image/png,image/jpeg,image/webp"{aria}>', __('Breites Bild über dem Profil, JPG, PNG oder WebP bis 6 MB.', [], $lang), $errors);
        if ($cover !== null) {
            $html .= sprintf('<label class="community-check"><input type="checkbox" name="cover_delete" value="1"> %s</label>', $e(__('Cover-Foto entfernen', [], $lang)));
        }
        $values = is_array($old['felder'] ?? null) ? $old['felder'] : ($profile->fields ?? []);
        foreach (app(ProfileFields::class)->definitions($customer->site) as $field) {
            $id = 'community-feld-'.$field['key'];
            $value = is_string($values[$field['key']] ?? null) ? $values[$field['key']] : '';
            if ($field['type'] === 'select') {
                $options = sprintf('<option value="">%s</option>', $e(__('— bitte wählen —', [], $lang)));
                foreach ($field['options'] as $option) {
                    $options .= sprintf('<option value="%1$s"%2$s>%1$s</option>', $e($option), $option === $value ? ' selected' : '');
                }
                $control = sprintf('<select id="%s" name="felder[%s]"%s{aria}>%s</select>', $e($id), $e($field['key']), $field['required'] ? ' required' : '', $options);
            } else {
                $control = $field['type'] === 'textarea'
                    ? sprintf('<textarea id="%s" name="felder[%s]" rows="3" maxlength="500"%s{aria}>%s</textarea>', $e($id), $e($field['key']), $field['required'] ? ' required' : '', $e($value))
                    : sprintf('<input id="%s" name="felder[%s]" type="%s" maxlength="500" value="%s"%s{aria}>', $e($id), $e($field['key']), $field['type'] === 'url' ? 'url' : 'text', $e($value), $field['required'] ? ' required' : '');
            }
            $html .= Html::field($id, 'felder.'.$field['key'], $e($field['label']).($field['required'] ? Html::required() : ''), $control,
                match ($field['visibility']) {
                    'members' => (string) __('Sichtbar für angemeldete Mitglieder.', [], $lang), 'team' => (string) __('Sichtbar nur für das Team.', [], $lang), default => null
                }, $errors);
        }
        // TB 38 (FO-11): Benachrichtigungen und automatisches Abonnieren
        $mode = ! (bool) data_get($customer->preferences, 'notifications.mail', true) ? 'nie' : ((bool) data_get($customer->preferences, 'notifications.digest', false) ? 'taeglich' : 'sofort');
        $mode = is_string($old['mails'] ?? null) ? $old['mails'] : $mode;
        $options = '';
        foreach (['sofort' => __('sofort', [], $lang), 'taeglich' => __('einmal täglich als Zusammenfassung', [], $lang), 'nie' => __('keine', [], $lang)] as $value => $label) {
            $options .= sprintf('<label class="community-check"><input type="radio" name="mails" value="%s"%s> %s</label>', $value, $mode === $value ? ' checked' : '', $e($label));
        }
        $html .= sprintf('<fieldset class="community-fieldset"><legend>%s</legend>%s<p class="form-help">%s</p></fieldset>', $e(__('E-Mails zu Antworten und Erwähnungen', [], $lang)), $options,
            $e(__('In der Community zeigt die Glocke alles Neue — unabhängig von den E-Mails.', [], $lang)));
        // TB 52: wer auf die Pinnwand schreiben darf
        $wall = is_string($old['pinnwand'] ?? null) ? $old['pinnwand'] : app(Wall::class)->mode($customer);
        $options = '';
        foreach (['alle' => __('alle Mitglieder', [], $lang), 'gefolgte' => __('nur Personen, denen ich folge', [], $lang), 'niemand' => __('niemand', [], $lang)] as $value => $label) {
            $options .= sprintf('<label class="community-check"><input type="radio" name="pinnwand" value="%s"%s> %s</label>', $value, $wall === $value ? ' checked' : '', $e($label));
        }
        $html .= sprintf('<fieldset class="community-fieldset"><legend>%s</legend>%s</fieldset>', $e(__('Wer darf auf Ihre Pinnwand schreiben?', [], $lang)), $options);
        $watch = (bool) data_get($customer->preferences, 'community.auto_watch', true);
        $html .= sprintf('<input type="hidden" name="auto_watch" value="0"><label class="community-check"><input type="checkbox" name="auto_watch" value="1"%s> %s</label>', $watch ? ' checked' : '',
            $e(__('Themen automatisch abonnieren, in denen ich schreibe', [], $lang)));
        $html .= sprintf('<p class="form-actions"><button type="submit" class="button">%s</button></p></form></section>', $e(__('Profil speichern', [], $lang)));

        return $html;
    }
}
