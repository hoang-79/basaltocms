<?php

declare(strict_types=1);

namespace Basalto\Core\License;

use Basalto\Core\Models\LicenseState;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Der Stand einer Installation gegenüber ihrer Lizenz (Option 2, D-12): Stufe, Grenzen, Hinweise — nie eine Sperre.
 * Ohne Schlüssel gilt Free (ein Backend-Benutzer, eine Site); Verstösse machen die Installation «unregistriert»,
 * und nach {@see bannerDays()} Tagen zeigt das Frontend einen Hinweis.
 */
final class LicenseStatus
{
    /** TB 12 Scheibe 1: Stufe einer SaaS-Installation — die Lizenz steckt im Abo des Kontos. */
    public const PLAN_SAAS = 'saas';

    /** Einstellung der Installation, in der das Backend den Schlüssel ablegt. */
    public const SETTING = 'license.key';

    /**
     * @param  list<string>  $hints
     */
    private function __construct(
        public readonly ?LicenseKey $key,
        public readonly string $plan,
        public readonly bool $registered,
        public readonly bool $compliant,
        public readonly int $users,
        public readonly int $sites,
        public readonly array $hints,
        public readonly ?CarbonImmutable $unregisteredSince = null,
    ) {}

    /**
     * Reine Bewertung ohne Datenbank — Verstösse zuerst, dann Hinweise.
     *
     * @param  list<string>  $hosts
     */
    public static function evaluate(LicenseVerifier $verifier, ?string $installedKey, int $users, int $sites, array $hosts, ?LicenseRevocations $revocations = null): self
    {
        $key = $verifier->verify($installedKey);
        $violations = [];
        $hints = [];
        if ($installedKey !== null && $installedKey !== '' && $key === null) {
            $hints[] = __('Der eingetragene Lizenzschlüssel ist ungültig (Format oder Signatur) — Free gilt, bis ein gültiger Schlüssel eingetragen ist.');
        }
        if ($key !== null && ($revocations ?? LicenseRevocations::default())->revoked($key->id)) {
            $hints[] = __('Der Lizenzschlüssel :id ist gesperrt — Free gilt, bis ein gültiger Schlüssel eingetragen ist. Fragen an :email.', ['id' => $key->id, 'email' => Vendor::email()]);
            $key = null;
        }
        $plan = $key !== null ? $key->plan : LicenseKey::PLAN_FREE;
        $label = $key !== null ? $key->planLabel() : 'Free';
        $seats = $key?->seats;
        if ($seats === null && $plan === LicenseKey::PLAN_FREE) {
            $seats = 1;
        }
        $siteLimit = $key?->sites;
        if ($siteLimit === null) {
            $siteLimit = match ($plan) {
                LicenseKey::PLAN_FREE => 1,
                LicenseKey::PLAN_SITE => 3,
                default => null,
            };
        }
        if ($seats !== null && $users > $seats) {
            $violations[] = __(':users Backend-Benutzer, die Stufe :plan erlaubt :seats — für den kommerziellen Einsatz ist eine Site- oder Agentur-Lizenz nötig (unregistriert).', ['users' => $users, 'plan' => $label, 'seats' => $seats]);
        }
        if ($siteLimit !== null && $sites > $siteLimit) {
            $violations[] = __(':sites Sites, die Stufe :plan erlaubt :limit (unregistriert).', ['sites' => $sites, 'plan' => $label, 'limit' => $siteLimit]);
        }
        if ($key !== null && $key->plan === LicenseKey::PLAN_SITE) {
            $uncovered = array_values(array_filter($hosts, static fn (string $host): bool => ! $key->coversHost($host)));
            if ($uncovered !== []) {
                $violations[] = __('Domains ohne Lizenz: :domains — der Schlüssel gilt für :licensed (unregistriert).', ['domains' => implode(', ', $uncovered), 'licensed' => implode(', ', $key->domains)]);
            }
        }
        if ($key !== null && $key->updatesExpired()) {
            $hints[] = __('Der Update-Anspruch ist am :date abgelaufen — die Software läuft weiter, neue Versionen brauchen eine Verlängerung.', ['date' => (string) $key->updatesUntil?->format('d.m.Y')]);
        }
        if ($key === null && $violations === [] && $hints === []) {
            $hints[] = __('Free: ein Backend-Benutzer, eine Site, alle Module. Für den kommerziellen Einsatz Lizenz auf :vendor.', ['vendor' => Vendor::host()]);
        }
        $compliant = $violations === [];

        return new self($key, $plan, $key !== null && $compliant, $compliant, $users, $sites, [...$violations, ...$hints]);
    }

