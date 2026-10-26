<?php

declare(strict_types=1);

namespace Basalto\Core\Forms;

use Basalto\Core\Models\Site;
use Basalto\Core\Security\ProofOfWork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spam-Schutz ohne Rätsel (M-11), an und nicht abschaltbar: ein unsichtbares Honeypot-Feld, ein signiertes Zeit-Token
 * (unter drei Sekunden ausgefüllt oder ungültig = Maschine) und ein Limit von fünf Eingängen je Minute und IP.
 * Das Token ist nicht an eine Sitzung gebunden — Seiten mit Formular bleiben im Seiten-Cache (TB 06); darum kommt der
 * Versand ohne CSRF-Token aus. Spam wird nie gespeichert, nur gezählt.
 *
 * TB 50: Mit einem Bereich (`formular`, `kommentar`) prüft er dazu die Aufgabe im Browser (`ProofOfWork`), sofern sie in
 * den Einstellungen der Site an ist.
 */
final class SpamGuard
{
    public const HONEYPOT = 'website_url';

    public const TOKEN = '_t';

    private const MIN_SECONDS = 3;

    private const MAX_AGE_DAYS = 14;

    private const PER_MINUTE = 5;

    public function token(string $handle, ?int $time = null): string
    {
        $time ??= now()->getTimestamp();

        return $time.'.'.self::sign($handle, $time);
    }

    public function __construct(private readonly ProofOfWork $pow) {}

    /** Null, wenn alles stimmt — sonst der Grund (für den Zähler und die Tests). */
    public function reason(Request $request, string $handle, int $siteId, ?string $area = null): ?string
    {
        if (trim((string) $request->input(self::HONEYPOT, '')) !== '') {
            return 'honeypot';
        }
        $token = (string) $request->input(self::TOKEN, '');
        [$time, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if (! ctype_digit($time) || ! hash_equals(self::sign($handle, (int) $time), $signature)) {
            return 'token';
        }
        $age = now()->getTimestamp() - (int) $time;
        if ($age < self::MIN_SECONDS) {
            return 'zu-schnell';
        }
        if ($age > self::MAX_AGE_DAYS * 86400) {
            return 'token-alt';
        }
        if ($area !== null && $this->pow->enabled(Site::query()->find($siteId), $area) && ($reason = $this->pow->reason($request, $area)) !== null) {
            return $reason;
        }
        $key = sprintf('basalto-form:%d:%s', $siteId, hash('sha256', (string) $request->ip()));
        if (! RateLimiter::attempt($key, self::PER_MINUTE, static fn (): bool => true, 60)) {
            return 'limit';
        }

        return null;
    }

    private static function sign(string $handle, int $time): string
    {
        return hash_hmac('sha256', $handle.'|'.$time, (string) config('app.key'));
    }
}
