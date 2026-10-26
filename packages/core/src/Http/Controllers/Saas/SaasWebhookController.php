<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Models\Account;
use Basalto\Core\Saas\Billing\AccountBilling;
use Basalto\Core\Saas\Billing\CashierModels;
use Basalto\Core\Saas\Billing\Invoicer;
use Basalto\Core\Saas\Notifications\InvoiceIssuedNotification;
use Basalto\Core\Saas\Offboarding\Lifecycle;
use Basalto\Core\Saas\Plan;
use Basalto\Core\Saas\Saas;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der Stripe-Webhook der SaaS (TB 12 Scheibe 2) unter `/stripe/saas-webhook` (`basalto.saas.billing.webhook_path`) —
 * eigener Pfad neben `/stripe/webhook` des Lizenzverkaufs, damit beide Rollen sich nie in die Quere kommen. Cashiers
 * Controller gleicht Abos und Kunden ab (Konto als Billable, {@see CashierModels}); hier kommt dazu:
 *
 * - `checkout.session.completed` (Modus `subscription`, Metadaten mit Konto und Instanz) → Plan, Zusätze, Zahlungsart;
 * - `invoice.paid` (über CHF 0) → bezahlte Rechnung im Konto, Mail mit PDF; merkt sich das Ende der Periode;
 * - `customer.subscription.created/updated` → Ende der Periode; die Testphase des Kontos bleibt stehen.
 *
 * Stripe schickt jedem Endpunkt alle Ereignisse des Kontos — fremde (andere Instanz, basaltocms.com) beantwortet er mit 200
 * und tut nichts. Ohne `STRIPE_WEBHOOK_SECRET` nimmt er nichts an (503), eine falsche Signatur ist 403.
 */
final class SaasWebhookController extends WebhookController
{
    /** Die Signatur prüft dieser Controller selbst bei jeder Anfrage — nicht über eine Middleware, die nur mit Geheimnis greift. */
    public function __construct() {}

    public function handleWebhook(Request $request)
    {
        abort_unless(Saas::enabled(), 404);
        $secret = config('cashier.webhook.secret');
        if (! is_string($secret) || $secret === '') {
            return new Response('Stripe-Webhook nicht eingerichtet (STRIPE_WEBHOOK_SECRET fehlt).', 503);
        }
        try {
            WebhookSignature::verifyHeader($request->getContent(), (string) $request->header('Stripe-Signature'), $secret, (int) config('cashier.webhook.tolerance', 300));
        } catch (SignatureVerificationException) {
            return new Response('Signatur ungültig.', 403);
        }
        CashierModels::apply();

        return parent::handleWebhook($request);
    }

    /** @param  array<string, mixed>  $payload */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        $session = (array) ($payload['data']['object'] ?? []);
        if (($session['mode'] ?? null) !== 'subscription' || ! in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            return $this->successMethod();
        }
        $metadata = (array) ($session['metadata'] ?? []);
        $account = $this->account($metadata, $session['customer'] ?? null);
        $plan = Plan::find(is_string($metadata['plan'] ?? null) ? $metadata['plan'] : null);
        if ($account === null || $plan === null || $plan->isTrial()) {
            return $this->successMethod();
        }
        if ($account->stripe_id === null && is_string($session['customer'] ?? null)) {
            $account->forceFill(['stripe_id' => $session['customer']])->save();
        }
        app(AccountBilling::class)->activateStripe($account, $plan, max(0, (int) ($metadata['extra_sites'] ?? 0)), max(0, (int) ($metadata['extra_domains'] ?? 0)));

        return $this->successMethod();
    }

    /** @param  array<string, mixed>  $payload */
    protected function handleInvoicePaid(array $payload): Response
    {
        $data = (array) ($payload['data']['object'] ?? []);
        $account = $this->billable($data['customer'] ?? null);
        if ($account === null) {
            return $this->successMethod();
        }
        $ends = array_filter(array_map(static fn (mixed $line): ?int => is_array($line) && is_numeric($line['period']['end'] ?? null) ? (int) $line['period']['end'] : null, (array) ($data['lines']['data'] ?? [])));
        if ($ends !== []) {
            $this->periodEnd($account, max($ends));
        }
        $invoicer = app(Invoicer::class);
        $invoice = $invoicer->recordStripe($account, $data);
        if ($invoice !== null) {
            $invoicer->notify($account, new InvoiceIssuedNotification($invoice->id));
        }

        return $this->successMethod();
    }

    /** @param  array<string, mixed>  $payload */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        // Cashier leert sonst `trial_ends_at` — am Konto ist das das Ende der SaaS-Testphase und bleibt stehen
        $account = $this->billable($payload['data']['object']['customer'] ?? null);
        $trialEnd = $account?->trial_ends_at;
        $response = parent::handleCustomerSubscriptionCreated($payload);
        if ($account !== null) {
            $account->refresh();
            if ($trialEnd !== null && $account->trial_ends_at === null) {
                $account->forceFill(['trial_ends_at' => $trialEnd])->save();
            }
            $this->subscriptionPeriod($account, (array) ($payload['data']['object'] ?? []));
        }

        return $response;
    }

    /** @param  array<string, mixed>  $payload */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);
        $account = $this->billable($payload['data']['object']['customer'] ?? null);
        if ($account !== null) {
            $this->subscriptionPeriod($account, (array) ($payload['data']['object'] ?? []));
        }

        return $response;
    }

    /**
     * Das Konto aus den Metadaten — nur auf seiner Instanz und, falls schon bekannt, mit demselben Kunden bei Stripe.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function account(array $metadata, mixed $customer): ?Account
    {
        $id = $metadata['basalto_account'] ?? null;
        if (! is_numeric($id) || ($metadata['basalto_instance'] ?? null) !== Saas::instance()) {
            return null;
        }
        $account = Account::query()->find((int) $id);
        // Umgezogen: das Konto lebt auf der Zielinstanz weiter, die Kopie hier bekommt keine Zahlungen mehr
        if ($account === null || ! Saas::isCustomer($account) || $account->status === Lifecycle::MOVED) {
            return null;
        }
        if ($account->stripe_id !== null && $account->stripe_id !== $customer) {
            return null;
        }

        return $account;
    }

    private function billable(mixed $customer): ?Account
    {
        if (! is_string($customer) || $customer === '') {
            return null;
        }

        // Wie Cashier::findBillable(), aber typisiert: nur Konten dieser Instanz, fremde Kunden (basaltocms.com, andere Instanz) nicht
        return Account::query()->where('stripe_id', $customer)->where('status', '!=', Lifecycle::MOVED)->first();
    }

    /** @param  array<string, mixed>  $subscription */
    private function subscriptionPeriod(Account $account, array $subscription): void
    {
        $end = $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? null;
        if (is_numeric($end)) {
            $this->periodEnd($account, (int) $end);
        }
    }

    private function periodEnd(Account $account, int $timestamp): void
    {
        app(AccountBilling::class)->update($account, ['period_end' => CarbonImmutable::createFromTimestamp($timestamp, 'Europe/Zurich')->toDateString()]);
    }
}
