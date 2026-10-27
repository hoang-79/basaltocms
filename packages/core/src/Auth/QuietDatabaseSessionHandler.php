<?php

declare(strict_types=1);

namespace Basalto\Core\Auth;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Sitzungen in der Datenbank ohne verlorene Schreibvorgänge: Laravel schreibt am Ende jeder Anfrage die ganze Sitzung
 * zurück — auch wenn sich nichts geändert hat. Laufen zwei Anfragen gleichzeitig (eine Abfrage der Live-Aktualisierung
 * neben der Registrierung eines Passkeys), überschreibt die spätere mit ihrem alten Stand, was die andere eben
 * gespeichert hat; die Optionen des Passkeys waren weg («Passkey registration session expired»).
 *
 * Hier schreibt eine Anfrage nur, was sie geändert hat: Unveränderte Sitzungen führen nur die letzte Aktivität nach,
 * ohne die Nutzdaten anzufassen. Ändern zwei Anfragen gleichzeitig wirklich beide etwas, gewinnt weiter die spätere
 * — das ist selten und betrifft dann bewusste Schritte, nicht die Abfragen im Hintergrund.
 */
final class QuietDatabaseSessionHandler extends DatabaseSessionHandler
{
    /** @var array<string, string> Was diese Anfrage je Sitzung gelesen hat */
    private array $readPayloads = [];

    public function read($sessionId): string|false
    {
        $data = parent::read($sessionId);
        if (is_string($data) && $data !== '') {
            $this->readPayloads[$sessionId] = $data;
        }

        return $data;
    }

    public function write($sessionId, $data): bool
    {
        if ($this->exists && ($this->readPayloads[$sessionId] ?? null) === $data) {
            // Unverändert: nur die letzte Aktivität — nie die Nutzdaten, die eine gleichzeitige Anfrage geschrieben hat
            $this->getQuery()->where('id', $sessionId)->update(['last_activity' => $this->currentTime()]);

            return true;
        }
        $this->readPayloads[$sessionId] = $data;

        return parent::write($sessionId, $data);
    }
}
