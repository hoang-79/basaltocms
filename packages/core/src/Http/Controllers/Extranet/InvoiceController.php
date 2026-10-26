<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Access\Level;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Extranet\CreditNotes;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\Extranet\InvoiceSource;
use Basalto\Core\Extranet\Notifications\ExtranetInvoiceMail;
use Basalto\Core\Invoices\Money;
use Basalto\Core\Models\CreditNote;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rechnungen (CR-004, P-12) aus SaaS und Lizenzverkauf in einer Liste: PDF, Zahlungseingang der QR-Rechnung verbuchen,
 * Erinnerung (Mahnung), erneut senden, Gutschrift mit eigener Nummer.
 *
 * @phpstan-import-type Record from InvoiceSource
 */
final class InvoiceController
{
    public const FILTERS = ['open', 'overdue', 'paid'];

    public function __construct(private readonly ExtranetRegistry $registry, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : null;
        $user = $request->user();
        $access = app(ExtranetAccess::class);
        $canEdit = $user !== null && $access->allows($user, 'extranet_invoices', Level::Edit);
        $canCredit = $user !== null && $access->allows($user, 'extranet_invoices', Level::All);
        $locale = app()->getLocale();
        $rows = [];
        foreach ($this->registry->invoiceSources() as $source) {
            foreach ($source->list($filter) as $row) {
                $credited = CreditNotes::credited($row['source'], $row['id']);
                $key = [$row['source'], $row['id']];
                $rows[] = $row + [
                    'source_label' => $source->label(),
                    'amount' => Money::format($row['total'], $row['currency'], $locale),
                    'credited' => $credited > 0 ? Money::format($credited, $row['currency'], $locale) : null,
                    'credit_left' => max(0, $row['total'] - $credited),
                    'status_label' => match (true) {
                        $row['status'] === 'paid' => __('bezahlt'),
                        $row['status'] === 'cancelled' => __('storniert'),
                        $row['overdue'] => __('überfällig'),
                        default => __('offen'),
                    },
                    'urls' => [
                        'pdf' => route('extranet.invoices.pdf', $key),
                        'paid' => $canEdit && $row['payable'] ? route('extranet.invoices.paid', $key) : null,
                        'remind' => $canEdit && $row['status'] === 'open' ? route('extranet.invoices.remind', $key) : null,
                        'send' => $canEdit && $row['email'] !== '' ? route('extranet.invoices.send', $key) : null,
                        'credit' => $canCredit && $row['status'] !== 'cancelled' && $row['total'] - $credited > 0 ? route('extranet.invoices.credit', $key) : null,
                    ],
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => (CarbonImmutable::createFromFormat('d.m.Y', $b['issued']) ?: CarbonImmutable::now())
            <=> (CarbonImmutable::createFromFormat('d.m.Y', $a['issued']) ?: CarbonImmutable::now()) ?: strcmp($b['number'], $a['number']));
        $credits = CreditNote::query()->latest('id')->limit(50)->get()->map(static fn (CreditNote $c): array => [
            'id' => $c->id, 'number' => $c->number, 'invoice' => $c->invoice_number, 'amount' => Money::format($c->amount, $c->currency, app()->getLocale()), 'reason' => $c->reason,
            'date' => $c->issued_at->format('d.m.Y'), 'pdf' => route('extranet.invoices.credit_pdf', $c->id),
        ])->all();

        return Inertia::render('core::Extranet/Invoices', [
            'rows' => $rows,
            'credits' => $credits,
            'filters' => [['value' => 'open', 'label' => __('offen')], ['value' => 'overdue', 'label' => __('überfällig')], ['value' => 'paid', 'label' => __('bezahlt')]],
            'current' => ['filter' => $filter],
            'urls' => ['index' => route('extranet.invoices.index')],
        ]);
    }

    public function pdf(string $source, int $id): HttpResponse
    {
        $invoice = $this->record($source, $id);

        return response($this->source($source)->pdf($id), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="rechnung-'.strtolower($invoice['number']).'.pdf"']);
    }

    public function paid(Request $request, string $source, int $id): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);
        try {
            $message = $this->source($source)->markPaid($id, $user);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', $message);
    }

    public function remind(string $source, int $id): RedirectResponse
    {
        $invoice = $this->record($source, $id);
        if ($invoice['status'] !== 'open') {
            return back()->withErrors(['invoice' => __('Rechnung :number ist nicht offen.', ['number' => $invoice['number']])]);
        }
        $this->mail($invoice, ExtranetInvoiceMail::REMINDER, $this->source($source)->pdf($id), 'rechnung-'.strtolower($invoice['number']).'.pdf');
        $this->recorder->record('extranet.invoice_reminded', null, null, ['rechnung' => $invoice['number'], 'an' => $invoice['email']]);

        return back()->with('status', __('Erinnerung zu :number an :email gesendet.', ['number' => $invoice['number'], 'email' => $invoice['email']]));
    }

    public function send(string $source, int $id): RedirectResponse
    {
        $invoice = $this->record($source, $id);
        abort_if($invoice['email'] === '', 422, __('Zu dieser Rechnung gibt es keine E-Mail-Adresse.'));
        $this->mail($invoice, ExtranetInvoiceMail::SEND, $this->source($source)->pdf($id), 'rechnung-'.strtolower($invoice['number']).'.pdf');
        $this->recorder->record('extranet.invoice_sent', null, null, ['rechnung' => $invoice['number'], 'an' => $invoice['email']]);

        return back()->with('status', __('Rechnung :number an :email gesendet.', ['number' => $invoice['number'], 'email' => $invoice['email']]));
    }

    public function credit(Request $request, string $source, int $id, CreditNotes $notes): RedirectResponse
    {
        $invoice = $this->record($source, $id);
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,7}(?:[.,]\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['amount' => __('Betrag'), 'reason' => __('Grund')]);
        $cents = (int) round((float) str_replace(',', '.', (string) $data['amount']) * 100);
        try {
            $note = $notes->issue($invoice, $cents, trim((string) $data['reason']), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
        if ($invoice['email'] !== '') {
            $this->mail($invoice, ExtranetInvoiceMail::CREDIT, $notes->pdf($note), CreditNotes::filename($note), $note->number, Money::format($note->amount, $note->currency, $invoice['locale']));
        }

        return back()->with('status', __('Gutschrift :number zu :invoice erstellt und gesendet.', ['number' => $note->number, 'invoice' => $invoice['number']]));
    }

    public function creditPdf(int $note, CreditNotes $notes): HttpResponse
    {
        $credit = CreditNote::query()->findOrFail($note);

        return response($notes->pdf($credit), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.CreditNotes::filename($credit).'"']);
    }

    private function source(string $key): InvoiceSource
    {
        return $this->registry->invoiceSource($key) ?? abort(404);
    }

    /** @return Record */
    private function record(string $source, int $id): array
    {
        return $this->source($source)->find($id) ?? abort(404);
    }

    /** @param  Record  $invoice */
    private function mail(array $invoice, string $kind, string $pdf, string $filename, ?string $number = null, ?string $amount = null): void
    {
        $mail = new ExtranetInvoiceMail($kind, $number ?? $invoice['number'], $amount ?? Money::format($invoice['total'], $invoice['currency'], $invoice['locale']), $pdf, $filename, $invoice['number']);
        Notification::route('mail', $invoice['email'])->notify($mail->locale($invoice['locale']));
    }
}
