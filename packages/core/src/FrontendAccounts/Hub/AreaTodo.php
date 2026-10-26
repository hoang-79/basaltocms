<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

/**
 * «Als Nächstes»: etwas, das die Person tun sollte — mit einem Knopf. Ton `lava` (Neues), `warn` (läuft ab, offen)
 * oder leer.
 */
final readonly class AreaTodo
{
    public function __construct(public string $icon, public string $text, public AreaAction $action, public string $tone = '') {}
}
