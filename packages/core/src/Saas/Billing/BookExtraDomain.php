<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use Basalto\Core\Saas\Events\ExtraDomainRequested;

/**
 * Zusatzdomain gebucht (TB 12 Scheibe 2): Hört auf {@see ExtraDomainRequested} aus den Domains (Scheibe 3) und übernimmt
 * die Bestellung ins Abo ({@see AccountBilling::bookExtraDomainOrders()}). Scheitert Stripe, bleibt die Bestellung stehen
 * und der stündliche Lauf ({@see BillingRun}) versucht es wieder — die Domain selbst ist davon nicht betroffen.
 */
final class BookExtraDomain
{
    public function __construct(private readonly AccountBilling $billing) {}

    public function handle(ExtraDomainRequested $event): void
    {
        try {
            $this->billing->bookExtraDomainOrders($event->account);
        } catch (BillingException $e) {
            report($e);
        }
    }
}
