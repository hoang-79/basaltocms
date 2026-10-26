<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Eine Quelle von Rechnungen für das Extranet (CR-004, P-12): SaaS-Abos (Kern) und Lizenzverkauf (Modul). Liste,
 * PDF und «bezahlt verbuchen» liefert die Quelle; Erinnerung, Versand und Gutschrift macht das Extranet für alle gleich.
 *
 * @phpstan-type Row array{source: string, id: int, number: string, customer: string, email: string, issued: string, due: string|null, currency: string, total: int, status: string, overdue: bool, payable: bool}
 * @phpstan-type Record array{source: string, id: int, number: string, email: string, name: string, locale: string, currency: string, total: int, status: string, debtor: list<string>}
 */
interface InvoiceSource
{
    public function key(): string;

    public function label(): string;

    /**
     * Rechnungen, neueste zuerst; `overdue` = offen und Frist überschritten, `payable` = offen per QR (von Hand verbuchbar).
     *
     * @return list<Row>
     */
    public function list(?string $filter): array;

    /** @return Record|null */
    public function find(int $id): ?array;

    /** Das PDF der Rechnung (Bytes). */
    public function pdf(int $id): string;

    /**
     * Zahlungseingang verbuchen — die Lizenz bzw. das Abo gilt als bezahlt. Gibt die Meldung für die Oberfläche zurück.
     *
     * @throws ValidationException wenn die Rechnung nicht offen ist
     */
    public function markPaid(int $id, Authenticatable $user): string;
}