    /** Stand der laufenden Installation (Benutzer mit Site, Sites, Produktionsdomains) — und hält den Verstoss in `license_state` fest. */
    public static function current(): self
    {
        // TB 12 Scheibe 1: Auf der SaaS gilt keine Download-Lizenz — die Lizenz steckt im Abo; keine Zählung über alle Kunden
        if (Saas::enabled()) {
            LicenseState::current()->record(true);

            return new self(null, self::PLAN_SAAS, true, true, 0, 0, [__('Die Lizenz ist im Abo der SaaS enthalten — Plan und Kontingente unter Verwaltung › Konto.')]);
        }
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $users = (int) $userModel::query()->whereHas('sites')->count();
        $sites = Site::query()->count();
        $hosts = [];
        foreach (SiteDomain::query()->where('kind', SiteDomain::KIND_CUSTOM)->pluck('host') as $host) {
            if (is_string($host)) {
                $hosts[] = $host;
            }
        }
        $status = self::evaluate(LicenseVerifier::fromBase64(config('basalto.license.public_key')), self::installedKey(), $users, $sites, $hosts);
        $state = LicenseState::current();
        $state->record($status->compliant);

        return new self($status->key, $status->plan, $status->registered, $status->compliant, $status->users, $status->sites, $status->hints, $state->unregistered_since);
    }

    /** Der eingetragene Schlüssel: aus dem Backend (Einstellung der Installation), sonst aus `BASALTO_LICENSE_KEY`. */
    public static function installedKey(): ?string
    {
        $stored = app(Settings::class)->get(self::SETTING);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        $env = config('basalto.license.key');

        return is_string($env) && $env !== '' ? $env : null;
    }

    /**
     * White-Label (G-02, Agentur): der Name der Agentur, wenn ein gültiger, nicht gesperrter Schlüssel es erlaubt — sonst
     * null und das Backend zeigt die Marke Basalto. Nur Signaturprüfung, keine Zählungen (läuft bei jeder Anfrage).
     */
    public static function brand(): ?string
    {
        $key = LicenseVerifier::fromBase64(config('basalto.license.public_key'))->verify(self::installedKey());
        if ($key === null || ! $key->whiteLabel || $key->agency === null || LicenseRevocations::default()->revoked($key->id)) {
            return null;
        }

        return $key->agency;
    }

    /** Woher der Schlüssel kommt — für die Lizenzseite. */
    public static function keySource(): ?string
    {
        $stored = app(Settings::class)->get(self::SETTING);
        if (is_string($stored) && $stored !== '') {
            return 'backend';
        }

        return is_string(config('basalto.license.key')) && config('basalto.license.key') !== '' ? 'env' : null;
    }

    public static function bannerDays(): int
    {
        return max(0, (int) config('basalto.license.banner_days', 30));
    }

    /** Ab wann das Frontend den Hinweis zeigt — null, solange die Installation regelkonform ist. */
    public function bannerFrom(): ?CarbonImmutable
    {
        return $this->unregisteredSince?->addDays(self::bannerDays());
    }

    public function bannerActive(): bool
    {
        $from = $this->bannerFrom();

        return $from !== null && $from->isPast();
    }

    public function standLabel(): string
    {
        return $this->registered ? __('registriert') : ($this->compliant ? __('Free, ohne Schlüssel') : __('unregistriert'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'plan_label' => $this->key?->planLabel() ?? ($this->plan === self::PLAN_SAAS ? 'SaaS' : 'Free'),
            'licensee' => $this->key?->licensee,
            'registered' => $this->registered,
            'compliant' => $this->compliant,
            'stand' => $this->standLabel(),
            'users' => $this->users,
            'sites' => $this->sites,
            'updates_until' => $this->key?->updatesUntil?->toDateString(),
            'hints' => $this->hints,
            'banner_days' => self::bannerDays(),
            'banner_from' => $this->bannerFrom()?->toDateString(),
            'banner_active' => $this->bannerActive(),
        ];
    }
}
