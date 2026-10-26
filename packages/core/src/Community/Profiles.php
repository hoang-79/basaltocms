<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use App\Models\User;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Illuminate\Support\Str;

/**
 * Community-Profile (CR-006 FO-K2, FO-K14): Anzeigename prüfen und vorschlagen, Profil anlegen und ändern, Konten mit
 * dem Team verknüpfen. Der Anzeigename ist je Site eindeutig — gleich geschrieben in anderer Gross-/Kleinschreibung
 * oder mit mehreren Leerzeichen gilt als derselbe.
 */
final class Profiles
{
    public const MIN = 3;

    public const MAX = 30;

    /** Namen, die nur das Team tragen darf — sie gäben sich sonst als Betreiber aus. */
    private const RESERVED = ['admin', 'administrator', 'team', 'moderator', 'moderation', 'basalto', 'system', 'support'];

    public function of(Customer $customer): ?CommunityProfile
    {
        return CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->first();
    }

    /** Der Schlüssel für die Eindeutigkeit: klein, Leerzeichen zusammengezogen. */
    public static function key(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    /** Ein Fehlertext in der Sprache der Seite oder null, wenn der Name passt. */
    public function nameError(Site $site, string $name, ?int $exceptProfileId = null, bool $team = false, ?string $locale = null): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < self::MIN || mb_strlen($name) > self::MAX) {
            return (string) __('Der Anzeigename hat :min bis :max Zeichen.', ['min' => self::MIN, 'max' => self::MAX], $locale);
        }
        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/u', $name) !== 1) {
            return (string) __('Erlaubt sind Buchstaben, Ziffern, Leerzeichen, Punkt, Bindestrich und Unterstrich — am Anfang ein Buchstabe oder eine Ziffer.', [], $locale);
        }
        if (! $team && in_array(self::key($name), self::RESERVED, true)) {
            return (string) __('Dieser Name ist dem Team vorbehalten.', [], $locale);
        }
        $taken = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('name_key', self::key($name))
            ->when($exceptProfileId !== null, static fn ($q) => $q->where('id', '!=', $exceptProfileId))->exists();

        return $taken ? (string) __('«:name» ist vergeben — wie wäre «:suggestion»?', ['name' => $name, 'suggestion' => $this->suggestion($site, $name)], $locale) : null;
    }

    /**
     * Ein freier, gültiger Name nahe am Wunsch: «Anna» → «Anna 2», «Anna 3» … Zeichen, die `nameError()` nicht erlaubt,
     * werden zu Leerzeichen («<script>alert()</script>» → «script alert script», «O’Connor» → «O Connor»), am Anfang steht
     * ein Buchstabe oder eine Ziffer; bleibt zu wenig übrig, heisst der Vorschlag «Mitglied». Der bereinigte Name selbst
     * kommt zuerst, wenn er frei ist.
     */
    public function suggestion(Site $site, string $name): string
    {
        $clean = (string) preg_replace(['/[^\p{L}\p{N} ._-]+/u', '/\s+/u'], ' ', $name);
        $base = trim(Str::limit(trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', trim($clean))), self::MAX - 4, ''));
        if (mb_strlen($base) < self::MIN) {
            $base = 'Mitglied';
        }
        $free = static fn (string $candidate): bool => ! CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('name_key', self::key($candidate))->exists();
        if (! in_array(self::key($base), self::RESERVED, true) && $free($base)) {
            return $base;
        }
        for ($i = 2; $i < 1000; $i++) {
            if ($free($base.' '.$i)) {
                return $base.' '.$i;
            }
        }

        return $base.' '.Str::lower(Str::random(4));
    }

    /** Profil anlegen oder ändern (der Name ist vorher mit `nameError` geprüft). */
    public function save(Customer $customer, string $name, ?string $signature = null): CommunityProfile
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $profile = $this->of($customer) ?? new CommunityProfile(['site_id' => $customer->site_id, 'customer_id' => $customer->id]);
        $profile->fill(['name' => $name, 'name_key' => self::key($name), 'signature' => $signature === null || trim($signature) === '' ? null : trim($signature)]);
        $profile->save();

        return $profile;
    }

    /** Der Name, unter dem eine Person erscheint: Profil, sonst der Vorname des Kontos, sonst «Mitglied». */
    public function displayName(?Customer $customer): string
    {
        if ($customer === null) {
            return (string) __('Gast');
        }
        $profile = $this->of($customer);
        if ($profile !== null) {
            return $profile->name;
        }

        return is_string($customer->name) && trim($customer->name) !== '' ? trim($customer->name) : (string) __('Mitglied');
    }

    /** Aktivität merken (höchstens einmal je Minute schreiben). */
    public function touch(CommunityProfile $profile): void
    {
        if ($profile->last_activity_at === null || $profile->last_activity_at->lt(now()->subMinute())) {
            $profile->forceFill(['last_activity_at' => now()])->save();
        }
    }

    /**
     * Ein Team-Benutzer verknüpft sein Kundenkonto auf der Site (FO-K14): das Konto mit derselben E-Mail, sonst neu;
     * das Profil trägt den Namen des Benutzers (frei gemacht, wenn vergeben) und `user_id`.
     */
    public function linkTeam(User $user, Site $site): CommunityProfile
    {
        $customer = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', mb_strtolower((string) $user->email))->first()
            ?? Customer::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'email' => mb_strtolower((string) $user->email), 'name' => $user->name, 'locale' => $site->default_locale]);
        CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('user_id', $user->id)->where('customer_id', '!=', $customer->id)->update(['user_id' => null]);
        $profile = $this->of($customer);
        if ($profile === null) {
            $wanted = trim((string) $user->name) !== '' ? (string) $user->name : 'Team';
            $name = $this->nameError($site, $wanted, null, true) === null ? $wanted : $this->suggestion($site, $wanted);
            $profile = $this->save($customer, $name);
        }
        $profile->forceFill(['user_id' => $user->id])->save();

        return $profile;
    }

    public function unlinkTeam(User $user, Site $site): void
    {
        CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('user_id', $user->id)->update(['user_id' => null]);
    }
}
