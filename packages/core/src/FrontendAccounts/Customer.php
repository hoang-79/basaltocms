<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\Privacy\PersonScope;
use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;

/**
 * Ein Frontend-Konto einer Site (G-04; seit TB 13 Scheibe 2 im Kern, damit Lizenzverkauf, Marktplatz und Mitglieder es
 * teilen, ohne voneinander abzuhängen): angemeldet wird per Mail-Code über den Guard `customer` (eigene Sitzung), nie
 * mit Passwort — und nie im Backend, Konten des Frontends sind keine Benutzer. Die E-Mail ist je Site eindeutig; Name,
 * Firma und Adresse erscheinen auf Rechnungen, die Sprache gilt für Mails und Rechnungen. `Billable` verbindet das Konto
 * mit Stripe (Cashier: `stripe_id`, Abos in `subscriptions`). Die Tabelle heisst weiter `customers` — Fremdschlüssel
 * von Lizenzen, Bestellungen, Rechnungen, Abos und Autorenprofilen zeigen darauf.
 *
 * @property int $id
 * @property int $site_id
 * @property string $email
 * @property string|null $name Ganzer Name — beim Speichern aus Vor- und Nachname gebildet (TB 63)
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company
 * @property string|null $street
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country
 * @property string $locale
 * @property string|null $remember_token
 * @property Carbon|null $last_login_at
 * @property string|null $stripe_id
 * @property array<string, mixed>|null $preferences
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Customer extends Authenticatable implements HasLocalePreference
{
    use BelongsToSite;
    use Billable;
    use Notifiable;

    protected $table = 'customers';

    protected $fillable = ['site_id', 'email', 'name', 'first_name', 'last_name', 'company', 'street', 'postal_code', 'city', 'country', 'locale', 'last_login_at', 'preferences'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime', 'trial_ends_at' => 'datetime', 'preferences' => 'array'];
    }

    /** Mails, Rechnungen und Codes in der Sprache des Kontos (N-01). */
    public function preferredLocale(): string
    {
        return $this->locale !== '' ? $this->locale : (string) config('app.locale');
    }

    /** Wie das Konto heisst: Firma, sonst Name, sonst die E-Mail. */
    public function displayName(): string
    {
        foreach ([$this->company, $this->name] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->email;
    }

    /**
     * Die Adresse für die Rechnung, so wie sie zum Zeitpunkt der Rechnung galt.
     *
     * @return array{name: string|null, company: string|null, street: string|null, postal_code: string|null, city: string|null, country: string|null, email: string}
     */
    public function billingAddress(): array
    {
        return ['name' => $this->name, 'company' => $this->company, 'street' => $this->street, 'postal_code' => $this->postal_code,
            'city' => $this->city, 'country' => $this->country, 'email' => $this->email];
    }

    /** Der Name für Stripe (Cashier). */
    public function stripeName(): string
    {
        return $this->displayName();
    }

    /**
     * Die Sprache für Stripe Checkout und Belege von Stripe (Cashier).
     *
     * @return list<string>
     */
    public function stripePreferredLocales(): array
    {
        return [$this->preferredLocale()];
    }

    /**
     * Kundenkonten zu einer E-Mail-Adresse für Auskunft und Löschen — auf allen Sites, im Rahmen von
     * {@see PersonScope::within()} nur auf dessen Site (CR-011 TB 61).
     *
     * @return Builder<self>
     */
    public static function byEmail(string $email): Builder
    {
        $site = app(PersonScope::class)->siteId();

        return self::query()->withoutGlobalScopes()->where('email', CustomerLogin::normalize($email))->when($site !== null, static fn (Builder $q) => $q->where('site_id', $site));
    }

    /**
     * TB 63: Vor- und Nachname und der ganze Name bleiben gleich — ändert sich einer der beiden, entsteht `name` daraus;
     * setzt ein älterer Weg nur `name` (Kauf, Anmeldung über Anbieter), teilt ihn der Schnitt am letzten Leerzeichen.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $customer): void {
            if ($customer->isDirty(['first_name', 'last_name'])) {
                $full = trim(trim((string) $customer->first_name).' '.trim((string) $customer->last_name));
                $customer->name = $full !== '' ? $full : null;
            } elseif ($customer->isDirty('name')) {
                [$customer->first_name, $customer->last_name] = self::splitName($customer->name);
            }
        });
    }

    /**
     * Ganzer Name → Vor- und Nachname: das letzte Wort ist der Nachname, ein einzelnes Wort der Vorname.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitName(?string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '') {
            return [null, null];
        }
        $at = mb_strrpos($name, ' ');

        return $at === false ? [$name, null] : [mb_substr($name, 0, $at), mb_substr($name, $at + 1)];
    }
}
