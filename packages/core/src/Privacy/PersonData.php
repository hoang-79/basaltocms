<?php

declare(strict_types=1);

namespace Basalto\Core\Privacy;

use Basalto\Core\FrontendAccounts\AccountPersonData;
use Basalto\Core\Models\Activity;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Models\Invitation;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Support\JsonText;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Auskunft und Löschung für eine Person (N-05, DSG Art. 25 und 32): Backend-Konto (Profil, Rollen, Protokoll),
 * Formulareingänge aller Sites und Daten aus Modulen (Kommentare), gefunden über die E-Mail-Adresse. Löschen ist endgültig: das Konto verschwindet,
 * Protokoll-Einträge bleiben als Nachweis, aber ohne Person — Name und E-Mail werden darin ersetzt, die IP-Prüfsumme
 * entfällt; Eingänge samt Dateien sind weg. Der letzte Betreiber einer Site lässt sich nicht löschen.
 */
final class PersonData
{
    public const REDACTED = '[gelöscht]';

    /** @return array<string, mixed> alles, was Basalto zu dieser Adresse speichert */
    public function export(string $email): array
    {
        $email = strtolower(trim($email));
        $user = $this->user($email);
        $out = ['email' => $email, 'erstellt' => now()->toIso8601String(), 'konto' => null, 'protokoll' => [], 'formulareingaenge' => []];
        if ($user !== null) {
            $out['konto'] = array_intersect_key($user->toArray(), array_flip(['name', 'first_name', 'last_name', 'email', 'phone', 'phone_country', 'street', 'address_extra',
                'postal_code', 'city', 'country', 'locale', 'timezone', 'preferences', 'created_at', 'deactivated_at']));
            $out['konto']['sites'] = DB::table('site_user')->join('sites', 'sites.id', '=', 'site_user.site_id')->where('site_user.user_id', $user->getKey())
                ->get(['sites.handle', 'sites.name', 'site_user.role'])->map(static fn (object $r): array => (array) $r)->all();
            $out['konto']['passkeys'] = DB::table('passkeys')->where('user_id', $user->getKey())->count();
            $out['konto']['sitzungen'] = DB::table('sessions')->where('user_id', $user->getKey())->count();
            // TB 27: Benachrichtigungen in Glocke und Center
            $out['konto']['benachrichtigungen'] = DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())
                ->orderBy('created_at')->get(['kind', 'data', 'read_at', 'created_at'])
                ->map(static fn (object $r): array => ['art' => $r->kind, 'inhalt' => json_decode((string) $r->data, true), 'gelesen' => $r->read_at, 'zeit' => $r->created_at])->all();
            $out['protokoll'] = Activity::query()->withoutGlobalScopes()->where('user_id', $user->getKey())->orderBy('id')->get(['action', 'site_id', 'created_at', 'before', 'after'])
                ->map(static fn (Activity $a): array => ['aktion' => $a->action, 'site_id' => $a->site_id, 'zeit' => $a->created_at->toIso8601String(), 'vorher' => $a->before, 'nachher' => $a->after])->all();
        }
        foreach ($this->submissions($email) as $submission) {
            $out['formulareingaenge'][] = ['site' => $submission->site_id, 'formular' => $submission->form?->handle, 'zeit' => $submission->created_at?->toIso8601String(),
                'sprache' => $submission->locale, 'seite' => $submission->page_path, 'zustimmung' => $submission->consent_at?->toIso8601String(), 'werte' => $submission->data,
                'dateien' => array_values(array_map(static fn (array $f): string => $f['name'], $submission->files ?? []))];
        }
        foreach ($this->modules() as $source) {
            $out += (array) $source($email, false);
        }
        // TB 13 Scheibe 2: Frontend-Konten des Kerns (Lizenzverkauf, Marktplatz, Mitglieder)
        $out += app(AccountPersonData::class)($email, false);

