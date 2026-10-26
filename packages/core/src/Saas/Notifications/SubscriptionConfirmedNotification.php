<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Notifications;

use Basalto\Core\Legal\WithdrawalNotice;
use Basalto\Core\Models\Account;
use Basalto\Core\Saas\Billing\AccountBilling;
use Basalto\Core\Saas\Billing\BillingSettings;
use Basalto\Core\Saas\Plan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bestätigung des Abos der SaaS (CR-011, TB 58) an Besitzer und Abrechnung: Plan, Preis, Zahlungsart, ab wann bezahlt
 * wird, wie man kündigt — und die Widerrufsbelehrung auf dauerhaftem Datenträger, mit dem Vermerk, wenn sofortiger
 * Beginn verlangt wurde.
 */
final class SubscriptionConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $accountId, public readonly string $plan) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $account = Account::query()->findOrFail($this->accountId);
        $plan = Plan::find($this->plan);
        $extras = AccountBilling::extras($account);
        $prices = app(BillingSettings::class);
        $monthly = ($plan->price ?? 0) + ($extras['sites'] * $prices->price(BillingSettings::EXTRA_SITE) + $extras['domains'] * $prices->price(BillingSettings::EXTRA_DOMAIN)) / 100;
        $start = AccountBilling::billingStart($account);
        $method = ($account->billing['method'] ?? null) === 'stripe' ? __('mit Karte über Stripe') : __('mit QR-Rechnung, zahlbar innert 14 Tagen');
        $mail = (new MailMessage)
            ->subject(__('Basalto · Ihr Abo «:plan» ist bestätigt', ['plan' => $plan?->label() ?? $this->plan]))
            ->greeting(__('Guten Tag'))
            ->line(__('Danke — das Abo «:plan» für :account gilt ab heute.', ['plan' => $plan?->label() ?? $this->plan, 'account' => $account->name]))
            ->line(__('Preis: CHF :amount im Monat, im Voraus bezahlt :method. Bezahlt wird ab :date.', [
                'amount' => number_format($monthly, 2, '.', "\u{2019}"), 'method' => $method, 'date' => $start->format('d.m.Y'),
            ]))
            ->line(__('Kündigen können Sie jederzeit unter Verwaltung › Konto › Export und Kündigung, auf das Ende des bezahlten Monats.'));
        WithdrawalNotice::apply($mail, 'subscription', null, is_string($account->billing['immediate_start_at'] ?? null));

        return $mail;
    }
}
