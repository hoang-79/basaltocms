<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Access\Level;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\Models\ExtranetNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kunden und Kundenakte (CR-004, P-11): eine Liste über alle Quellen (SaaS, Lizenzverkauf, Marktplatz), die Akte zeigt
 * alles zu derselben E-Mail-Adresse und die Notizen des Teams.
 */
final class CustomerController
{
    public function __construct(private readonly ExtranetRegistry $registry) {}

    public function index(Request $request): Response
    {
        $search = is_string($request->query('q')) ? trim($request->query('q')) : null;
        $filter = is_string($request->query('filter')) && $request->query('filter') !== '' ? $request->query('filter') : null;
        $rows = [];
        $filters = [];
        foreach ($this->registry->customerSources() as $source) {
            foreach ($source->filters() as $key => $label) {
                $filters[] = ['value' => $key, 'label' => $label];
            }
            if ($filter !== null && ! array_key_exists($filter, $source->filters())) {
                continue;
            }
            foreach ($source->list($search, $filter) as $row) {
                $rows[] = $row + ['source_label' => $source->label(), 'url' => route('extranet.customers.show', [$row['source'], $row['id']])];
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return Inertia::render('core::Extranet/Customers', [
            'rows' => $rows,
            'filters' => $filters,
            'current' => ['q' => $search ?? '', 'filter' => $filter],
            'urls' => ['index' => route('extranet.customers.index')],
        ]);
    }

    public function show(Request $request, string $source, int $id): Response
    {
        $origin = $this->registry->customerSource($source) ?? abort(404);
        $found = $origin->find($id) ?? abort(404);
        $email = strtolower($found['email']);
        $sections = [];
        if ($email !== '') {
            foreach ($this->registry->customerSources() as $other) {
                foreach ($other->sectionsFor($email) as $section) {
                    $sections[] = $section + ['source' => $other->label()];
                }
            }
        }
        $user = $request->user();

        return Inertia::render('core::Extranet/Customer', [
            'customer' => $found + ['source_label' => $origin->label()],
            'sections' => $sections,
            'notes' => $email === '' ? [] : ExtranetNote::query()->where('email', $email)->with('author')->latest('id')->get()->map(static fn (ExtranetNote $n): array => [
                'id' => $n->id, 'body' => $n->body, 'author' => $n->author instanceof Model ? (string) $n->author->getAttribute('name') : '—', 'date' => $n->created_at?->format('d.m.Y, H:i'),
            ])->all(),
            'can_note' => $user !== null && $email !== '' && app(ExtranetAccess::class)->allows($user, 'extranet_customers', Level::Edit),
            'urls' => ['index' => route('extranet.customers.index'), 'note' => route('extranet.customers.note')],
        ]);
    }

    public function note(Request $request, Recorder $recorder): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'body' => ['required', 'string', 'max:5000']], [], ['body' => __('Notiz')]);
        $note = ExtranetNote::query()->create(['email' => strtolower($data['email']), 'user_id' => $request->user()?->getAuthIdentifier(), 'body' => trim($data['body'])]);
        $recorder->record('extranet.note', $note, null, ['email' => $note->email]);

        return back()->with('status', __('Notiz gespeichert.'));
    }
}
