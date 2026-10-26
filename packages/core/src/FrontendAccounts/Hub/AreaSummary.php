<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

/**
 * Der Stand eines Bereichs: Bezeichnung und Symbol (Wabe und Überschrift), Zahl mit Beschriftung, Ton (`lava` für
 * Neues, `warn` für Achtung), dazu Aufgaben, Ereignisse, Meldungen und Knöpfe. `wide` nimmt im Konto die ganze Breite.
 */
final readonly class AreaSummary
{
    /**
     * @param  list<AreaTodo>  $todos
     * @param  list<AreaEvent>  $events
     * @param  list<AreaAction>  $actions  Knöpfe für den Kopf des Kontos (höchstens einer je Bereich zählt)
     * @param  list<AreaAction>  $tools  Knöpfe rechts in der Überschrift des Abschnitts
     * @param  list<array{text: string, tone: string}>  $notices  Meldungen oben im Konto (nach einer Aktion)
     */
    public function __construct(
        public string $label,
        public string $icon,
        public ?int $count = null,
        public string $caption = '',
        public string $tone = '',
        public array $todos = [],
        public array $events = [],
        public array $actions = [],
        public array $tools = [],
        public array $notices = [],
        public bool $wide = false,
    ) {}
}
