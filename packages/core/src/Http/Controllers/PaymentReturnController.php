<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Payments\Payment;
use Basalto\Core\Payments\PaymentException;
use Basalto\Core\Payments\SitePayments;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Rückkehr aus dem Stripe-Checkout (`/_zahlung/<id>/<token>`, CR-018 TB 103): Stand bei Stripe abfragen, dann zurück auf
 * die Adresse des Moduls mit `zahlung=bezahlt`, `offen` oder `abgebrochen`. Das Token gehört zur Zahlung — fremde
 * Zahlungen lassen sich so nicht abfragen.
 */
final class PaymentReturnController
{
    public function __invoke(Request $request, int $payment, string $token, SitePayments $payments, CurrentSite $current): RedirectResponse
    {
        $site = $current->require();
        $record = Payment::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($payment)->first();
        abort_if($record === null || ! hash_equals($record->token, $token), 404);
        try {
            $record = $payments->confirm($record);
        } catch (PaymentException) {
            // Stripe gerade nicht erreichbar — der Abgleich holt es nach
        }
        $state = match (true) {
            $record->status === Payment::PAID => 'bezahlt',
            $request->boolean('abbruch') => 'abgebrochen',
            default => 'offen',
        };

        return redirect($record->return_url.(str_contains($record->return_url, '?') ? '&' : '?').'zahlung='.$state);
    }
}
