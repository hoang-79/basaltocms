<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\FrontendAccounts\Passkeys\CustomerPasskey;
use Basalto\Core\FrontendAccounts\Social\SocialIdentity;
use Basalto\Core\Privacy\PersonScope;
use Illuminate\Support\Facades\DB;

/**
 * Frontend-Konten im Datenschutz des Kerns (N-05, TB 13 Scheibe 2): Auskunft über die Konten je E-Mail (Site, Name,
 * Firma, Adresse, Sprache, Zeitpunkte). Löschen anonymisiert das Konto (E-Mail, Name, Adresse) und löscht die
 * Anmeldecodes, die verknüpften Konten bei Anbietern und die Passkeys (TB 53) — gelöscht wird es nicht, weil Rechnungen und Bestellungen des Lizenzverkaufs darauf zeigen und der
 * Aufbewahrungspflicht unterliegen (OR 958f). `PersonData` ruft das **nach** den Modulen auf: Lizenzverkauf,
 * Marktplatz und Mitglieder finden ihre Daten über die E-Mail des Kontos — erst danach verschwindet sie.
 */
final class AccountPersonData
{
    /** @return array<string, mixed> */
    public function __invoke(string $email, bool $delete): array
    {
        $email = CustomerLogin::normalize($email);
        $customers = Customer::byEmail($email)->get();
        if (! $delete) {
            return $customers->isEmpty() ? [] : ['frontend_konten' => $customers->map(static fn (Customer $c): array => [
                'site' => $c->site_id, 'name' => $c->name, 'firma' => $c->company, 'adresse' => array_values(array_filter([$c->street, $c->postal_code, $c->city, $c->country])),
                'sprache' => $c->locale, 'angelegt' => $c->created_at?->toIso8601String(), 'letzte_anmeldung' => $c->last_login_at?->toIso8601String(),
                // TB 53: verknüpfte Konten bei Anbietern
                'verknuepfte_konten' => SocialIdentity::query()->where('customer_id', $c->id)->orderBy('id')->get()->map(static fn (SocialIdentity $i): array => [
                    'anbieter' => $i->provider, 'kennung' => $i->subject, 'email' => $i->email, 'verknuepft' => $i->created_at?->toIso8601String(), 'zuletzt' => $i->last_login_at?->toIso8601String(),
                ])->all(),
                'passkeys' => CustomerPasskey::query()->where('customer_id', $c->id)->orderBy('id')->get()->map(static fn (CustomerPasskey $p): array => [
                    'name' => $p->name, 'angelegt' => $p->created_at?->toIso8601String(), 'zuletzt' => $p->last_used_at?->toIso8601String(),
                ])->all(),
            ])->all()];
        }
        // TB 53: Verknüpfungen und Passkeys weg — sonst kämen Anbieter und Passkey weiter ins anonymisierte Konto
        $identities = SocialIdentity::query()->whereIn('customer_id', $customers->pluck('id'))->delete();
        $passkeys = CustomerPasskey::query()->whereIn('customer_id', $customers->pluck('id'))->delete();
        // TB 54: Seitenaufrufe bleiben als Zahl, aber ohne Konto
        DB::table('analytics_page_views')->whereIn('customer_id', $customers->pluck('id'))->update(['customer_id' => null]);
        foreach ($customers as $customer) {
            $customer->forceFill(['email' => 'geloescht-'.$customer->id.'@invalid', 'name' => null, 'company' => null, 'street' => null, 'postal_code' => null, 'city' => null,
                'remember_token' => null])->save();
        }
        $site = app(PersonScope::class)->siteId();
        $codes = CustomerLoginCode::query()->withoutGlobalScopes()->where('email', $email)->when($site !== null, static fn ($q) => $q->where('site_id', $site))->delete();

        return $customers->isEmpty() && $codes === 0 ? [] : array_filter(['frontend_konten' => $customers->count(), 'verknuepfte_konten' => $identities, 'passkeys' => $passkeys]);
    }

    /** @return array<string, int> nur, wenn etwas wegkam — Anmeldecodes nach einem Tag */
    public static function cleanup(): array
    {
        $count = CustomerLoginCode::query()->withoutGlobalScopes()->where('created_at', '<', now()->subDay())->delete();

        return $count > 0 ? ['Anmeldecodes' => $count] : [];
    }
}
