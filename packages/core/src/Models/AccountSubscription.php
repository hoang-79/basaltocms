<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Basalto\Core\Saas\Billing\CashierModels;
use Laravel\Cashier\Subscription;

/**
 * Ein Stripe-Abo eines Kontos (TB 12 Scheibe 2) — Cashiers Abo-Modell in der eigenen Tabelle `account_subscriptions`
 * an `account_id`; die Positionen hängen wie bei Cashier an `subscription_id`. Gilt nur auf einer SaaS-Instanz
 * ({@see CashierModels}).
 */
final class AccountSubscription extends Subscription
{
    protected $table = 'account_subscriptions';

    /** Die Positionen hängen an `subscription_id` wie im Schema von Cashier, nicht an `account_subscription_id`. */
    public function getForeignKey(): string
    {
        return 'subscription_id';
    }
}
