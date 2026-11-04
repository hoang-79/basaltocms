<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Illuminate\Support\Str;

/**
 * Zahlungen einer Site über ihr eigenes Stripe-Konto (CR-018, TB 103) — für Module und Plugins: Anzahlung einer
 * Reservation, Kauf eines Gutscheins. Ablauf:
 *
 * 1. Das Modul ruft {@see checkout()} mit Betrag, Bezug und der Adresse, auf die es nach dem Bezahlen zurück will, und
 *    leitet auf `checkout_url` weiter.
 * 2. Stripe schickt die Person zurück auf `/_zahlung/<id>/<token>`; der Kern fragt Stripe nach dem Stand, meldet
 *    {@see PaymentCompleted} und leitet weiter auf die Adresse des Moduls (`?zahlung=bezahlt|offen|abgebrochen`).
 * 3. Wer das Fenster vorher schliesst, verpasst nichts: {@see reconcile()} (alle fünf Minuten) fragt offene Zahlungen
 *    nach und meldet auch abgelaufene ({@see PaymentExpired}).
 *
 * Kein Webhook nötig — der Betreiber trägt nur den geheimen Schlüssel ein (Verwaltung › Zahlungen).
 */
final class SitePayments
{
    /** Stripe nimmt in CHF und EUR ab 50 Rappen bzw. Cent */
    public const MINIMUM = 50;

    public function __construct(private readonly CheckoutGateway $gateway) {}

    public function setting(Site $site): ?PaymentSetting
    {
        return PaymentSetting::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    }

    public function configured(Site $site): bool
    {
        $setting = $this->setting($site);

        return $setting !== null && is_string($setting->secret_key) && $setting->secret_key !== '';
    }

    public function currency(Site $site): string
    {
        return $this->setting($site)->currency ?? 'CHF';
    }

    /**
     * Eine Zahlung anlegen und den Checkout bei Stripe eröffnen.
     *
     * @param  string  $returnUrl  Pfad dieser Site, auf den es nach dem Bezahlen zurückgeht
     * @param  array<string, string>  $metadata
     *
     * @throws PaymentException
     */
    public function checkout(Site $site, string $module, string $reference, int $amount, string $description, string $returnUrl, ?string $email = null,
        array $metadata = [], int $minutes = 30, ?string $locale = null): Payment
    {
        $setting = $this->setting($site);
        if ($setting === null || ! is_string($setting->secret_key) || $setting->secret_key === '') {
            throw new PaymentException((string) __('Zahlungen sind auf dieser Site nicht eingerichtet.'));
        }
        if ($amount < self::MINIMUM) {
            throw new PaymentException((string) __('Der Betrag ist zu klein für eine Zahlung (mindestens :currency 0.50).', ['currency' => $setting->currency]));
        }
        $payment = new Payment(['module' => $module, 'reference' => $reference, 'token' => Str::random(40), 'amount' => $amount, 'currency' => $setting->currency,
            'description' => mb_substr($description, 0, 250), 'status' => Payment::OPEN, 'live' => $setting->live, 'return_url' => self::path($returnUrl), 'email' => $email,
            'metadata' => $metadata ?: null]);
        $payment->site_id = $site->id;
        $payment->save();
        $back = rtrim($site->publicUrl('/'), '/').'/_zahlung/'.$payment->id.'/'.$payment->token;
        try {
            $session = $this->gateway->create($setting->secret_key, $amount, $setting->currency, $description, $back, $back.'?abbruch=1', $email,
                $locale ?? $site->default_locale, $metadata + ['basalto_payment' => (string) $payment->id, 'basalto_site' => (string) $site->id, 'basalto_module' => $module], $minutes);
        } catch (PaymentException $e) {
            $payment->delete();

            throw $e;
        }
        $payment->forceFill(['provider_session' => $session['id'], 'checkout_url' => $session['url'], 'expires_at' => $session['expires_at'] > 0 ? now()->setTimestamp($session['expires_at']) : null])->save();

        return $payment;
    }

    /** Bei Stripe nachfragen und den Stand übernehmen — bezahlt oder abgelaufen meldet das Ereignis genau einmal. */
    public function confirm(Payment $payment): Payment
    {
        if ($payment->status !== Payment::OPEN || $payment->provider_session === null) {
            return $payment;
        }
        $site = Site::query()->find($payment->site_id);
        $secret = $site === null ? null : $this->setting($site)?->secret_key;
        if (! is_string($secret) || $secret === '') {
            return $payment;
        }
        $state = $this->gateway->retrieve($secret, $payment->provider_session);
        if ($state['status'] === 'paid') {
            $this->settle($payment, Payment::PAID, ['provider_payment' => $state['payment'], 'email' => $payment->email ?? $state['email'], 'paid_at' => now()]);
        } elseif ($state['status'] === 'expired') {
            $this->settle($payment, Payment::EXPIRED, []);
        }

        return $payment->refresh();
    }

    /** Offene Zahlungen der letzten Tage nachfragen (Scheduler, alle fünf Minuten). @return int geändert */
    public function reconcile(): int
    {
        $changed = 0;
        $query = Payment::query()->withoutGlobalScope(SiteScope::class);
        $query->where('status', Payment::OPEN);
        $query->where('created_at', '>=', now()->subDays(2));
        $query->orderBy('id');
        foreach ($query->limit(200)->get() as $payment) {
            try {
                $changed += $this->confirm($payment)->status !== Payment::OPEN ? 1 : 0;
            } catch (PaymentException) {
                // Stripe gerade nicht erreichbar — beim nächsten Lauf
            }
        }

        return $changed;
    }

    /** Gibt es offene Zahlungen? (Für den Zeitplan — vor der ersten Migration gibt es die Tabelle noch nicht.) */
    public static function anyOpen(): bool
    {
        try {
            $query = Payment::query()->withoutGlobalScope(SiteScope::class);

            return $query->where('status', Payment::OPEN)->where('created_at', '>=', now()->subDays(2))->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Die neueste Zahlung zu einem Bezug eines Moduls. */
    public function latest(Site $site, string $module, string $reference): ?Payment
    {
        $query = Payment::query()->withoutGlobalScope(SiteScope::class);
        $query->where('site_id', $site->id);
        $query->where('module', $module);
        $query->where('reference', $reference);
        $query->orderByDesc('id');

        return $query->first();
    }

    /** @param  array<string, mixed>  $values */
    private function settle(Payment $payment, string $status, array $values): void
    {
        // Nur der erste, der den Stand ändert, meldet — Rückkehr und Abgleich können gleichzeitig kommen
        $query = Payment::query()->withoutGlobalScope(SiteScope::class);
        $query->whereKey($payment->id);
        $query->where('status', Payment::OPEN);
        if ($query->update(['status' => $status] + $values + ['updated_at' => now()]) !== 1) {
            return;
        }
        $payment->refresh();
        $status === Payment::PAID ? PaymentCompleted::dispatch($payment) : PaymentExpired::dispatch($payment);
    }

    /** Nur ein Pfad dieser Site — sonst wäre die Rückkehr eine offene Weiterleitung. */
    public static function path(string $value): string
    {
        $value = trim($value);

        return str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! str_contains($value, '\\') ? $value : '/';
    }
}
