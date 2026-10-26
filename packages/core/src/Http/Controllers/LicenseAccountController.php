<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\License\VendorAccount;
use Basalto\Core\License\VendorAccountException;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Security\CodeResend;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Verwaltung › Lizenz › Kundenkonto beim Hersteller (TB 15 Scheibe 4, CR-004 P-06): E-Mail eingeben → Code aus der
 * Mail eingeben → verbunden; danach einen Schlüssel aus dem Konto übernehmen oder trennen. Nur Betreiber, nicht auf
 * der SaaS (dort steckt die Lizenz im Abo). Das Protokoll hält nur «verbunden» und «getrennt» mit dem Host des
 * Herstellers fest — keine Adresse, kein Token.
 */
final class LicenseAccountController
{
    /** Die Adresse zwischen «Code anfordern» und «Code eingeben». */
    public const SESSION_EMAIL = 'license.vendor.email';

    /** TB 72: Zeitpunkt des letzten Codes — «Code erneut senden» erst nach der Wartezeit. */
    public const SESSION_SENT = 'license.vendor.sent_at';

    public function __construct(private readonly CurrentSite $current, private readonly VendorAccount $account, private readonly Recorder $recorder) {}

    public function code(Request $request): RedirectResponse
    {
        $this->site();
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']], [], ['email' => __('E-Mail')]);
        $email = mb_strtolower(trim((string) $data['email']));
        try {
            $minutes = $this->account->requestCode($email, $request->getHost());
        } catch (VendorAccountException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        }
        $request->session()->put(self::SESSION_EMAIL, $email);
        app(CodeResend::class)->sent($request, self::SESSION_SENT);

        return back()->with('status', __('Wenn es zu :email ein Kundenkonto bei :host gibt, ist ein Code unterwegs (:minutes Minuten gültig).',
            ['email' => $email, 'host' => Vendor::host(), 'minutes' => $minutes]));
    }

    /** «Code erneut senden» an die gemerkte Adresse, frühestens nach der Wartezeit (TB 72). */
    public function resend(Request $request, CodeResend $resend): RedirectResponse
    {
        $this->site();
        $email = $request->session()->get(self::SESSION_EMAIL);
        if (! is_string($email) || $email === '') {
            return back();
        }
        $wait = $resend->wait($request, self::SESSION_SENT);
        if ($wait > 0) {
            return back()->withErrors(['resend' => trans_choice('Bitte warten Sie noch :count Sekunde.|Bitte warten Sie noch :count Sekunden.', $wait)]);
        }
        try {
            $minutes = $this->account->requestCode($email, $request->getHost());
        } catch (VendorAccountException $e) {
            return back()->withErrors(['resend' => $e->getMessage()]);
        }
        $resend->sent($request, self::SESSION_SENT);

        return back()->with('status', __('Wenn es zu :email ein Kundenkonto bei :host gibt, ist ein neuer Code unterwegs (:minutes Minuten gültig).',
            ['email' => $email, 'host' => Vendor::host(), 'minutes' => $minutes]));
    }

    /** Zurück zur Eingabe der Adresse (andere Adresse, kein Code angekommen). */
    public function restart(Request $request): RedirectResponse
    {
        $this->site();
        $request->session()->forget(self::SESSION_EMAIL);

        return back();
    }

    public function connect(Request $request): RedirectResponse
    {
        $site = $this->site();
        $email = $request->session()->get(self::SESSION_EMAIL);
        if (! is_string($email) || $email === '') {
            return back()->withErrors(['code' => __('Bitte zuerst die E-Mail-Adresse eingeben.')]);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => __('Code')]);
        $code = (string) preg_replace('/\s+/', '', (string) $data['code']);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return back()->withErrors(['code' => __('Der Code hat sechs Ziffern.')]);
        }
        try {
            $connection = $this->account->connect($email, $code, $request->getHost(), $site->name, $request->user());
        } catch (VendorAccountException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }
        $request->session()->forget(self::SESSION_EMAIL);
        $this->recorder->record('license.vendor_connected', null, null, ['vendor' => Vendor::host()], $site);

        return back()->with('status', __('Verbunden mit dem Kundenkonto :email bei :host.', ['email' => $connection->email, 'host' => Vendor::host()]));
    }

    public function disconnect(): RedirectResponse
    {
        $site = $this->site();
        if ($this->account->connection() === null) {
            return back();
        }
        $revoked = $this->account->disconnect();
        $this->recorder->record('license.vendor_disconnected', null, null, ['vendor' => Vendor::host()], $site);

        return back()->with('status', $revoked
            ? __('Getrennt — :host hat die Verbindung widerrufen.', ['host' => Vendor::host()])
            : __('Getrennt. :host war nicht erreichbar; das Token ist hier gelöscht und damit unbrauchbar.', ['host' => Vendor::host()]));
    }

    /** «Diesen Schlüssel verwenden»: ein Schlüssel aus dem Kundenkonto — eingetragen wie von Hand (gleiche Prüfung, gleicher Ort). */
    public function useKey(Request $request, LicenseController $license): RedirectResponse
    {
        $this->site();
        $data = $request->validate(['key_id' => ['required', 'string', 'max:32']]);
        $result = $this->account->account();
        $key = null;
        foreach ($result['ok'] ? ($result['account']['licenses'] ?? []) : [] as $item) {
            if ($item['key_id'] === $data['key_id'] && $item['status'] === 'active' && $item['key'] !== null) {
                $key = $item['key'];
            }
        }
        if ($key === null) {
            return back()->withErrors(['vendor' => __('Diesen Schlüssel gibt es im Kundenkonto nicht (mehr) — bitte aktualisieren.')]);
        }

        return $license->install($request, $key);
    }

    private function site(): Site
    {
        // Auf der SaaS gibt es keine Download-Lizenz — die Lizenz steckt im Abo
        abort_if(Saas::enabled(), 404);
        $site = $this->current->require();
        Gate::authorize('update', $site);

        return $site;
    }
}
