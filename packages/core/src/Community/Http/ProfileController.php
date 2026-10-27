<?php

declare(strict_types=1);

namespace Basalto\Core\Community\Http;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\Covers;
use Basalto\Core\Community\ProfileFields;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\Community\Wall;
use Basalto\Core\FrontendAccounts\AutosaveResponse;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Avatars;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Community-Profil (CR-006 FO-K2): speichern aus dem Frontend und das Profilbild ausliefern.
 */
final class ProfileController
{
    public function __construct(private readonly Profiles $profiles) {}

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $lang = is_string($request->input('_sprache')) ? (string) $request->input('_sprache') : app()->getLocale();
        $back = Html::pagePath($request->input('_seite'));
        $profile = $this->profiles->of($customer);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string'],
            'signature' => ['nullable', 'string', 'max:300'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'about' => ['nullable', 'string', 'max:2000'],
            'cover' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'felder' => ['nullable', 'array'],
        ], [], ['name' => __('Anzeigename', [], $lang), 'signature' => __('Signatur', [], $lang), 'avatar' => __('Profilbild', [], $lang), 'about' => __('Über mich', [], $lang),
            'cover' => __('Cover-Foto', [], $lang)]);
        $fieldValues = [];
        $fieldErrors = [];
        foreach (app(ProfileFields::class)->definitions($customer->site) as $field) {
            $value = trim((string) (is_array($request->input('felder')) ? ($request->input('felder')[$field['key']] ?? '') : ''));
            if ($value === '' && $field['required']) {
                $fieldErrors['felder.'.$field['key']] = __(':field fehlt.', ['field' => $field['label']], $lang);
            } elseif ($value !== '' && $field['type'] === 'select' && ! in_array($value, $field['options'], true)) {
                $fieldErrors['felder.'.$field['key']] = __(':field: bitte eine der Möglichkeiten wählen.', ['field' => $field['label']], $lang);
            } elseif ($value !== '' && $field['type'] === 'url' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $fieldErrors['felder.'.$field['key']] = __(':field ist keine gültige Adresse.', ['field' => $field['label']], $lang);
            } elseif ($value !== '') {
                $fieldValues[$field['key']] = mb_substr($value, 0, 500);
            }
        }
        $validator->after(function ($validator) use ($request, $customer, $profile, $lang): void {
            $error = $this->profiles->nameError($customer->site, (string) $request->input('name', ''), $profile?->id, $profile?->isTeam() ?? false, $lang);
            if ($error !== null) {
                $validator->errors()->add('name', $error);
            }
        });
        $validator->after(static function ($validator) use ($fieldErrors): void {
            foreach ($fieldErrors as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });
        if ($validator->fails()) {
            return AutosaveResponse::wanted($request) ? AutosaveResponse::invalid($validator->errors())
                : redirect($back)->withErrors($validator, 'community')->withInput($request->except(['avatar', 'cover']));
        }
        $profile = $this->profiles->save($customer, (string) $request->input('name'), is_string($request->input('signature')) ? (string) $request->input('signature') : null);
        $about = trim((string) $request->input('about', ''));
        $profile->forceFill(['about' => $about === '' ? null : $about, 'fields' => $fieldValues === [] ? null : $fieldValues])->save();
        // TB 38 (FO-11): E-Mails sofort, täglich oder nie; Themen automatisch abonnieren
        $mails = in_array($request->input('mails'), ['sofort', 'taeglich', 'nie'], true) ? (string) $request->input('mails') : 'sofort';
        $preferences = is_array($customer->preferences) ? $customer->preferences : [];
        $preferences['notifications'] = [...(is_array($preferences['notifications'] ?? null) ? $preferences['notifications'] : []), 'mail' => $mails !== 'nie', 'digest' => $mails === 'taeglich'];
        $preferences['community'] = [...(is_array($preferences['community'] ?? null) ? $preferences['community'] : []), 'auto_watch' => $request->boolean('auto_watch', true),
            // TB 52: Pinnwand
            'wall' => in_array($request->input('pinnwand'), Wall::MODES, true) ? (string) $request->input('pinnwand') : data_get($preferences, Wall::PREFERENCE, 'alle')];
        $customer->forceFill(['preferences' => $preferences])->save();
        $file = $request->file('avatar');
        $cover = $request->file('cover');
        try {
            if ($file !== null && ! is_array($file)) {
                Avatars::store($profile, $file, 'community-avatars');
            } elseif ($request->boolean('avatar_delete')) {
                Avatars::delete($profile);
            }
            if ($cover !== null && ! is_array($cover)) {
                Covers::store($profile, $cover);
            } elseif ($request->boolean('cover_delete')) {
                Covers::delete($profile);
            }
        } catch (RuntimeException) {
            $unreadable = new MessageBag(['avatar' => __('Das Bild liess sich nicht lesen.', [], $lang)]);

            return AutosaveResponse::wanted($request) ? AutosaveResponse::invalid($unreadable)
                : redirect($back)->withErrors($unreadable, 'community')->withInput($request->except(['avatar', 'cover']));
        }
        if (AutosaveResponse::wanted($request)) {
            // Ein neues oder entferntes Bild erscheint an mehreren Stellen — dann lädt die Seite neu
            $pictures = ($file !== null && ! is_array($file)) || ($cover !== null && ! is_array($cover)) || $request->boolean('avatar_delete') || $request->boolean('cover_delete');

            return AutosaveResponse::saved(__('Profil gespeichert.', [], $lang), reload: $pictures);
        }

        return redirect($back)->with('community.profile_status', __('Profil gespeichert.', [], $lang));
    }

    /** Anzeigenamen für Erwähnungen: beginnt mit der Eingabe, höchstens acht, Team zuerst. */
    public function search(Request $request): JsonResponse
    {
        $customer = CustomerAuth::current();
        abort_if($customer === null, 403);
        $query = Profiles::key(mb_substr((string) $request->query('q', ''), 0, 30));
        if ($query === '') {
            return response()->json([]);
        }
        $found = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $customer->site_id)->whereRaw('SUBSTR(name_key, 1, ?) = ?', [mb_strlen($query), $query])
            ->orderByRaw('user_id is null')->orderBy('name')->limit(8)->get();
        $people = array_values($found->map(static fn (CommunityProfile $p): array => ['id' => $p->id, 'name' => $p->name, 'avatar' => $p->avatarUrl()])->all());
        // TB 52: wer darf, bekommt passende Gruppen dazu (vor den Personen)
        $site = $customer->site;
        $groups = [];
        if ($site !== null && app(VisitorAccess::class)->allows($site, $customer, VisitorGroups::MENTION_PERMISSION)) {
            foreach (app(VisitorGroups::class)->mentionable($site) as $group) {
                if (str_starts_with(Profiles::key((string) $group->name), $query)) {
                    $groups[] = ['id' => $group->id, 'name' => (string) $group->name, 'gruppe' => true];
                }
            }
        }

        return response()->json([...array_slice($groups, 0, 3), ...$people]);
    }

    /** TB 40: das Cover-Foto — wie das Profilbild ohne Sitzung, nur aus der Site des Hosts. */
    public function cover(int $profile, string $file): Response
    {
        return $this->image($profile, $file, 'cover_path');
    }

    /** Ohne Sitzung und ohne Routenbindung: die Site aus dem Host, das Profil nur aus ihr. */
    public function avatar(int $profile, string $file): Response
    {
        return $this->image($profile, $file, 'avatar_path');
    }

    private function image(int $profile, string $file, string $column): Response
    {
        $site = app(CurrentSite::class)->id();
        $path = $site === null ? null : CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site)->whereKey($profile)->value($column);
        abort_unless(is_string($path) && basename($path) === $file && Storage::disk()->exists($path), 404);

        return response((string) Storage::disk()->get($path), 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
