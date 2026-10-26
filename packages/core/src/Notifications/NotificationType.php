<?php

declare(strict_types=1);

namespace Basalto\Core\Notifications;

use Basalto\Core\Access\Level;
use Closure;

/**
 * Eine Art von Benachrichtigung (TB 27), wie Kern und Module sie im Verzeichnis anmelden: Schlüssel, deutsche
 * Beschriftung (Schlüssel der Übersetzung), Gruppe in den Einstellungen, Bereich und Stufe (wer sie sehen und
 * einstellen kann), Schicht und die Vorgabe je Kanal. `mailAvailable = false`: Die Mail stellt man anderswo ein
 * (Empfänger eines Formulars, Kommentar-Block) — hier gibt es nur die Glocke. `when`: nur sichtbar, solange es zutrifft
 * (z. B. nur im SaaS-Betrieb). `mailRequired` heisst: die Mail kommt immer (Rechnungen,
 * Kündigungen) — abschalten lässt sich dann nur die Glocke.
 */
final readonly class NotificationType
{
    public const BACKEND = 'backend';

    public const EXTRANET = 'extranet';

    /** CR-006 (TB 38): Kundenkonten im Frontend (Community) — Glocke im Forum, E-Mail sofort oder als Zusammenfassung. */
    public const FRONTEND = 'frontend';

    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public ?string $description = null,
        public ?string $area = null,
        public Level $level = Level::View,
        public string $scope = self::BACKEND,
        public bool $bell = true,
        public bool $mail = false,
        public bool $mailRequired = false,
        public string $icon = 'module',
        public bool $mailAvailable = true,
        public ?Closure $when = null,
    ) {}
}
