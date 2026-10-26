<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Invoices\Money;
use Basalto\Core\License\LicenseKey;
use Basalto\Core\License\LicenseRevocations;
use Basalto\Core\License\LicenseStatus;
use Basalto\Core\License\LicenseVerifier;
use Basalto\Core\License\VendorAccount;
use Basalto\Core\Models\VendorConnection;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Security\CodeResend;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Vendor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verwaltung › Lizenz (K-18, G-02, G-05; Mockup 19): Stand der Installation («registriert» und «regelkonform» sind zwei
 * Wörter), Angaben des Schlüssels und Schlüssel prüfen oder eintragen — offline gegen den Produktschlüssel im Kern,
 * ohne Aufruf nach Hause. Ein ungültiger Schlüssel wird nicht eingetragen; nichts wird je gesperrt.
 *
 * TB 15 Scheibe 4 (CR-004 P-06): Betreiber verbinden die Installation mit ihrem Kundenkonto beim Hersteller
 * (`LicenseAccountController`); Lizenzen, Rechnungen und Marktplatz-Käufe kommen dann als aufgeschobene Prop
 * (`vendor_account`) — die Seite wartet nie auf den Hersteller. Die Lizenz selbst bleibt offline geprüft.
 *
 * @phpstan-import-type Result from VendorAccount
 */
final class LicenseController
{
    public function __construct(private readonly CurrentSite $current, private readonly Settings $settings, private readonly Recorder $recorder, private readonly Dates $dates,
        private readonly VendorAccount $account) {}

    public function show(Request $request): Response
    {
        // TB 12 Scheibe 1: Auf der SaaS gibt es keine Download-Lizenz — die Lizenz steckt im Abo
        abort_if(Saas::enabled(), 404);
        $site = $this->current->require();
        $status = LicenseStatus::current();
        $key = $status->key;
        $canEdit = Gate::allows('update', $site);
        $connection = $canEdit ? $this->account->connection() : null;
        $fresh = $request->boolean('neu');

        return Inertia::render('core::License', [
            'status' => [
                'plan' => $key?->planLabel() ?? 'Free',
                'registered' => $status->registered,
                'compliant' => $status->compliant,
                'label' => $status->standLabel(),
                'users' => $status->users,
                'sites' => $status->sites,
                'hints' => $status->hints,
                'banner_from' => $status->bannerFrom() === null ? null : $this->dates->date($status->bannerFrom()),
                'banner_active' => $status->bannerActive(),
                'unregistered_since' => $status->unregisteredSince === null ? null : $this->dates->date($status->unregisteredSince),
            ],
            'key' => $key === null ? null : $this->describe($key),
            'source' => LicenseStatus::keySource(),
            'limits' => self::limits($key),
            'can_edit' => $canEdit,
            // TB 15 Scheibe 4 (CR-004 P-06): Kundenkonto beim Hersteller — nur für Betreiber, Daten aufgeschoben
            'connection' => $canEdit ? [
                'host' => Vendor::host(),
                'connected' => $connection !== null,
                'email' => $connection?->email,
                'customer_name' => $connection?->customer_name,
                'connected_at' => $connection === null ? null : $this->dates->date($connection->created_at),
                'pending_email' => $connection === null && is_string($pending = $request->session()->get(LicenseAccountController::SESSION_EMAIL)) ? $pending : null,
                'urls' => ['code' => route('basalto.license.account.code'), 'restart' => route('basalto.license.account.restart'), 'connect' => route('basalto.license.account.connect'),
                    'resend' => route('basalto.license.account.resend'),
                    'disconnect' => route('basalto.license.account.disconnect'), 'use_key' => route('basalto.license.account.key'), 'refresh' => route('basalto.license')],
                // TB 72: «Code erneut senden» nach der Wartezeit
                'resend' => ['wait' => app(CodeResend::class)->wait($request, LicenseAccountController::SESSION_SENT), 'seconds' => app(CodeResend::class)->seconds()],
            ] : null,
            'vendor_account' => $connection === null ? null : Inertia::defer(fn (): array => $this->vendorAccount($connection, $fresh, $key?->id)),
            'urls' => ['check' => route('basalto.license.check'), 'store' => route('basalto.license.store'), 'destroy' => route('basalto.license.destroy'),
                // TB 11 Scheibe 3 (G-07): Lizenzvertrag in der Sprache der Oberfläche
                'contract' => route('basalto.legal', ['text' => 'lizenzvertrag'])],
        ]);
    }

