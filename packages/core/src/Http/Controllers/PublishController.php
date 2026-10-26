<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Models\Entry;
use Basalto\Core\Publish\LegalPages;
use Basalto\Core\Publish\LegalTemplates;
use Basalto\Core\Publish\Publisher;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Station Z der Einrichtung (L-06, L-05): derselbe Lauf wie `basalto:publish` — der Probelauf zeigt jeden Befund mit
 * Ziel, «Live schalten» spielt den Seed ein, prüft, wärmt vor und schaltet live. Nur wer die Site verwalten darf.
 */
final class PublishController
{
    public function __construct(private readonly CurrentSite $current, private readonly Publisher $publisher, private readonly Dates $dates, private readonly LegalPages $legal, private readonly Recorder $recorder) {}

    public function show(): Response
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $report = $this->publisher->run($site, dryRun: true);

        return Inertia::render('core::Publish', [
            'site' => ['name' => $site->name, 'handle' => $site->handle, 'live' => $site->isLive(), 'live_at' => $this->dates->dateTime($site->live_at)],
            'report' => ['findings' => $report['findings'], 'counted' => $report['counted'], 'checks' => $report['checks'], 'errors' => $report['errors'], 'warnings' => $report['warnings']],
            'command' => sprintf('php artisan basalto:publish %s', $site->handle),
            'legal' => array_map(static fn (string $kind): array => ['kind' => $kind, 'label' => LegalTemplates::meta($kind, $site->default_locale)['title'], 'url' => route('basalto.setup.legal', $kind)], LegalTemplates::KINDS),
            'urls' => ['publish' => route('basalto.setup.publish.store'), 'setup' => route('basalto.setup')],
        ]);
    }

    /** Rechtsseite aus Vorlage — als Entwurf je Sprache; danach Platzhalter füllen und veröffentlichen. */
    public function legal(Request $request, string $kind): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));
        abort_unless(in_array($kind, LegalTemplates::KINDS, true), 404);
        $entry = $this->legal->apply($site, $kind, $request->user()?->getAuthIdentifier());
        $this->recorder->record('legal.template_applied', $entry, null, ['kind' => $kind], $site);

        return redirect()->route('basalto.entries.edit', [$entry, $site->default_locale])
            ->with('status', __('Vorlage eingesetzt — als Entwurf in jeder Sprache. Offene Platzhalter [[…]] ausfüllen, dann einreichen und veröffentlichen. Die Vorlage ersetzt keine rechtliche Prüfung.'));
    }

    public function store(): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $result = $this->publisher->run($site);
        if ($result['errors'] > 0) {
            return back()->with('status', trans_choice('Nicht live geschaltet — :count Fehler. Die Liste zeigt, was fehlt.|Nicht live geschaltet — :count Fehler. Die Liste zeigt, was fehlt.', $result['errors'], ['count' => $result['errors']]));
        }

        return back()->with('status', __(':result — :seeded Startinhalt(e) eingespielt, :warmed Seite(n) vorgewärmt.', ['result' => $result['was_live'] ? __('Veröffentlicht') : __('Live geschaltet'), 'seeded' => count($result['seed']['created'] ?? []), 'warmed' => $result['warmed']]));
    }
}
