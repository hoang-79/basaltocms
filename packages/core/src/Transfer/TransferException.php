<?php

declare(strict_types=1);

namespace Basalto\Core\Transfer;

use DomainException;

/**
 * Erwarteter Fehler bei Export, Import, Sicherung und Prüfung: falsches Format, Prüfsumme, unsicherer Pfad im Archiv,
 * Site schon vorhanden, Werkzeug fehlt. Befehle melden ihn als Satz; alles andere (SQL, Speicher) bleibt eine Ausnahme.
 */
final class TransferException extends DomainException {}
