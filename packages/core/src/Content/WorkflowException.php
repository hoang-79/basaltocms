<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use DomainException;

/** Verletzte Regel des Statusmodells: Übergang, Vier-Augen, Pflichtsprachen, Slug. */
final class WorkflowException extends DomainException {}
