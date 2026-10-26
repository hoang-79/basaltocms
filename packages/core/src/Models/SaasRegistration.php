<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eine Selbstregistrierung auf der SaaS (TB 12 Scheibe 1, M-21): Name, Firma und E-Mail mit Zustimmung zu AGB und
 * Datenschutz; bestätigt per Mail-Code oder Einmal-Link (kein Passwort, K-04). Erst nach der Bestätigung entstehen
 * Konto, Benutzer und Site. Code und Link liegen nur als Hash vor; nach fünf Fehlversuchen verfällt der Code.
 *
 * @property int $id
 * @property string $email
 * @property string $name
 * @property string|null $company
 * @property string $locale
 * @property string|null $code_hash
 * @property Carbon|null $code_expires_at
 * @property string|null $link_hash
 * @property Carbon|null $link_expires_at
 * @property int $attempts
 * @property Carbon $terms_accepted_at
 * @property Carbon|null $verified_at
 * @property int|null $account_id
 * @property Carbon|null $completed_at
 * @property string|null $ip_hash
 * @property Carbon $created_at
 */
final class SaasRegistration extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['email', 'name', 'company', 'locale', 'terms_accepted_at', 'ip_hash'];

    protected function casts(): array
    {
        return ['code_expires_at' => 'datetime', 'link_expires_at' => 'datetime', 'terms_accepted_at' => 'datetime', 'verified_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Neuer Code und neuer Link — ältere verfallen damit.
     *
     * @return array{code: string, link: string} beides im Klartext, nur für die Mail
     */
    public function issueSecrets(int $codeMinutes, int $linkMinutes): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $link = Str::random(64);
        $this->forceFill([
            'code_hash' => self::hashOf($code), 'code_expires_at' => now()->addMinutes($codeMinutes), 'attempts' => 0,
            'link_hash' => self::hashOf($link), 'link_expires_at' => now()->addMinutes($linkMinutes),
        ])->save();

        return ['code' => $code, 'link' => $link];
    }

    /** Code prüfen: richtig und offen → bestätigt; falsch zählt als Fehlversuch. */
    public function verifyCode(string $code): bool
    {
        if ($this->isVerified()) {
            return true;
        }
        if ($this->code_hash === null || $this->code_expires_at === null || $this->code_expires_at->isPast() || $this->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (! hash_equals($this->code_hash, self::hashOf($code))) {
            $this->increment('attempts');

            return false;
        }
        $this->markVerified();

        return true;
    }

    /** Einmal-Link einlösen: gültig und offen → die Registrierung, bestätigt; sonst null. */
    public static function consumeLink(string $secret): ?self
    {
        $registration = self::query()->where('link_hash', self::hashOf($secret))->first();
        if ($registration === null || $registration->completed_at !== null || $registration->link_expires_at === null || $registration->link_expires_at->isPast()) {
            return null;
        }
        $registration->markVerified();

        return $registration;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    private function markVerified(): void
    {
        $this->forceFill(['verified_at' => $this->verified_at ?? now(), 'code_hash' => null, 'link_hash' => null])->save();
    }

    public static function hashOf(string $secret): string
    {
        return hash('sha256', 'saas-registration|'.$secret.'|'.config('app.key'));
    }
}
