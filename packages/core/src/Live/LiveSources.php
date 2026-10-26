<?php

declare(strict_types=1);

namespace Basalto\Core\Live;

use Closure;

/**
 * Quellen der Live-Aktualisierung (CR-008 LV-12): Kern und Module melden je Präfix (`konto`, `forum`, `konversation` …)
 * kleine Nutzdaten an, die die Abfrage zu einem geänderten Schlüssel mitliefert — etwa Zähler. Ohne Eintrag liefert
 * ein Schlüssel nur seine Version. Beim Booten gefüllt (Singleton), danach nur gelesen.
 */
final class LiveSources
{
    /** @var array<string, Closure(int, string): (array<string, mixed>|null)> */
    private array $payloads = [];

    /**
     * Nutzdaten für Schlüssel mit diesem Präfix (der erste Teil des Schlüssels).
     *
     * @param  Closure(int $siteId, string $key): (array<string, mixed>|null)  $payload
     */
    public function register(string $prefix, Closure $payload): self
    {
        $this->payloads[$prefix] = $payload;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function payload(int $siteId, string $key): ?array
    {
        $prefix = explode('.', $key, 2)[0];
        $source = $this->payloads[$prefix] ?? null;

        return $source === null ? null : $source($siteId, $key);
    }

    /** @return list<string> */
    public function prefixes(): array
    {
        return array_keys($this->payloads);
    }
}
