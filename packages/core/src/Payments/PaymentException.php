<?php

declare(strict_types=1);

namespace Basalto\Core\Payments;

use RuntimeException;

/** Eine Zahlung liess sich nicht anlegen oder prüfen — die Meldung ist für die Person gedacht, die gerade zahlt oder einrichtet. */
final class PaymentException extends RuntimeException {}
