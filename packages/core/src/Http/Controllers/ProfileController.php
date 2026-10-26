<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Auth\ApiTokenException;
use Basalto\Core\Auth\ApiTokens;
use Basalto\Core\Auth\Sessions;
use Basalto\Core\Models\ApiToken;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\CoreNotifications;
use Basalto\Core\Notifications\NotificationSettings;
use Basalto\Core\Notifications\NotificationTypes;
use Basalto\Core\Privacy\PersonData;
use Basalto\Core\Rules\PhoneNumber;
use Basalto\Core\Rules\PostalCode;
use Basalto\Core\Support\Avatars;
use Basalto\Core\Support\Countries;
use Basalto\Core\Support\Preferences;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\Contracts\HasApiTokens;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Das eigene Profil (K-03, ausgebaut auf Hoangs Wunsch 16.10.2026): Persönlich (Vor- und Nachname, Telefon je Land),
 * Anschrift (Strasse, Zusatz, PLZ und Ort nach Land), Einstellungen (Sprache, Zeitzone, Datums- und Zeitformat,
 * Startseite — alle mit Wirkung im Backend), Sitzungen, API-Tokens je Site (M-17). Jeder Abschnitt speichert für sich.
 */
final class ProfileController
{
    private const LOCALES = ['de' => 'Deutsch', 'en' => 'English', 'it' => 'Italiano', 'fr' => 'Français'];

