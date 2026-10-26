<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Laravel\Cashier\SubscriptionItem;

/** Eine Position eines Stripe-Abos eines Kontos (TB 12 Scheibe 2): Plan, Zusatz-Sites, Zusatzdomains — je eine Preis-ID mit Menge. */
final class AccountSubscriptionItem extends SubscriptionItem
{
    protected $table = 'account_subscription_items';
}
