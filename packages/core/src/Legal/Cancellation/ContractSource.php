<?php

declare(strict_types=1);

namespace Basalto\Core\Legal\Cancellation;

use Basalto\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine Art Vertrag, die sich über «Verträge hier kündigen» kündigen lässt (CR-011 TB 59): das SaaS-Abo aus dem Kern,
 * die Agentur-Lizenz aus dem Lizenzverkauf. Die Quelle findet Verträge zur E-Mail-Adresse, kündigt sie auf das nächste
 * Ende und nimmt eine Kündigung zurück.
 */
interface ContractSource
{
    /** Schlüssel im Formular und in `contract_cancellations.contract` (`saas`, `agency`). */
    public function key(): string;

    /** Wie der Vertrag im Formular heisst — deutsch, übersetzt wird mit `__()`. */
    public function label(): string;

    /** Was als Kennung hilft (z. B. «Adresse Ihrer Site») — deutsch, übersetzt wird mit `__()`. */
    public function hint(): string;

    /** Lässt sich diese Art Vertrag auf der Site abschliessen (und darum dort kündigen)? */
    public function available(Site $site): bool;

    /**
     * Laufende, kündbare Verträge zur E-Mail-Adresse — mit Kennung nur die passenden.
     *
     * @return list<Model>
     */
    public function find(Site $site, string $email, ?string $reference): array;

    /** Frühestes Ende, wenn jetzt gekündigt würde. */
    public function earliestEnd(Model $contract): CarbonImmutable;

    /** Kündigen auf das nächste Ende; gibt das Ende zurück. */
    public function cancel(Model $contract): CarbonImmutable;

    /** Kündigung zurücknehmen — false, wenn es nicht mehr geht. */
    public function revoke(Model $contract): bool;

    /** Der Vertrag in einem Satzteil, z. B. «SaaS-Abo «Muster AG» (Plan Standard)». */
    public function describe(Model $contract, string $locale): string;

    /**
     * Was nach dem Ende geschieht — zusätzliche Zeilen für die Bestätigung (z. B. Sperre und Löschung des Kontos).
     *
     * @return list<string>
     */
    public function notes(Model $contract, string $locale): array;
}
