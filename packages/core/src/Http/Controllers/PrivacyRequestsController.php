<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\FrontendAccounts\Notifications\PrivacyRequestReceivedNotification;
use Basalto\Core\Models\PrivacyRequest;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verwaltung › Datenschutz (CR-011 TB 61): Anliegen aus dem Kundenkonto (Berichtigung, Einschränkung, Widerspruch,
 * Sonstiges) mit Frist von einem Monat. «Erledigen» schickt die Antwort an die Person; das Konto selbst ändert die
 * Person im Kundenkonto oder das Team an Ort und Stelle.
 */
final class PrivacyRequestsController
{
    public function __construct(private readonly CurrentSite $current, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $site = $this->current->get();
        abort_if($site === null, 404);
        $filter = $request->query('filter') === 'alle' ? 'alle' : 'offen';
        $zone = $site->timezone;
        $items = PrivacyRequest::query()->withoutGlobalScopes()->where('site_id', $site->id)
            ->when($filter === 'offen', static fn ($q) => $q->where('status', PrivacyRequest::OPEN))
            ->orderBy('status')->orderBy('due_at')->limit(200)->get()
            ->map(static fn (PrivacyRequest $r): array => [
                'id' => $r->id, 'kind' => PrivacyRequest::kindLabel($r->kind), 'article' => PrivacyRequest::KINDS[$r->kind][1] ?? '', 'message' => $r->message,
                'email' => $r->email, 'name' => $r->name, 'status' => $r->status,
                'received' => $r->received_at->timezone($zone)->format('d.m.Y, H:i'), 'due' => $r->due_at->timezone($zone)->format('d.m.Y'),
                'overdue' => $r->status === PrivacyRequest::OPEN && $r->due_at->isPast(),
                'handled' => $r->handled_at?->timezone($zone)->format('d.m.Y, H:i'), 'answer' => $r->answer,
                'answer_url' => $r->status === PrivacyRequest::OPEN ? route('basalto.privacy.requests.answer', $r->id) : null,
            ])->values()->all();

        return Inertia::render('core::Privacy/Requests', [
            'items' => $items,
            'filter' => $filter,
            'counts' => ['offen' => PrivacyRequest::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('status', PrivacyRequest::OPEN)->count(),
                'alle' => PrivacyRequest::query()->withoutGlobalScopes()->where('site_id', $site->id)->count()],
            'urls' => ['index' => route('basalto.privacy.requests.index')],
        ]);
    }

    public function answer(Request $request, int $privacyRequest): RedirectResponse
    {
        $site = $this->current->get();
        abort_if($site === null, 404);
        $item = PrivacyRequest::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('status', PrivacyRequest::OPEN)->findOrFail($privacyRequest);
        $data = $request->validate(['answer' => ['required', 'string', 'min:10', 'max:5000']], [], ['answer' => __('Antwort')]);
        $item->forceFill(['status' => PrivacyRequest::DONE, 'answer' => trim((string) $data['answer']), 'handled_at' => now(), 'handled_by' => $request->user()?->getAuthIdentifier()])->save();
        $this->recorder->record('privacy.request_answered', $item, null, ['kind' => $item->kind], $site);
        Notification::route('mail', $item->email)->route('site', $site->id)->notify((new PrivacyRequestReceivedNotification($item->id, true))->locale($item->locale));

        return back()->with('status', __('Anliegen erledigt — die Antwort ist unterwegs.'));
    }
}
