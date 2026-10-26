<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\FrontendAccounts\Notifications\CustomerLoginCodeNotification;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Illuminate\Support\Facades\Notification;

/**
 * Anmeldung im Frontend-Konto per Mail-Code (wie das Backend, K-04, aber ohne Konto vorab): Code anfordern schickt
 * sechs Ziffern an die Adresse — ob es ein Konto gibt oder nicht, die Antwort ist dieselbe. Der richtige Code meldet an
 * und legt das Konto beim ersten Mal an; ältere offene Codes derselben Adresse verfallen, fünf Fehlversuche sperren.
 * Lässt kein eingeschaltetes Modul neue Konten zu (Mitglieder «nur auf Einladung»), geht ein Code nur an Adressen mit
 * Konto — die Antwort an den Browser bleibt dieselbe, damit niemand Adressen abfragen kann.
 */
final class CustomerLogin
{
    /** Die Adresse, für die ein Code unterwegs ist (in der Sitzung bis zur Eingabe des Codes). */
    public const SESSION_EMAIL = 'account.login.email';

    /** Wozu angemeldet wird (z. B. «members») — aus dem Formular, bis zur Eingabe des Codes in der Sitzung. */
    public const SESSION_PURPOSE = 'account.login.purpose';

    public function __construct(private readonly Settings $settings) {}

    public function minutes(Site $site): int
    {
        return (int) $this->settings->get('auth.code_minutes', (int) config('basalto.auth.code_minutes', 10));
    }

    /** Code erzeugen und senden; liefert die Gültigkeit in Minuten. */
    public function request(Site $site, string $email, string $locale, ?string $ip): int
    {
        $email = self::normalize($email);
        $minutes = $this->minutes($site);
        $customer = Customer::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', $email)->first();
        if ($customer === null && ! CustomerAuth::opensRegistration($site)) {
            return $minutes;
        }
        CustomerLoginCode::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        CustomerLoginCode::query()->create(['site_id' => $site->id, 'email' => $email, 'code_hash' => CustomerLoginCode::hashOf($code), 'expires_at' => now()->addMinutes($minutes),
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key'))]);
        $notification = (new CustomerLoginCodeNotification($code, $minutes, $site->name))->locale($customer?->preferredLocale() ?? $locale);
        if ($customer !== null) {
            $customer->notify($notification);
        } else {
            Notification::route('mail', $email)->notify($notification);
        }

        return $minutes;
    }

    /** Code prüfen: richtig → das (neue) Konto; sonst null, Fehlversuch gezählt. */
    public function verify(Site $site, string $email, string $code, string $locale): ?Customer
    {
        $email = self::normalize($email);
        $challenge = CustomerLoginCode::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('email', $email)->whereNull('used_at')->latest('id')->first();
        if ($challenge === null || ! $challenge->isOpen()) {
            return null;
        }
        if (! hash_equals($challenge->code_hash, CustomerLoginCode::hashOf($code))) {
            $challenge->increment('attempts');
            if ($challenge->attempts >= CustomerLoginCode::MAX_ATTEMPTS) {
                $challenge->forceFill(['used_at' => now()])->save();
            }

            return null;
        }
        $challenge->forceFill(['used_at' => now()])->save();
        $query = Customer::query()->withoutGlobalScopes();

        return CustomerAuth::opensRegistration($site)
            ? $query->firstOrCreate(['site_id' => $site->id, 'email' => $email], ['locale' => $locale])
            : $query->where('site_id', $site->id)->where('email', $email)->first();
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
