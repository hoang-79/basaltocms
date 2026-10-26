<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Models\Account;

/**
 * Ein Plan der SaaS mit Preis, Instanz und Kontingenten (`basalto.saas.plans`, Mockup 23): Sites, eigene Domains,
 * Speicher und Benutzer. Kontingente sind Hinweise, nie eine Sperre der Inhalte (ADR-008).
 */
final readonly class Plan
{
    public function __construct(
        public string $key,
        public string $instance,
        public int $price,
        public int $sites,
        public int $domains,
        public int $storageMb,
        public int $users,
    ) {}

    public static function find(?string $key): ?self
    {
        $plans = (array) config('basalto.saas.plans', []);
        $plan = $key !== null ? ($plans[$key] ?? null) : null;
        if (! is_array($plan)) {
            return null;
        }

        return new self(
            (string) $key,
            (string) ($plan['instance'] ?? 'start'),
            (int) ($plan['price'] ?? 0),
            (int) ($plan['sites'] ?? 1),
            (int) ($plan['domains'] ?? 0),
            (int) ($plan['storage_mb'] ?? 0),
            (int) ($plan['users'] ?? 1),
        );
    }

    /** @return list<self> Die buchbaren Pläne (ohne Testphase), in der Reihenfolge der Konfiguration. */
    public static function purchasable(): array
    {
        $plans = [];
        foreach (array_keys((array) config('basalto.saas.plans', [])) as $key) {
            if ($key !== Account::PLAN_TRIAL && ($plan = self::find((string) $key)) !== null) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    public function isTrial(): bool
    {
        return $this->key === Account::PLAN_TRIAL;
    }

    public function label(): string
    {
        return match ($this->key) {
            Account::PLAN_TRIAL => __('Testphase'),
            Account::PLAN_START => 'Start',
            Account::PLAN_STANDARD => 'Standard',
            Account::PLAN_PRO => 'Pro',
            default => ucfirst($this->key),
        };
    }

    /** @return array{key: string, label: string, instance: string, price: int, sites: int, domains: int, storage_mb: int, users: int} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label(), 'instance' => $this->instance, 'price' => $this->price, 'sites' => $this->sites,
            'domains' => $this->domains, 'storage_mb' => $this->storageMb, 'users' => $this->users];
    }
}
