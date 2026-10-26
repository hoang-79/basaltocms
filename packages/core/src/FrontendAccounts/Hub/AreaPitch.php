<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

/** Was ein Bereich Gästen verspricht — Symbol, Titel, ein Satz (Seite «Anmelden» des Kontos). */
final readonly class AreaPitch
{
    public function __construct(public string $icon, public string $title, public string $text) {}
}
