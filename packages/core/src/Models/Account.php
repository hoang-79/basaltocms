<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Database\Factories\AccountFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;

/**
 * Das Konto über den Sites (ADR-008): Plan, Abo-Stand, Rechnungsangaben, Kündigung. Eine Download-Installation hat genau
 * ein Konto; auf der SaaS ist das Konto der Kunde.
 *
 * @property int $id
 * @property string $name
 * @property string $plan
 * @property string $status
 * @property ?CarbonImmutable $trial_ends_at
 * @property array<string, mixed>|null $billing
 * @property ?CarbonImmutable $cancelled_at
 * @property ?CarbonImmutable $delete_after
 * @property ?string $instance
 * @property ?CarbonImmutable $terms_accepted_at Zustimmung zu AGB und Datenschutz bei der Registrierung (TB 12 Scheibe 1)
 * @property ?CarbonImmutable $ends_at TB 12 Scheibe 4: Ende der Laufzeit nach einer Kündigung (danach gesperrt bis `delete_after`)
 * @property ?string $instance_target TB 12 Scheibe 4: Zielinstanz eines Planwechsels («start» ↔ «pro»), gesetzt von Scheibe 2
 * @property ?CarbonImmutable $erased_at TB 12 Scheibe 4: Zeitpunkt der Löschung (Sites weg, Konto anonymisiert)
 * @property Carbon|null $created_at
 * @property ?string $stripe_id TB 12 Scheibe 2: Kunde bei Stripe (Cashier), nur auf der SaaS
 * @property ?string $pm_type TB 12 Scheibe 2: Art der Zahlungsmethode bei Stripe (z. B. visa)
 * @property ?string $pm_last_four TB 12 Scheibe 2: letzte vier Ziffern der Karte
 */
final class Account extends Model
{
    // TB 12 Scheibe 2: auf der SaaS zahlt das Konto (Cashier, Abos in account_subscriptions) — siehe Saas\Billing\CashierModels
    use Billable;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    public const PLAN_DOWNLOAD = 'download';

    /** TB 12 Scheibe 1 (M-21): Testphase nach der Selbstregistrierung — 14 Tage, danach wählt der Kunde einen Plan. */
    public const PLAN_TRIAL = 'trial';

    public const PLAN_START = 'start';

    public const PLAN_STANDARD = 'standard';

    public const PLAN_PRO = 'pro';

    public const PLAN_ENTERPRISE = 'enterprise';

    protected $fillable = ['name', 'plan', 'status', 'trial_ends_at', 'billing', 'cancelled_at', 'delete_after', 'instance', 'terms_accepted_at', 'instance_target'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['billing' => 'array', 'trial_ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'delete_after' => 'immutable_datetime', 'terms_accepted_at' => 'immutable_datetime',
            // TB 12 Scheibe 4: Ende der Laufzeit nach der Kündigung und Zeitpunkt der Löschung
            'ends_at' => 'immutable_datetime', 'erased_at' => 'immutable_datetime'];
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<AccountNotice, $this> TB 12 Scheibe 1: Hinweise an das Konto, je Art und Bezug genau einmal */
    public function notices(): HasMany
    {
        return $this->hasMany(AccountNotice::class);
    }

    /** @return HasMany<AccountInvoice, $this> TB 12 Scheibe 2: Rechnungen der SaaS (nicht `invoices()` — das sind bei Cashier die Rechnungen von Stripe) */
    public function accountInvoices(): HasMany
    {
        return $this->hasMany(AccountInvoice::class);
    }

    /** TB 12 Scheibe 2: Name bei Stripe — Firma der Rechnungsadresse, sonst der Name des Kontos. */
    public function stripeName(): string
    {
        $company = $this->billing['address']['company'] ?? null;

        return is_string($company) && trim($company) !== '' ? $company : $this->name;
    }

    /** TB 12 Scheibe 2: E-Mail für Stripe — die Rechnungs-E-Mail, sonst die des Besitzers. */
    public function stripeEmail(): ?string
    {
        $email = $this->billing['address']['email'] ?? null;
        if (is_string($email) && $email !== '') {
            return $email;
        }
        $owner = $this->users()->wherePivot('role', AccountRole::Owner->value)->orderBy('account_user.id')->first();
        $value = $owner?->getAttribute('email');

        return is_string($value) ? $value : null;
    }

    /**
     * TB 12 Scheibe 2: Rechnungsadresse für Stripe.
     *
     * @return array<string, string|null>
     */
    public function stripeAddress(): array
    {
        $address = (array) ($this->billing['address'] ?? []);

        return array_filter(['line1' => $address['street'] ?? null, 'postal_code' => $address['postal_code'] ?? null, 'city' => $address['city'] ?? null, 'country' => $address['country'] ?? null],
            static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /** @return list<string>|null TB 12 Scheibe 2: Sprache der Rechnungen (Stripe-Belege, PDF) */
    public function stripePreferredLocales(): ?array
    {
        $locale = $this->billing['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? [$locale] : null;
    }

    /** @return BelongsToMany<Model, $this> */
    public function users(): BelongsToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsToMany($userModel, 'account_user')->withPivot('role')->withTimestamps();
    }

    public function roleOf(Authenticatable $user): ?AccountRole
    {
        $pivot = $this->users()->whereKey($user->getAuthIdentifier())->first()?->getRelationValue('pivot');
        $role = $pivot?->getAttribute('role');

        return is_string($role) ? AccountRole::tryFrom($role) : null;
    }

    /** Das eine Konto einer Download-Installation — angelegt beim Installieren, sonst beim ersten Zugriff. */
    public static function installation(?string $name = null): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create(['name' => $name ?? (string) config('app.name', 'Basalto'), 'plan' => self::PLAN_DOWNLOAD]);
    }
}
