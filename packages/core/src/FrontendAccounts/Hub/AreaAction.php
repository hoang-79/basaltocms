<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

/** Ein Verweis als Knopf — im Kopf des Kontos, an einem Bereich oder an «Als Nächstes». */
final readonly class AreaAction
{
    public function __construct(public string $label, public string $href, public string $icon = 'arrow', public bool $primary = false) {}
}
