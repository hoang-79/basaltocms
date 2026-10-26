<?php

declare(strict_types=1);

namespace Basalto\Core\Privacy;

use RuntimeException;

/** Die Person ist der einzige Betreiber einer Site — löschen hiesse, die Site ohne Verantwortliche zurückzulassen. */
final class LastOwnerException extends RuntimeException {}
