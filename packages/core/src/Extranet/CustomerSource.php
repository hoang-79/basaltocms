<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

/**
 * Eine Quelle von Kunden für die Kundenakte (CR-004, P-11): SaaS-Konten (Kern), Käufer im Lizenzverkauf und Autoren
 * oder Käufer im Marktplatz (Module). Verbunden werden die Quellen über die E-Mail-Adresse.
 *
 * @phpstan-type Row array{source: string, id: int, name: string, email: string, company: string|null, meta: string, created: string|null, tags: list<string>}
 * @phpstan-type Section array{title: string, columns: list<string>, rows: array<int, array<int, string>>, empty: string}
 * @phpstan-type Card array{source: string, id: int, name: string, email: string, company: string|null, facts: list<array{0: string, 1: string}>}
 */
interface CustomerSource
{
    public function key(): string;

    public function label(): string;

    /**
     * Filter dieser Quelle für die Liste (Schlüssel → Bezeichnung), etwa «Testphase endet bald».
     *
     * @return array<string, string>
     */
    public function filters(): array;

    /**
     * @return list<Row>
     */
    public function list(?string $search, ?string $filter): array;

    /** @return Card|null */
    public function find(int $id): ?array;

    /**
     * Was diese Quelle zu einer E-Mail-Adresse weiss — Abschnitte der Akte.
     *
     * @return list<Section>
     */
    public function sectionsFor(string $email): array;
}
