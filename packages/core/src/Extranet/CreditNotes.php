<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Invoices\InvoiceSequence;
use Basalto\Core\Invoices\InvoiceSheet;
use Basalto\Core\Invoices\Money;
use Basalto\Core\Invoices\PdfRenderer;
use Basalto\Core\Models\CreditNote;
use Basalto\Core\Saas\Billing\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Gutschriften (CR-004, P-12): je Rechnung höchstens bis zu ihrem Betrag (abzüglich früherer Gutschriften), mit eigener
 * fortlaufender Nummer `G<Jahr>-<laufend>` über alle Quellen, PDF auf dem Blatt der Rechnungen.
 *
 * @phpstan-import-type Record from InvoiceSource
 */
final class CreditNotes
{
    public function __construct(private readonly BillingSettings $settings, private readonly InvoiceSheet $sheet, private readonly PdfRenderer $pdf, private readonly Recorder $recorder) {}

    /** Bereits gutgeschrieben (Rappen). */
    public static function credited(string $source, int $invoiceId): int
    {
        return (int) CreditNote::query()->where('source', $source)->where('invoice_id', $invoiceId)->sum('amount');
    }

    /**
     * @param  Record  $invoice
     *
     * @throws ValidationException
     */
    public function issue(array $invoice, int $amount, string $reason, ?Authenticatable $user): CreditNote
    {
        $left = $invoice['total'] - self::credited($invoice['source'], $invoice['id']);
        if ($amount <= 0 || $amount > $left) {
            throw ValidationException::withMessages(['amount' => __('Höchstens :amount lassen sich noch gutschreiben.', ['amount' => Money::format(max(0, $left), $invoice['currency'], app()->getLocale())])]);
        }
        $year = (int) CarbonImmutable::today()->format('Y');
        /** @var CreditNote $note */
        $note = InvoiceSequence::issue(
            static fn () => CreditNote::query()->where('year', $year),
            static fn (int $sequence): CreditNote => CreditNote::query()->create([
                'source' => $invoice['source'], 'invoice_id' => $invoice['id'], 'invoice_number' => $invoice['number'],
                'number' => sprintf('G%d-%04d', $year, $sequence), 'year' => $year, 'sequence' => $sequence, 'currency' => $invoice['currency'],
                'amount' => $amount, 'reason' => $reason, 'email' => $invoice['email'], 'debtor' => $invoice['debtor'], 'locale' => $invoice['locale'],
                'created_by' => $user?->getAuthIdentifier(), 'issued_at' => CarbonImmutable::today(),
            ]),
        );
        $this->recorder->record('extranet.credit_note', $note, null, ['number' => $note->number, 'invoice' => $invoice['number'], 'amount' => $amount], null, $user);

        return $note;
    }

    public function pdf(CreditNote $note): string
    {
        $lang = $note->locale;
        $amount = Money::format($note->amount, $note->currency, $lang);

        return $this->pdf->render($this->sheet->html([
            'lang' => $lang,
            'title' => InvoiceSheet::text(__('Gutschrift :number', ['number' => $note->number], $lang)),
            'creditor' => $this->settings->creditor()->lines(),
            'vat_number' => $this->settings->vatNumber(),
            'debtor' => $note->debtor,
            'meta' => [[InvoiceSheet::text(__('Datum', [], $lang)), $note->issued_at->format('d.m.Y')], [InvoiceSheet::text(__('Zu Rechnung', [], $lang)), $note->invoice_number]],
            'lines' => [['text' => $note->reason, 'detail' => null, 'amount' => '– '.$amount]],
            'labels' => ['position' => InvoiceSheet::text(__('Position', [], $lang)), 'amount' => InvoiceSheet::text(__('Betrag', [], $lang))],
            'sums' => [],
            'total' => [InvoiceSheet::text(__('Gutschrift', [], $lang)), $amount],
            'vat_note' => null,
            'stamp' => null,
            'note' => InvoiceSheet::text(__('Der Betrag wird mit der nächsten Rechnung verrechnet oder auf Wunsch zurückerstattet.', [], $lang)),
            'qr' => null,
            'qr_missing' => null,
        ]));
    }

    public static function filename(CreditNote $note): string
    {
        return 'gutschrift-'.strtolower($note->number).'.pdf';
    }
}
