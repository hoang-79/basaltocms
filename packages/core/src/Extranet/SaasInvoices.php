<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\AccountInvoice;
use Basalto\Core\Saas\Billing\Invoicer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Rechnungen der SaaS-Abos im Extranet (CR-004, P-12): QR-Rechnungen von Hand als bezahlt verbuchen; Stripe-Rechnungen
 * sind bezahlt, sobald es sie gibt.
 *
 * @phpstan-import-type Row from InvoiceSource
 * @phpstan-import-type Record from InvoiceSource
 */
final class SaasInvoices implements InvoiceSource
{
    public function __construct(private readonly Invoicer $invoicer, private readonly Recorder $recorder) {}

    public function key(): string
    {
        return 'saas';
    }

    public function label(): string
    {
        return __('SaaS');
    }

    /** @return Builder<AccountInvoice> */
    public static function query(?string $filter): Builder
    {
        $query = AccountInvoice::query()->whereNotNull('account_id');

        return match ($filter) {
            'open' => $query->where('status', AccountInvoice::STATUS_OPEN),
            'overdue' => $query->where('status', AccountInvoice::STATUS_OPEN)->where('method', AccountInvoice::METHOD_INVOICE)->where('due_at', '<', CarbonImmutable::today()->toDateString()),
            'paid' => $query->where('status', AccountInvoice::STATUS_PAID),
            default => $query,
        };
    }

    public function list(?string $filter): array
    {
        $rows = [];
        foreach (self::query($filter)->with('account')->orderByDesc('id')->limit(500)->get() as $invoice) {
            $rows[] = ['source' => $this->key(), 'id' => $invoice->id, 'number' => $invoice->number, 'customer' => $invoice->account->name ?? '—', 'email' => $this->email($invoice),
                'issued' => $invoice->issued_at->format('d.m.Y'), 'due' => $invoice->due_at?->format('d.m.Y'), 'currency' => $invoice->currency, 'total' => $invoice->total,
                'status' => $invoice->status, 'overdue' => $invoice->overdue(), 'payable' => $invoice->isOpen() && $invoice->method === AccountInvoice::METHOD_INVOICE];
        }

        return $rows;
    }

    public function find(int $id): ?array
    {
        $invoice = AccountInvoice::query()->whereNotNull('account_id')->find($id);
        if ($invoice === null) {
            return null;
        }
        $billing = $invoice->billing;
        $debtor = array_values(array_filter([$billing['company'] ?? null, $billing['name'] ?? null, $billing['street'] ?? null, trim(($billing['postal_code'] ?? '').' '.($billing['city'] ?? ''))],
            static fn (mixed $v): bool => is_string($v) && trim($v) !== ''));

        return ['source' => $this->key(), 'id' => $invoice->id, 'number' => $invoice->number, 'email' => $this->email($invoice), 'name' => $invoice->account->name ?? '',
            'locale' => $invoice->locale, 'currency' => $invoice->currency, 'total' => $invoice->total, 'status' => $invoice->status, 'debtor' => $debtor];
    }

    public function pdf(int $id): string
    {
        return $this->invoicer->pdf(AccountInvoice::query()->whereNotNull('account_id')->findOrFail($id));
    }

    public function markPaid(int $id, Authenticatable $user): string
    {
        $invoice = AccountInvoice::query()->whereNotNull('account_id')->findOrFail($id);
        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages(['invoice' => __('Rechnung :number ist nicht offen.', ['number' => $invoice->number])]);
        }
        $invoice->forceFill(['status' => AccountInvoice::STATUS_PAID, 'paid_at' => now()])->save();
        $this->recorder->record('saas.invoice_paid', $invoice, ['status' => AccountInvoice::STATUS_OPEN], ['status' => AccountInvoice::STATUS_PAID, 'number' => $invoice->number], null, $user);

        return __('Zahlung zu :number verbucht — das Abo von «:account» ist bezahlt.', ['number' => $invoice->number, 'account' => $invoice->account->name ?? '']);
    }

    public static function statusLabel(AccountInvoice $invoice): string
    {
        return match (true) {
            $invoice->status === AccountInvoice::STATUS_PAID => __('bezahlt'),
            $invoice->status === AccountInvoice::STATUS_CANCELLED => __('storniert'),
            $invoice->overdue() => __('überfällig'),
            default => __('offen'),
        };
    }

    private function email(AccountInvoice $invoice): string
    {
        $email = $invoice->billing['email'] ?? null;
        if (is_string($email) && $email !== '') {
            return $email;
        }
        $account = $invoice->account;

        return $account instanceof Account ? (string) (SaasCustomers::owner($account)?->getAttribute('email') ?? '') : '';
    }
}