    public function __construct(private readonly Sessions $sessions, private readonly Recorder $recorder, private readonly ApiTokens $tokens) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $prefs = Preferences::of($user instanceof Authenticatable ? $user : null);
        $now = CarbonImmutable::now((string) ($user->getAttribute('timezone') ?? 'Europe/Zurich'));
        $labels = Preferences::labels();
        $phoneCountry = (string) ($user->getAttribute('phone_country') ?? $user->getAttribute('country') ?? 'CH');
        // Konten ohne getrennte Namen (Installer, ältere Konten): Vorschlag aus «name», das letzte Wort ist der Nachname
        $first = (string) ($user->getAttribute('first_name') ?? '');
        $last = (string) ($user->getAttribute('last_name') ?? '');
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) $user->getAttribute('name'))) ?: [];
            $last = count($parts) > 1 ? (string) array_pop($parts) : '';
            $first = implode(' ', $parts);
        }

        return Inertia::render('core::Profile', [
            'user' => [
                'first_name' => $first, 'last_name' => $last,
                'name' => (string) $user->getAttribute('name'), 'email' => (string) $user->getAttribute('email'),
                'phone' => Countries::formatPhone($user->getAttribute('phone'), $phoneCountry), 'phone_country' => $phoneCountry,
                'street' => (string) ($user->getAttribute('street') ?? ''), 'address_extra' => (string) ($user->getAttribute('address_extra') ?? ''),
                'postal_code' => (string) ($user->getAttribute('postal_code') ?? ''), 'city' => (string) ($user->getAttribute('city') ?? ''),
                'country' => (string) ($user->getAttribute('country') ?? 'CH'),
                'locale' => (string) ($user->getAttribute('locale') ?? 'de'), 'timezone' => (string) ($user->getAttribute('timezone') ?? 'Europe/Zurich'),
                'date_format' => $prefs['date_format'], 'time_format' => $prefs['time_format'], 'start_page' => $prefs['start_page'],
                'per_page' => $prefs['per_page'], 'list_sort' => $prefs['list_sort'], 'help_shortcuts' => $prefs['help_shortcuts'], 'theme' => $prefs['theme'],
                'avatar_url' => Avatars::url($user),
            ],
            'countries' => array_map(static fn (array $c): array => ['code' => $c['code'], 'name' => $c['name'], 'dial' => $c['dial'], 'min' => $c['min'], 'max' => $c['max'],
                'groups' => $c['groups'], 'keep_zero' => $c['keep_zero'], 'postal_example' => $c['postal_example'], 'postal_numeric' => $c['postal_numeric'], 'postal_max' => $c['postal_max']], Countries::ordered()),
            'locales' => array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::LOCALES), array_values(self::LOCALES)),
            'timezones' => DateTimeZone::listIdentifiers(),
            'date_formats' => array_map(static fn (string $f): array => ['value' => $f, 'label' => $labels['date_formats'][$f] ?? Preferences::DATE_FORMATS[$f], 'example' => $now->format($f)], array_keys(Preferences::DATE_FORMATS)),
            'time_formats' => array_map(static fn (string $f): array => ['value' => $f, 'label' => $labels['time_formats'][$f] ?? Preferences::TIME_FORMATS[$f], 'example' => $now->format($f)], array_keys(Preferences::TIME_FORMATS)),
            'start_pages' => array_map(static fn (string $key, array $p): array => ['value' => $key, 'label' => $labels['start_pages'][$key] ?? $p['label']], array_keys(Preferences::START_PAGES), array_values(Preferences::START_PAGES)),
            'per_page_options' => array_map(static fn (int $n): array => ['value' => (string) $n, 'label' => (string) $n], Preferences::PER_PAGE),
            'list_sorts' => array_map(static fn (string $key): array => ['value' => $key, 'label' => $labels['list_sorts'][$key]], array_keys(Preferences::LIST_SORTS)),
            'themes' => array_map(static fn (string $key): array => ['value' => $key, 'label' => $labels['themes'][$key]], array_keys(Preferences::THEMES)),
            'sessions' => $this->sessions->forUser((int) $user->getKey(), $request->session()->getId()),
            'api_tokens' => $user instanceof HasApiTokens ? $this->tokens->forUser($user) : [],
            'api_sites' => $user instanceof Authenticatable ? Site::query()->forUser($user)->get(['id', 'name'])->map(static fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->name])->values()->all() : [],
            'api_abilities' => ApiToken::ABILITIES,
            // TB 27: Benachrichtigungen — was die Person bekommen kann, und ihre Wahl
            'notification_groups' => $groups = $user instanceof Authenticatable ? app(NotificationTypes::class)->groupedFor($user) : [],
            'notification_settings' => NotificationSettings::form($user, $groups),
            'urls' => ['update' => route('basalto.profile.update'), 'notifications' => route('basalto.profile.notifications'), 'sessions_end_others' => route('basalto.profile.sessions.others'), 'export' => route('basalto.profile.export'),
                'session_end' => route('basalto.profile.sessions.end', ['session' => '__ID__']), 'passkeys' => route('basalto.passkeys'),
                'avatar_store' => route('basalto.profile.avatar.store'), 'avatar_destroy' => route('basalto.profile.avatar.destroy'),
                'tokens_store' => route('basalto.profile.tokens.store'), 'token_destroy' => route('basalto.profile.tokens.destroy', ['token' => '__ID__']), 'api_base' => url('/api/v1')],
            'status' => session('status'),
        ]);
    }

    /** Ein Abschnitt (`section`: personal, address, preferences) — die anderen bleiben, wie sie sind. */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $section = (string) $request->input('section', 'personal');
        abort_unless(in_array($section, ['personal', 'address', 'preferences'], true), 422);
        $countries = Countries::codes();
        $values = match ($section) {
            'personal' => $this->personal($request, $countries),
            'address' => $this->address($request, $countries),
            default => $this->preferences($request, $user),
        };
        $before = [];
        foreach (array_keys($values) as $key) {
            $before[$key] = $user->getAttribute($key);
        }
        $user->forceFill($values)->save();
        $this->recorder->record('user.profile_updated', $user, $before, $values, null, $user instanceof Authenticatable ? $user : null);

        return back()->with('status', match ($section) {
            'personal' => __('Persönliche Angaben gespeichert.'),
            'address' => __('Anschrift gespeichert.'),
            default => __('Einstellungen gespeichert — Zeiten und Daten erscheinen ab jetzt so.'),
        });
    }

    /** Profilbild hochladen (TB 21): JPEG, PNG oder WebP bis 5 MB — gespeichert als 256 × 256 WebP. */
    public function storeAvatar(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['avatar' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp']], [], ['avatar' => __('Profilbild')]);
        $file = $request->file('avatar');
        abort_unless($file instanceof UploadedFile, 422);
        try {
            Avatars::store($user, $file);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['avatar' => __('Das Bild lässt sich nicht lesen. Bitte ein JPEG, PNG oder WebP wählen.')]);
        }
        $this->recorder->record('user.avatar_updated', $user, null, null, null, $user instanceof Authenticatable ? $user : null);

        return back()->with('status', __('Profilbild gespeichert.'));
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        Avatars::delete($user);
        $this->recorder->record('user.avatar_removed', $user, null, null, null, $user instanceof Authenticatable ? $user : null);

        return back()->with('status', __('Profilbild entfernt — es erscheinen wieder die Initialen.'));
    }

    /** Ein Profilbild ausliefern — für Angemeldete; der Dateiname ist die Version, darum lange im Cache. */
    public function avatar(int $user, string $file): StreamedResponse
    {
        $model = config('auth.providers.users.model');
        $owner = is_string($model) && is_subclass_of($model, Model::class) ? $model::query()->find($user) : null;
        $path = $owner?->getAttribute('avatar_path');
        abort_unless(is_string($path) && basename($path) === $file, 404);
        // Speicher nicht erreichbar (Bucket fehlt, Netz): melden, aber kein 500 — die Oberfläche zeigt dann die Initialen
        try {
            $exists = Storage::disk()->exists($path);
        } catch (FilesystemException $e) {
            report($e);
            $exists = false;
        }
        abort_unless($exists, 404);

        return Storage::disk()->response($path, null, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=31536000, immutable']);
    }

    /** Eigene Auskunft (N-05): alles, was Basalto zum eigenen Konto speichert, als JSON. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $email = (string) $request->user()?->getAttribute('email');
        $this->recorder->record('privacy.exported', $request->user() instanceof Model ? $request->user() : null, null, ['email' => $email, 'self' => true]);

        return response()->json(app(PersonData::class)->export($email), 200, ['Content-Disposition' => 'attachment; filename="meine-daten.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * API-Token anlegen (M-17): eine Aktion, kein Autosave — die Antwort ist JSON mit dem Klartext, genau einmal. Er
     * geht weder in die Sitzung noch in die Props der Seite (die Inertia im Verlauf des Browsers ablegt).
     */
    public function storeToken(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user instanceof HasApiTokens && $user instanceof Authenticatable, 403);
        $siteIds = Site::query()->forUser($user)->pluck('id')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'site_id' => ['required', 'integer', Rule::in($siteIds)],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiToken::ABILITIES)],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ], ['site_id.in' => __('Tokens gibt es nur für Sites, deren Mitglied Sie sind.'), 'abilities.required' => __('Mindestens «lesen» oder «schreiben» wählen.'), 'expires_at.after' => __('Das Ablaufdatum liegt in der Zukunft.')],
            ['name' => __('Name'), 'site_id' => __('Site'), 'abilities' => __('Fähigkeiten'), 'expires_at' => __('Ablaufdatum')]);
        $site = Site::query()->findOrFail((int) $data['site_id']);
        $expires = isset($data['expires_at']) ? CarbonImmutable::createFromFormat('Y-m-d', (string) $data['expires_at'], (string) ($user->getAttribute('timezone') ?? 'Europe/Zurich'))?->endOfDay()->utc() : null;
        try {
            $token = $this->tokens->issue($user, $site, trim((string) $data['name']), array_values((array) $data['abilities']), $expires);
        } catch (ApiTokenException $e) {
            throw ValidationException::withMessages(['site_id' => $e->getMessage()]);
        }

        // TB 27: ein neuer Schlüssel zum Konto — die Person sieht es in Glocke und Mail
        CoreNotifications::security($user, 'API-Token «:name» für «:site» angelegt', ['name' => trim((string) $data['name']), 'site' => $site->name]);

        return response()->json(['token' => $token->plainTextToken, 'id' => $token->accessToken->getKey(),
            'message' => __('Token «:name» angelegt — kopieren Sie es jetzt, es erscheint nur dieses eine Mal.', ['name' => (string) $data['name']])], 201);
    }

    /** Widerrufen — sofort ungültig; ein fremdes Token ist «nicht gefunden». */
    public function destroyToken(Request $request, int $token): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user instanceof HasApiTokens && $user instanceof Authenticatable, 403);
        $revoked = $this->tokens->revoke($user, $token);

        return back()->with('status', __('Token «:name» widerrufen.', ['name' => $revoked->name]));
    }

    public function endOthers(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $ended = $this->sessions->end((int) $user->getKey(), $request->session()->getId());

        return back()->with('status', trans_choice(':count andere Sitzung beendet.|:count andere Sitzungen beendet.', $ended, ['count' => $ended]));
    }

    public function end(Request $request, string $session): RedirectResponse
    {
        $user = $this->user($request);
        abort_if($session === $request->session()->getId(), 422, __('Die eigene Sitzung beenden Sie mit «Abmelden».'));
        $ended = $this->sessions->end((int) $user->getKey(), null, $session);
        abort_if($ended === 0, 404);

        return back()->with('status', __('Sitzung beendet.'));
    }

    /**
     * @param  list<string>  $countries
     * @return array<string, mixed>
     */
    private function personal(Request $request, array $countries): array
    {
        $phoneCountry = in_array($request->input('phone_country'), $countries, true) ? (string) $request->input('phone_country') : 'CH';
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone_country' => ['required', Rule::in($countries)],
            'phone' => ['nullable', 'string', 'max:30', new PhoneNumber($phoneCountry)],
        ], [], ['first_name' => __('Vorname'), 'last_name' => __('Nachname'), 'phone_country' => __('Land der Telefonnummer'), 'phone' => __('Telefon')]);
        $phone = is_string($data['phone'] ?? null) && trim($data['phone']) !== '' ? Countries::parsePhone($data['phone'], $phoneCountry) : null;
        $first = trim((string) $data['first_name']);
        $last = trim((string) $data['last_name']);

        return ['first_name' => $first, 'last_name' => $last, 'name' => trim($first.' '.$last),
            'phone' => $phone['e164'] ?? null, 'phone_country' => $phone['country'] ?? $phoneCountry];
    }

    /**
     * @param  list<string>  $countries
     * @return array<string, mixed>
     */
    private function address(Request $request, array $countries): array
    {
        $country = in_array($request->input('country'), $countries, true) ? (string) $request->input('country') : 'CH';
        $filled = $request->filled('street') || $request->filled('postal_code') || $request->filled('city');
        $data = $request->validate([
            'street' => [$filled ? 'required' : 'nullable', 'string', 'max:120'],
            'address_extra' => ['nullable', 'string', 'max:120'],
            'postal_code' => [$filled ? 'required' : 'nullable', 'string', 'max:12', new PostalCode($country)],
            'city' => [$filled ? 'required' : 'nullable', 'string', 'max:80'],
            'country' => ['required', Rule::in($countries)],
        ], [], ['street' => __('Strasse und Nr.'), 'address_extra' => __('Zusatz'), 'postal_code' => __('PLZ'), 'city' => __('Ort'), 'country' => __('Land')]);

        $clean = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $postal = $clean($data['postal_code'] ?? null);

        return ['street' => $clean($data['street'] ?? null), 'address_extra' => $clean($data['address_extra'] ?? null),
            'postal_code' => $postal === null ? null : (in_array($country, ['GB', 'CA', 'NL'], true) ? strtoupper($postal) : $postal),
            'city' => $clean($data['city'] ?? null), 'country' => $country];
    }

    /** @return array<string, mixed> */
    private function preferences(Request $request, Model $user): array
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(self::LOCALES))],
            'timezone' => ['required', 'timezone:all'],
            'date_format' => ['required', Rule::in(array_keys(Preferences::DATE_FORMATS))],
            'time_format' => ['required', Rule::in(array_keys(Preferences::TIME_FORMATS))],
            'start_page' => ['required', Rule::in(array_keys(Preferences::START_PAGES))],
            // TB 21: fehlen sie, bleibt der gespeicherte Wert
            'per_page' => ['sometimes', 'integer', Rule::in(Preferences::PER_PAGE)],
            'list_sort' => ['sometimes', Rule::in(array_keys(Preferences::LIST_SORTS))],
            'help_shortcuts' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', Rule::in(array_keys(Preferences::THEMES))],
        ], [], ['locale' => __('Sprache'), 'timezone' => __('Zeitzone'), 'date_format' => __('Datumsformat'), 'time_format' => __('Zeitformat'), 'start_page' => __('Startseite'),
            'per_page' => __('Einträge pro Seite'), 'list_sort' => __('Sortierung der Listen'), 'help_shortcuts' => __('Tastenkürzel der Hilfe'), 'theme' => __('Erscheinungsbild')]);
        $prefs = is_array($user->getAttribute('preferences')) ? $user->getAttribute('preferences') : [];
        $current = Preferences::of($user instanceof Authenticatable ? $user : null);

        return ['locale' => $data['locale'], 'timezone' => $data['timezone'],
            'preferences' => ['date_format' => $data['date_format'], 'time_format' => $data['time_format'], 'start_page' => $data['start_page'],
                'per_page' => (int) ($data['per_page'] ?? $current['per_page']), 'list_sort' => (string) ($data['list_sort'] ?? $current['list_sort']),
                'help_shortcuts' => array_key_exists('help_shortcuts', $data) ? (bool) $data['help_shortcuts'] : $current['help_shortcuts'],
                'theme' => (string) ($data['theme'] ?? $current['theme'])] + $prefs];
    }

    /** TB 29: Breite der Hilfe-Leiste, wie sie mit der Maus gezogen wurde — gilt für diese Person auf allen Geräten. */
    public function helpWidth(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['width' => ['required', 'integer', 'between:'.Preferences::HELP_WIDTH[0].','.Preferences::HELP_WIDTH[1]]]);
        $prefs = is_array($user->getAttribute('preferences')) ? $user->getAttribute('preferences') : [];
        $user->forceFill(['preferences' => ['help_width' => (int) $data['width']] + $prefs])->save();

        return response()->json(['width' => (int) $data['width']]);
    }

    private function user(Request $request): Model
    {
        $user = $request->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }
}
