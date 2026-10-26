<?php

declare(strict_types=1);

namespace Basalto\Core\Testdata;

use Basalto\Core\Models\Site;
use Closure;

/**
 * Testdaten aus Kern und Modulen (`basalto:testdaten`): Jede Quelle füllt ihren Bereich mit vielen, absichtlich
 * verschiedenen Daten — gewöhnliche, leere, sehr lange, mehrsprachige und solche mit heiklen Zeichen —, damit man
 * Ansichten und Abläufe mit echtem Bestand prüfen und Fehler finden kann. Die Reihenfolge bestimmt `order`: Der Kern
 * legt zuerst Team und Kundenkonten an, die Module verwenden sie weiter. Beim Booten gefüllt (Singleton), danach nur
 * gelesen.
 */
final class Testdata
{
    /** @var array<string, array{label: string, order: int, applies: Closure(Site): bool, seed: Closure(TestdataRun): void, remove: Closure(Site): void}> */
    private array $sources = [];

    /**
     * @param  Closure(Site): bool  $applies  passt die Quelle zur Site (Modul eingeschaltet …)?
     * @param  Closure(TestdataRun): void  $seed
     * @param  Closure(Site): void  $remove  entfernt, was die Quelle angelegt hat (vor dem Kern)
     */
    public function register(string $key, string $label, int $order, Closure $applies, Closure $seed, Closure $remove): self
    {
        $this->sources[$key] = ['label' => $label, 'order' => $order, 'applies' => $applies, 'seed' => $seed, 'remove' => $remove];

        return $this;
    }

    /** @return array<string, array{label: string, order: int, applies: Closure(Site): bool, seed: Closure(TestdataRun): void, remove: Closure(Site): void}> nach Reihenfolge */
    public function sources(): array
    {
        $sources = $this->sources;
        uasort($sources, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $sources;
    }

    /**
     * Alle passenden Quellen der Reihe nach — `$only` beschränkt auf Schlüssel (der Kern läuft immer mit).
     *
     * @param  list<string>  $only
     * @return array<string, string> Schlüssel → Bezeichnung der gelaufenen Quellen
     */
    public function seed(TestdataRun $run, array $only = []): array
    {
        $ran = [];
        foreach ($this->sources() as $key => $source) {
            if (($only !== [] && $key !== CoreTestdata::KEY && ! in_array($key, $only, true)) || ! ($source['applies'])($run->site)) {
                continue;
            }
            ($source['seed'])($run);
            $ran[$key] = $source['label'];
        }

        return $ran;
    }

    /** Testdaten einer Site entfernen — Module zuerst (umgekehrte Reihenfolge), der Kern mit den Kundenkonten zuletzt. */
    public function remove(Site $site): void
    {
        foreach (array_reverse($this->sources(), true) as $source) {
            ($source['remove'])($site);
        }
    }
}
