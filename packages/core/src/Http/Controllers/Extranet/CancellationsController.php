<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Legal\Cancellation\ContractCancellations;
use Basalto\Core\Models\ContractCancellation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kündigungen über «Verträge hier kündigen» im Extranet (CR-011 TB 59): alle Eingänge mit Stand; was Basalto nicht
 * selbst zuordnen konnte, erledigt das Team hier — mit dem Ende des Vertrags oder «kein Vertrag gefunden». Beides geht
 * als Bestätigung an die Person.
 */
final class CancellationsController
{
    public function __construct(private readonly ContractCancellations $cancellations) {}

    public function index(Request $request): Response
    {
        $filter = $request->query('filter') === 'review' ? 'review' : 'all';
        $items = ContractCancellation::query()->with('site')
            ->when($filter === 'review', static fn ($q) => $q->where('status', ContractCancellation::REVIEW))
            ->latest('received_at')->latest('id')->limit(200)->get()
            ->map(fn (ContractCancellation $c): array => [
                'id' => $c->id, 'number' => $c->number, 'status' => $c->status,
                'received' => $c->received_at->timezone($c->site->timezone ?? 'Europe/Zurich')->format('d.m.Y, H:i'),
                'kind' => $c->kind, 'reason' => $c->reason, 'name' => $c->name, 'email' => $c->email,
                'contract' => $this->cancellations->describe($c, 'de'), 'reference' => $c->reference,
                'wish' => $c->wish_date?->format('d.m.Y'), 'ends' => $c->ends_at?->format('d.m.Y'), 'note' => $c->note,
                'site' => $c->site->name ?? '—',
                'resolve_url' => $c->status === ContractCancellation::REVIEW ? route('extranet.cancellations.resolve', $c->id) : null,
            ])->all();

        return Inertia::render('core::Extranet/Cancellations', [
            'items' => $items,
            'filter' => $filter,
            'counts' => ['review' => ContractCancellation::query()->where('status', ContractCancellation::REVIEW)->count(), 'all' => ContractCancellation::query()->count()],
            'urls' => ['index' => route('extranet.cancellations.index')],
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function resolve(Request $request, int $cancellation): RedirectResponse
    {
        $item = ContractCancellation::query()->where('status', ContractCancellation::REVIEW)->findOrFail($cancellation);
        $data = $request->validate([
            'outcome' => ['required', 'in:ended,none'],
            'ends' => ['nullable', 'required_if:outcome,ended', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:outcome,none'],
        ], [], ['ends' => __('Ende des Vertrags'), 'note' => __('Hinweis')]);
        $user = $request->user();
        abort_if($user === null, 403);
        $end = $data['outcome'] === 'ended' ? CarbonImmutable::createFromFormat('Y-m-d', (string) $data['ends']) : null;
        $note = is_string($data['note'] ?? null) && trim($data['note']) !== '' ? trim($data['note']) : null;
        $this->cancellations->resolve($item, $end, $note, $user);

        return back()->with('status', __('Kündigung :number erledigt — die Bestätigung ist unterwegs.', ['number' => $item->number]));
    }
}
