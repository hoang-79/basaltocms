<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

/** Ein Projekt, wie es das Projektmanagement anderen Plugins zeigt (CR-020) — nur lesen. */
final readonly class ProjectData
{
    public const STATUSES = ['planned', 'active', 'on_hold', 'completed', 'cancelled'];

    /** @param  string  $status  einer der {@see STATUSES} */
    public function __construct(
        public int $id,
        public string $number,
        public string $name,
        public ?int $partnerId = null,
        public string $clientName = '',
        public string $status = 'active',
        public bool $archived = false,
    ) {}

    /** «P-0007 · Website-Relaunch» — für Auswahllisten. */
    public function label(): string
    {
        return $this->number !== '' ? $this->number.' · '.$this->name : $this->name;
    }

    /** Läuft das Projekt noch (geplant, aktiv oder pausiert)? */
    public function open(): bool
    {
        return ! $this->archived && in_array($this->status, ['planned', 'active', 'on_hold'], true);
    }
}
