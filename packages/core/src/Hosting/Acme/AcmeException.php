<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting\Acme;

use RuntimeException;

/** CR-017: Let's Encrypt hat abgelehnt oder nicht geantwortet — die Meldung landet beim Host (`hosting_error`). */
final class AcmeException extends RuntimeException {}
