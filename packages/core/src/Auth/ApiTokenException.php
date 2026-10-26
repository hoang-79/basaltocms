<?php

declare(strict_types=1);

namespace Basalto\Core\Auth;

use DomainException;

/** Ein API-Token lässt sich so nicht ausstellen: keine Mitgliedschaft auf der Site oder keine gültige Fähigkeit. */
final class ApiTokenException extends DomainException {}
