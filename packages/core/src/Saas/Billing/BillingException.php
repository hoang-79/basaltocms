<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Billing;

use RuntimeException;

/** Stripe ist für die SaaS nicht eingerichtet oder hat eine Anfrage abgelehnt — die Meldung ist für Kundinnen und Kunden bestimmt. */
final class BillingException extends RuntimeException {}
