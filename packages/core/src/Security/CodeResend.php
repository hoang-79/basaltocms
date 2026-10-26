<?php

declare(strict_types=1);

namespace Basalto\Core\Security;

use Basalto\Core\Settings\Settings;
use Illuminate\Http\Request;

/**
 * «Code erneut senden» (TB 72): frühestens so viele Sekunden nach dem letzten Code (Verwaltung › Einstellungen,
 * `auth.code_resend_seconds`, Vorgabe 30). Der Zeitpunkt steht in der Sitzung, nicht in der Datenbank — so verhält
 * sich die Seite gleich, ob es zur Adresse ein Konto gibt oder nicht, und verrät nichts.
 */
final class CodeResend
{
    public function __construct(private readonly Settings $settings) {}

    public function seconds(): int
    {
        return max(0, (int) $this->settings->get('auth.code_resend_seconds', (int) config('basalto.auth.code_resend_seconds', 30)));
    }

    /** Merken, dass gerade ein Code ging. */
    public function sent(Request $request, string $key): void
    {
        $request->session()->put($key, now()->getTimestamp());
    }

    /** Wie viele Sekunden noch bis zum nächsten Code — 0 heisst: jetzt. */
    public function wait(Request $request, string $key): int
    {
        $sent = $request->session()->get($key);

        return is_int($sent) ? max(0, $this->seconds() - (now()->getTimestamp() - $sent)) : 0;
    }
}
