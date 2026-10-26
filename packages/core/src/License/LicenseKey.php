<?php

declare(strict_types=1);

namespace Basalto\Core\License;

use Carbon\CarbonImmutable;

/**
 * Der Inhalt eines Lizenzschlüssels (K-18, Option 2): Stufe, Lizenznehmer, gebundene Domains, Benutzer- und Site-Grenze,
 * Update-Anspruch bis. Format «BSL1.<base64url(json)>.<base64url(ed25519-signatur)>» — prüfbar ohne Verbindung.
 *
 * Agentur (G-02): Ein Agentur-Schlüssel ohne Domains gilt für alle Sites der Agentur; je Kunde stellt basaltocms.com eine
 * Unterlizenz aus (`agency` = Agentur, `customer` = Kunde, Domains des Kunden). `white_label` blendet im Backend
 * die Marke Basalto zugunsten der Agentur aus.
 */
final class LicenseKey
{
    public const PREFIX = 'BSL1';

    public const PLAN_FREE = 'free';

    public const PLAN_SITE = 'site';

    public const PLAN_AGENCY = 'agency';

    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public readonly string $id,
        public readonly string $plan,
        public readonly string $licensee,
        public readonly array $domains,
        public readonly ?int $seats,
        public readonly ?int $sites,
        public readonly CarbonImmutable $issuedAt,
        public readonly ?CarbonImmutable $updatesUntil,
        public readonly ?string $agency = null,
        public readonly ?string $customer = null,
        public readonly bool $whiteLabel = false,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function fromPayload(array $payload): self
    {
        $domains = [];
        foreach (is_array($payload['domains'] ?? null) ? $payload['domains'] : [] as $domain) {
            if (is_string($domain) && $domain !== '') {
                $domains[] = strtolower($domain);
            }
        }

        return new self(
            id: (string) ($payload['id'] ?? ''),
            plan: (string) ($payload['plan'] ?? self::PLAN_FREE),
            licensee: (string) ($payload['licensee'] ?? ''),
            domains: $domains,
            seats: isset($payload['seats']) && is_numeric($payload['seats']) ? (int) $payload['seats'] : null,
            sites: isset($payload['sites']) && is_numeric($payload['sites']) ? (int) $payload['sites'] : null,
            issuedAt: CarbonImmutable::parse((string) ($payload['issued_at'] ?? 'now')),
            updatesUntil: isset($payload['updates_until']) ? CarbonImmutable::parse((string) $payload['updates_until']) : null,
            agency: is_string($payload['agency'] ?? null) && $payload['agency'] !== '' ? $payload['agency'] : null,
            customer: is_string($payload['customer'] ?? null) && $payload['customer'] !== '' ? $payload['customer'] : null,
            whiteLabel: ($payload['white_label'] ?? false) === true,
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $payload = ['v' => 1, 'id' => $this->id, 'plan' => $this->plan, 'licensee' => $this->licensee, 'domains' => $this->domains, 'seats' => $this->seats,
            'sites' => $this->sites, 'issued_at' => $this->issuedAt->toDateString(), 'updates_until' => $this->updatesUntil?->toDateString()];
        // Agentur-Angaben nur, wenn gesetzt — ältere Schlüssel bleiben Byte für Byte gleich
        foreach (['agency' => $this->agency, 'customer' => $this->customer] as $field => $value) {
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }
        if ($this->whiteLabel) {
            $payload['white_label'] = true;
        }

        return $payload;
    }

    public function updatesExpired(): bool
    {
        return $this->updatesUntil !== null && $this->updatesUntil->endOfDay()->isPast();
    }

    public function coversHost(string $host): bool
    {
        $host = strtolower($host);
        foreach ($this->domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return $this->domains === [] && $this->plan === self::PLAN_AGENCY;
    }

    /** Unterlizenz einer Agentur für einen Kunden? */
    public function isSublicense(): bool
    {
        return $this->agency !== null && $this->customer !== null;
    }

    public function planLabel(): string
    {
        return match ($this->plan) {
            self::PLAN_SITE => __('Site'),
            self::PLAN_AGENCY => __('Agentur'),
            default => 'Free',
        };
    }
}
