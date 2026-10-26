<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountSubscription;
use Basalto\Core\Models\AccountSubscriptionItem;
use Basalto\Core\Saas\Saas;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;

/**
 * Wer bei Laravel Cashier zahlt — je Rolle der Installation (TB 12 Scheibe 2). Cashier kennt genau ein Kundenmodell
 * (statisch, global); eine Installation hat aber auch genau eine Rolle:
 *
 * - **SaaS-Instanz** (`basalto.saas.enabled`): das Konto ({@see Account}) mit den Abos in `account_subscriptions` und
 *   `account_subscription_items` (Fremdschlüssel `account_id`).
 * - **sonst**, z. B. basaltocms.com mit dem Lizenzverkauf: das Kundenmodell, das ein Modul anmeldet ({@see licensing()} —
 *   `customers`, Abos in `subscriptions` an `customer_id`), mit den Abo-Modellen von Cashier.
 *
 * Eigene Abo-Tabellen statt der gemeinsamen `subscriptions`: Cashier leitet den Fremdschlüssel aus dem Kundenmodell ab
 * (`Customer` → `customer_id`, `Account` → `account_id`), die Tabelle `subscriptions` gehört dem Lizenzmodul (das auf der
 * SaaS fehlen darf), und der Kern hängt so an keiner Modul-Tabelle. Beide Wege zugleich gibt es in keiner Installation.
 *
 * Entschieden wird beim Start (Service-Provider) und noch einmal an jedem Eingang der Abrechnung (Konto-Bereich,
 * Webhook der SaaS) — so gilt die Rolle auch, wenn die Konfiguration erst zur Laufzeit wechselt (Tests).
 */
final class CashierModels
{
    /** Konfigurationsschlüssel für das Kundenmodell des Lizenzverkaufs — Konfiguration statt statischer Eigenschaft (Octane). */
    public const LICENSING_CUSTOMER = 'basalto.billing.licensing_customer';

    /**
     * Das Kundenmodell des Lizenzverkaufs anmelden (Modul `licensing`) — es gilt, solange die Installation keine SaaS ist.
     *
     * @param  class-string<Model>  $model
     */
    public static function licensing(string $model): void
    {
        config()->set(self::LICENSING_CUSTOMER, $model);
        self::apply();
    }

    public static function apply(): void
    {
        if (Saas::enabled()) {
            Cashier::useCustomerModel(Account::class);
            Cashier::useSubscriptionModel(AccountSubscription::class);
            Cashier::useSubscriptionItemModel(AccountSubscriptionItem::class);

            return;
        }
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);
        $customer = config(self::LICENSING_CUSTOMER);
        if (is_string($customer) && is_a($customer, Model::class, true)) {
            Cashier::useCustomerModel($customer);
        }
    }
}
