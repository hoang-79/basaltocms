<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

/**
 * Ein verknüpfter Datensatz aus einem anderen Plugin (CR-020) — das CRM zeigt beim Kunden seine Projekte, Rechnungen
 * und Karten, ohne die Plugins zu kennen. Beigesteuert mit {@see Connections::links()}.
 */
final readonly class LinkedRecord
{
    /**
     * @param  string  $group  übersetzte Gruppe, z. B. «Rechnungen»
     * @param  string  $state  übersetzter Stand, z. B. «offen»
     * @param  string  $tone  `neutral`, `success`, `warning`, `danger` oder `lava`
     */
    public function __construct(
        public string $group,
        public string $title,
        public string $meta = '',
        public ?string $url = null,
        public string $state = '',
        public string $tone = 'neutral',
    ) {}
}
