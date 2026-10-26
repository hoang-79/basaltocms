<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

use DateTimeInterface;

/** «Zuletzt»: ein Ereignis mit Zeit — Kauf, Rechnung, Beitrag, verbundene Installation. */
final readonly class AreaEvent
{
    public function __construct(public DateTimeInterface $at, public string $icon, public string $text, public ?string $href = null, public string $tone = '') {}
}