    /** Prüfen, ohne einzutragen — dieselbe Prüfung wie `basalto:license --check=`. */
    public function check(Request $request): JsonResponse
    {
        // TB 12 Scheibe 1: Auf der SaaS gibt es keine Download-Lizenz — die Lizenz steckt im Abo
        abort_if(Saas::enabled(), 404);
        $this->current->require();
        $key = self::verify((string) $request->input('key', ''));

        return $key instanceof LicenseKey
            ? response()->json(['valid' => true, 'key' => $this->describe($key)])
            : response()->json(['valid' => false, 'message' => $key], 422);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->install($request, (string) $request->input('key', ''));
    }

    /**
     * Einen Schlüssel eintragen — von Hand (`store`) oder aus dem Kundenkonto beim Hersteller (TB 15 Scheibe 4):
     * dieselbe Prüfung, derselbe Speicherort, derselbe Protokolleintrag.
     */
    public function install(Request $request, string $raw): RedirectResponse
    {
        // TB 12 Scheibe 1: Auf der SaaS gibt es keine Download-Lizenz — die Lizenz steckt im Abo
        abort_if(Saas::enabled(), 404);
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $raw = trim($raw);
        $key = self::verify($raw);
        if (! $key instanceof LicenseKey) {
            return back()->withErrors(['key' => $key]);
        }
        $user = $request->user();
        $this->settings->set(LicenseStatus::SETTING, $raw, null, $user instanceof Authenticatable ? $user : null);
        $this->recorder->record('license.stored', null, null, ['id' => $key->id, 'plan' => $key->plan, 'licensee' => $key->licensee], $site);
        $status = LicenseStatus::current();

        return back()->with('status', $status->registered ? __('Schlüssel eingetragen — :plan-Lizenz für :licensee, registriert.', ['plan' => $key->planLabel(), 'licensee' => $key->licensee]) : __('Schlüssel eingetragen — die Installation hält die Grenzen der Lizenz noch nicht ein (siehe Hinweise).'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        // TB 12 Scheibe 1: Auf der SaaS gibt es keine Download-Lizenz — die Lizenz steckt im Abo
        abort_if(Saas::enabled(), 404);
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $user = $request->user();
        $this->settings->set(LicenseStatus::SETTING, null, null, $user instanceof Authenticatable ? $user : null);
        $this->recorder->record('license.removed', null, null, null, $site);

        return back()->with('status', LicenseStatus::keySource() === 'env' ? __('Schlüssel im Backend entfernt — es gilt wieder BASALTO_LICENSE_KEY.') : __('Schlüssel entfernt — es gilt Free.'));
    }

    /**
     * Lizenzen, Rechnungen und Käufe aus dem Kundenkonto — beschriftet in der Sprache der Oberfläche; Schlüssel selbst
     * gehen nicht in die Props («Diesen Schlüssel verwenden» schickt nur die Schlüssel-ID).
     *
     * @return array<string, mixed>
     */
    private function vendorAccount(VendorConnection $connection, bool $fresh, ?string $installedId): array
    {
        $result = $this->account->account($fresh);
        $locale = app()->getLocale();
        $account = $result['ok'] ? $result['account'] : null;
        $plans = [LicenseKey::PLAN_SITE => __('Site-Lizenz'), LicenseKey::PLAN_AGENCY => __('Agentur-Lizenz')];
        $licenseStates = ['active' => __('aktiv'), 'revoked' => __('gesperrt'), 'pending' => __('in Bearbeitung')];
        $paymentStates = ['open' => __('offen'), 'paid' => __('bezahlt'), 'cancelled' => __('storniert'), 'refunded' => __('zurückerstattet')];

        return [
            'ok' => $result['ok'], 'signed' => $result['signed'], 'error' => $result['error'], 'revoked' => $result['revoked'],
            'fetched_at' => $result['fetched_at'] === null ? null : $this->dates->dateTime($result['fetched_at']),
            'customer' => $account['customer'] ?? null,
            'licenses' => array_map(fn (array $l): array => [
                'key_id' => $l['key_id'], 'plan' => $l['sublicense'] ? __('Unterlizenz') : $plans[$l['plan']] ?? $l['plan'], 'licensee' => $l['licensee'], 'client' => $l['client'],
                'domains' => $l['domains'], 'issued_at' => $this->dates->date($l['issued_at']), 'updates_until' => $this->dates->date($l['updates_until']),
                'updates_expired' => $l['updates_until'] !== null && now()->startOfDay()->toDateString() > $l['updates_until'],
                'status' => $l['status'], 'status_label' => $licenseStates[$l['status']] ?? $l['status'],
                'can_use' => $l['status'] === 'active' && $l['key'] !== null && $l['key_id'] !== $installedId, 'installed' => $l['key_id'] === $installedId,
            ], $account['licenses'] ?? []),
            'invoices' => array_map(fn (array $i): array => [
                'number' => $i['number'], 'issued_at' => $this->dates->date($i['issued_at']), 'due_at' => $this->dates->date($i['due_at']),
                'total' => Money::format($i['total'], $i['currency'], $locale), 'status' => $i['status'], 'status_label' => $paymentStates[$i['status']] ?? $i['status'], 'pdf_url' => $i['pdf_url'],
            ], $account['invoices'] ?? []),
            'purchases' => array_map(fn (array $p): array => [
                'number' => $p['number'], 'title' => $p['title'], 'kind' => $p['kind'] === 'theme' ? __('Theme') : __('Plugin'),
                'amount' => Money::format($p['amount'], $p['currency'], $locale), 'status' => $p['status'], 'status_label' => $paymentStates[$p['status']] ?? $p['status'],
                'paid_at' => $this->dates->date($p['paid_at']), 'updates_until' => $this->dates->date($p['updates_until']), 'installation' => $p['installation'], 'receipt_url' => $p['receipt_url'],
            ], $account['purchases'] ?? []),
            'email' => $connection->email,
        ];
    }

    /** Gültiger Schlüssel oder die Meldung, warum nicht. */
    private static function verify(string $raw): LicenseKey|string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return __('Bitte einen Schlüssel einfügen (beginnt mit BSL1.).');
        }
        $verifier = LicenseVerifier::fromBase64(config('basalto.license.public_key'));
        if (! $verifier->configured()) {
            return __('Diese Installation kennt keinen Produktschlüssel — Schlüssel lassen sich nicht prüfen.');
        }
        $key = $verifier->verify($raw);
        if ($key === null) {
            return __('Dieser Schlüssel ist ungültig (Format oder Signatur). Bitte aus der Mail von :vendor kopieren.', ['vendor' => Vendor::host()]);
        }
        if (LicenseRevocations::default()->revoked($key->id)) {
            return __('Dieser Schlüssel (:id) ist gesperrt. Fragen an :email.', ['id' => $key->id, 'email' => Vendor::email()]);
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function describe(LicenseKey $key): array
    {
        return [
            'id' => $key->id, 'plan' => $key->planLabel(), 'licensee' => $key->licensee, 'domains' => $key->domains,
            'seats' => $key->seats, 'sites' => $key->sites, 'issued_at' => $this->dates->date($key->issuedAt),
            'updates_until' => $key->updatesUntil === null ? null : $this->dates->date($key->updatesUntil),
            'updates_days' => $key->updatesUntil === null ? null : (int) now()->startOfDay()->diffInDays($key->updatesUntil->endOfDay(), false),
            'updates_expired' => $key->updatesExpired(),
            'agency' => $key->agency, 'customer' => $key->customer, 'white_label' => $key->whiteLabel,
        ];
    }

    /** @return array{users: int|null, sites: int|null} null = unbegrenzt */
    private static function limits(?LicenseKey $key): array
    {
        $plan = $key === null ? LicenseKey::PLAN_FREE : $key->plan;
        $defaultSites = match ($plan) {
            LicenseKey::PLAN_FREE => 1,
            LicenseKey::PLAN_SITE => 3,
            default => null,
        };

        return [
            'users' => $key !== null && $key->seats !== null ? $key->seats : ($plan === LicenseKey::PLAN_FREE ? 1 : null),
            'sites' => $key !== null && $key->sites !== null ? $key->sites : $defaultSites,
        ];
    }
}