        return $out;
    }

    /**
     * @return array{konto: bool, protokoll: int, eingaenge: int, weitere: array<string, int>}
     *
     * @throws LastOwnerException wenn die Person der letzte Betreiber einer Site ist
     */
    public function delete(string $email): array
    {
        $email = strtolower(trim($email));
        $user = $this->user($email);
        $activity = 0;
        if ($user !== null) {
            foreach (DB::table('site_user')->where('user_id', $user->getKey())->where('role', SiteRole::Owner->value)->pluck('site_id') as $siteId) {
                if (DB::table('site_user')->where('site_id', $siteId)->where('role', SiteRole::Owner->value)->count() <= 1) {
                    throw new LastOwnerException(__('«:email» ist der einzige Betreiber der Site «:site» — zuerst einen anderen Betreiber bestimmen.', ['email' => $email, 'site' => (string) Site::query()->whereKey($siteId)->value('name')]));
                }
            }
            $names = array_values(array_filter([(string) $user->getAttribute('name'), (string) $user->getAttribute('email')], static fn (string $v): bool => mb_strlen($v) >= 3));
            DB::transaction(function () use ($user, $email, $names, &$activity): void {
                foreach (Activity::query()->withoutGlobalScopes()->where('user_id', $user->getKey())->orWhere(JsonText::column('after', true), 'like', '%'.mb_strtolower($email).'%')
                    ->orWhere(JsonText::column('before', true), 'like', '%'.mb_strtolower($email).'%')->get() as $row) {
                    $row->forceFill(['user_id' => null, 'ip_hash' => null, 'before' => self::redact($row->before, $names), 'after' => self::redact($row->after, $names)])->save();
                    $activity++;
                }
                Invitation::query()->withoutGlobalScopes()->where('email', $email)->delete();
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
                DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())->delete();
                $user->delete();
            });
        }
        $submissions = 0;
        foreach ($this->submissions($email) as $submission) {
            foreach ($submission->files ?? [] as $file) {
                Storage::disk('local')->delete($file['path']);
            }
            $submission->delete();
            $submissions++;
        }

        $more = [];
        // TB 13 Scheibe 2: die Module zuerst — sie finden ihre Daten über die E-Mail des Frontend-Kontos; erst danach
        // anonymisiert der Kern das Konto selbst
        foreach ([...$this->modules(), app(AccountPersonData::class)(...)] as $source) {
            foreach ((array) $source($email, true) as $name => $count) {
                $more[(string) $name] = (int) $count;
            }
        }

        return ['konto' => $user !== null, 'protokoll' => $activity, 'eingaenge' => $submissions, 'weitere' => $more];
    }

    /**
     * CR-011 TB 61: Auskunft aus dem Kundenkonto — nur, was die Person auf dieser Site hinterlassen hat (ohne Backend-Konto
     * und Protokoll, die gehören nicht zur Site).
     *
     * @return array<string, mixed>
     */
    public function exportForSite(string $email, Site $site): array
    {
        return app(PersonScope::class)->within($site, fn (): array => ['site' => ['name' => $site->name, 'adresse' => $site->publicUrl()]] + $this->export($email));
    }

    /**
     * CR-011 TB 61: «Konto löschen» im Kundenkonto — die Daten dieser Site; Rechnungen bleiben ohne Person (OR 958f).
     *
     * @return array{konto: bool, protokoll: int, eingaenge: int, weitere: array<string, int>}
     */
    public function deleteForSite(string $email, Site $site): array
    {
        return app(PersonScope::class)->within($site, fn (): array => $this->delete($email));
    }

    /**
     * Daten aus Modulen (z. B. Kommentare, M-27): Closures mit dem Tag «basalto.person-data», aufgerufen mit der
     * Adresse und ob gelöscht wird — Auskunft liefert je Art eine Liste, Löschung je Art eine Zahl.
     *
     * @return list<Closure(string, bool): array<string, mixed>>
     */
    private function modules(): array
    {
        $out = [];
        foreach (app()->tagged('basalto.person-data') as $source) {
            if ($source instanceof Closure) {
                $out[] = $source;
            }
        }

        return $out;
    }

    /** @return list<FormSubmission> Eingänge, deren E-Mail-Feld diese Adresse trägt */
    private function submissions(string $email): array
    {
        $site = app(PersonScope::class)->siteId();
        $candidates = FormSubmission::query()->withoutGlobalScopes()->with('form')->where(JsonText::column('data', true), 'like', '%'.mb_strtolower($email).'%')
            ->when($site !== null, static fn ($q) => $q->where('site_id', $site))->get();
        $out = [];
        foreach ($candidates as $submission) {
            foreach ($submission->data as $value) {
                if (is_string($value) && strtolower(trim($value)) === $email) {
                    $out[] = $submission;

                    break;
                }
            }
        }

        return $out;
    }

    private function user(string $email): ?Model
    {
        // Im Rahmen einer Site (Kundenkonto) bleibt das Backend-Konto aussen vor
        if (app(PersonScope::class)->siteId() !== null) {
            return null;
        }
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @param  list<string>  $names
     * @return array<string, mixed>|null
     */
    private static function redact(?array $values, array $names): ?array
    {
        if ($values === null || $names === []) {
            return $values;
        }
        array_walk_recursive($values, static function (mixed &$value) use ($names): void {
            if (is_string($value)) {
                $value = str_ireplace($names, self::REDACTED, $value);
            }
        });

        return $values;
    }
}
