<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Level;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Translations;
use Basalto\Core\Demo\DemoMode;
use Basalto\Core\License\LicenseStatus;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\FormSubmission;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\NotFoundHit;
use Basalto\Core\Models\Site;
use Basalto\Core\Operations\Operators;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\PasskeyUser;

/**
 * Die Übersicht im Modus Inhalte (Mockup 9): Führungssatz, Hinweise nur wenn nötig, Aufgaben mit einem Klick zum Ziel,
 * dann Sites, Lizenz und Betrieb — «nie still» (K-14, K-16).
 */
final class DashboardController
{
    public function __construct(private readonly CurrentSite $current, private readonly Operations $operations, private readonly Dates $dates) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $site = $this->current->get();
        $passkeys = $user instanceof PasskeyUser ? $user->passkeys()->count() : 0;
        $license = LicenseStatus::current();
        $operations = $this->operations->summary();

        // CR-005: Prüfen und Veröffentlichen sind Aufgaben der Freigabe, 404-Treffer gehören zum Veröffentlichen
        $mayApprove = $site !== null && Gate::allows('area', [$site, 'approval', Level::View]);
        $inReview = ! $mayApprove ? collect() : EntryVersion::query()->whereHas('entry', static fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id))->where('status', EntryStatus::InReview->value)->with('entry')->latest('id')->limit(10)->get();
        $scheduled = $site === null ? collect() : EntryVersion::query()->whereHas('entry', static fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id))->where('status', EntryStatus::Scheduled->value)->with('entry')->orderBy('scheduled_for')->limit(10)->get();

        // Aufgaben (K-14): jede mit einem Klick am Ziel — Prüfen, Veröffentlichen, Geplant, Zurückgewiesen
        $tasks = [];
        $userId = (int) $user?->getAuthIdentifier();
        foreach ($inReview as $version) {
            $tasks[] = ['kind' => 'review', 'title' => __(':title · Fassung :number (:locale)', ['title' => $version->title, 'number' => $version->number, 'locale' => strtoupper($version->locale)]),
                'meta' => __('In Prüfung · eingereicht :when', ['when' => $this->dates->relative($version->submitted_at ?? $version->updated_at) ?? '']), 'href' => route('basalto.entries.review', [$version->entry_id, $version->locale]), 'action' => __('Prüfen')];
        }
        $approved = ! $mayApprove ? collect() : EntryVersion::query()->whereHas('entry', static fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id))->where('status', EntryStatus::Approved->value)->latest('id')->limit(10)->get();
        foreach ($approved as $version) {
            $tasks[] = ['kind' => 'approved', 'title' => __(':title · Fassung :number (:locale)', ['title' => $version->title, 'number' => $version->number, 'locale' => strtoupper($version->locale)]),
                'meta' => __('Freigegeben · wartet auf Veröffentlichung'), 'href' => route('basalto.entries.review', [$version->entry_id, $version->locale]), 'action' => __('Veröffentlichen')];
        }
        foreach ($scheduled as $version) {
            $tasks[] = ['kind' => 'scheduled', 'title' => __(':title · Fassung :number (:locale)', ['title' => $version->title, 'number' => $version->number, 'locale' => strtoupper($version->locale)]),
                'meta' => __('Geplant').' · '.($version->scheduled_for?->timezone($site !== null ? $site->timezone : 'UTC')->format('d.m.Y, H:i') ?? '').' '.($site !== null ? $site->timezone : ''),
                'href' => route('basalto.entries.review', [$version->entry_id, $version->locale]), 'action' => __('Ansehen')];
        }
        $rejected = $site === null || $userId === 0 ? collect() : EntryVersion::query()->whereHas('entry', static fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id))
            ->where('status', EntryStatus::Draft->value)->whereNotNull('comment')->where(static fn ($q) => $q->where('author_id', $userId)->orWhere('submitted_by', $userId))->latest('id')->limit(10)->get();
        foreach ($rejected as $version) {
            $tasks[] = ['kind' => 'rejected', 'title' => __(':title · Fassung :number (:locale)', ['title' => $version->title, 'number' => $version->number, 'locale' => strtoupper($version->locale)]),
                'meta' => __('Zurückgewiesen: «:comment»', ['comment' => (string) str((string) $version->comment)->limit(120)]), 'href' => route('basalto.entries.edit', [$version->entry_id, $version->locale]), 'action' => __('Überarbeiten')];
        }

        // 404-Treffer (M-10): Adressen ohne Ziel aus den letzten sieben Tagen — mit einem Klick zum Protokoll
        if ($site !== null && Gate::allows('area', [$site, 'publishing', Level::View])) {
            $recent = NotFoundHit::query()->where('last_seen_at', '>=', now()->subDays(7));
            $paths = (clone $recent)->count();
            $top = $paths > 0 ? (clone $recent)->orderByDesc('hits')->first() : null;
            if ($top !== null) {
                $tasks[] = ['kind' => 'not_found', 'title' => trans_choice(':count Adresse ohne Ziel|:count Adressen ohne Ziel', $paths, ['count' => $paths]),
                    'meta' => __('404-Treffer der letzten 7 Tage · am häufigsten :path (:hits×)', ['path' => $top->path, 'hits' => $top->hits]),
                    'href' => route('basalto.pages.index', ['tab' => 'not_found']), 'action' => __('Umleitung anlegen')];
            }
        }

        // Übersetzung (M-06): veraltete oder fehlende Pflichtsprachen der zuletzt geänderten Inhalte
        if ($site !== null) {
            $required = array_values(array_diff($site->requiredLocales(), [$site->default_locale]));
            $translations = app(Translations::class);
            foreach ($required === [] ? collect() : Entry::query()->with(['versions', 'site.locales'])->latest('updated_at')->limit(100)->get() as $entry) {
                foreach ($required as $locale) {
                    $state = $translations->state($entry, $locale);
                    if ($state === Translations::OUTDATED || $state === Translations::MISSING) {
                        $title = $entry->versions->where('locale', $site->default_locale)->sortByDesc('number')->first()->title ?? __('(ohne Titel)');
                        $tasks[] = ['kind' => 'translation', 'title' => sprintf('%s · %s', $title, strtoupper($locale)), 'meta' => $state === Translations::MISSING ? __('Übersetzung fehlt · Pflichtsprache') : __('Übersetzung veraltet · Pflichtsprache'),
                            'href' => route('basalto.entries.translation', [$entry, $locale]), 'action' => __('Übersetzen')];
                    }
                }
                if (count($tasks) >= 30) {
                    break;
                }
            }
        }

        $hints = [];
        if ($passkeys === 0) {
            $hints[] = ['kind' => 'passkey', 'text' => __('Dieses Konto hat noch keinen Passkey. Mit einem Passkey melden Sie sich ohne Code an, auch ohne Mail-Server.'), 'href' => route('basalto.passkeys'), 'action' => __('Passkey einrichten')];
        }
        // CR-005: Lizenz und Betrieb sieht nur, wer die Bereiche sieht; die Demo braucht keinen Lizenzhinweis
        $seesLicense = $site !== null && Gate::allows('area', [$site, 'license', Level::View]) && ! DemoMode::enabled();
        if ($seesLicense && ! $license->compliant) {
            $hints[] = ['kind' => 'license', 'text' => __('Unregistriert.').' '.($license->hints[0] ?? '').($license->bannerFrom() !== null ? ' '.__('Ohne gültigen Schlüssel zeigt das Frontend ab :date eine Fussleiste.', ['date' => $this->dates->date($license->bannerFrom())]) : ''), 'href' => Vendor::url('lizenz-kaufen'), 'action' => __('Lizenz auf :vendor', ['vendor' => Vendor::host()])];
        }
        if ($site !== null && app(Settings::class)->get('media.alt_required', false, $site) === true) {
            $locales = $site->localeCodes();
            $withoutAlt = Media::query()->where('mime', 'like', 'image/%')->whereHas('usages')->get(['id', 'mime', 'alt'])->filter(static fn (Media $m): bool => $m->missingAlt($locales) !== [])->count();
            if ($withoutAlt > 0) {
                $hints[] = ['kind' => 'alt', 'text' => trans_choice(':count eingebundenes Bild ohne Alt-Text — die Veröffentlichung dieser Inhalte wird abgewiesen, bis der Alt-Text je Sprache steht.|:count eingebundene Bilder ohne Alt-Text — die Veröffentlichung dieser Inhalte wird abgewiesen, bis der Alt-Text je Sprache steht.', $withoutAlt, ['count' => $withoutAlt]), 'href' => route('basalto.media.index'), 'action' => __('Zur Mediathek')];
            }
        }
        // Formulare (M-11): Eingänge, deren Mail nicht zugestellt wurde — sie liegen gespeichert, aber niemand weiss davon
        if ($site !== null && Gate::allows('create', new Entry(['site_id' => $site->id]))) {
            $failedMails = FormSubmission::query()->where('mail_status', 'failed')->count();
            if ($failedMails > 0) {
                $hints[] = ['kind' => 'forms', 'text' => trans_choice(':count Formulareingang mit nicht zugestellter Mail — im Eingang steht der Grund, dort lässt sich die Mail erneut senden.|:count Formulareingänge mit nicht zugestellter Mail — im Eingang steht der Grund, dort lässt sich die Mail erneut senden.', $failedMails, ['count' => $failedMails]),
                    'href' => route('basalto.forms.index'), 'action' => __('Zu den Formularen')];
            }
        }
        // Nie still (K-16): jeder fehlgeschlagene Job bleibt oben, bis ihn jemand wiederholt oder verwirft
        // TB 12 Scheibe 1: Jobs gehören der Instanz — auf der SaaS sieht sie nur der Betreiber, nicht die Kundenkonten
        $instanceView = (! Saas::enabled() || Operators::allows($user)) && $site !== null && Gate::allows('area', [$site, 'operations', Level::View]);
        if (! $instanceView) {
            $operations['queue_failed'] = 0;
        }
        $canOperate = $site !== null && Gate::allows('area', [$site, 'operations', Level::Edit]);
        foreach ($instanceView ? $this->operations->failedJobs(3) : [] as $job) {
            $hints[] = ['kind' => 'jobs', 'text' => __('Job «:name» fehlgeschlagen (:when): :message', ['name' => $job['name'], 'when' => $this->dates->relative($job['failed_at']) ?? '', 'message' => $job['message']]), 'href' => null, 'action' => null,
                'actions' => $canOperate ? [['label' => __('Noch einmal versuchen'), 'url' => route('basalto.operations.jobs.retry', $job['uuid']), 'method' => 'post'], ['label' => __('Verwerfen'), 'url' => route('basalto.operations.jobs.forget', $job['uuid']), 'method' => 'delete']] : []];
        }
        if ($operations['queue_failed'] > 3) {
            $hints[] = ['kind' => 'jobs', 'text' => __('Dazu :count weitere fehlgeschlagene Jobs — :command zeigt alle.', ['count' => $operations['queue_failed'] - 3, 'command' => 'php artisan queue:failed']), 'href' => null, 'action' => null];
        }

        $sites = [];
        foreach (($user === null ? collect() : Site::query()->forUser($user)->with(['locales', 'domains'])->get()) as $s) {
            $sites[] = ['id' => $s->id, 'handle' => $s->handle, 'name' => $s->name, 'locales' => $s->localeCodes(),
                'domains' => $s->domains->map(static fn ($d): array => ['host' => $d->host, 'kind' => $d->kind, 'verified' => $d->verified_at !== null])->values()->all(),
                'edit_url' => Gate::allows('area', [$s, 'settings', Level::View]) ? route('basalto.sites.edit', $s) : null];
        }

        return Inertia::render('core::Dashboard', [
            'date' => CarbonImmutable::now($site !== null ? $site->timezone : 'Europe/Zurich')->settings(['locale' => app()->getLocale()])->translatedFormat(__('l, j. F')),
            'lead' => $this->lead($inReview->count(), $scheduled->count(), $operations['queue_failed'], $approved->count(), count(array_filter($tasks, static fn (array $t): bool => $t['kind'] === 'translation'))),
            'hints' => $hints,
            'tasks' => $tasks,
            'sites' => $sites,
            // TB 12 Scheibe 1: auf der SaaS keine Lizenzkarte (Lizenz im Abo, Plan unter Verwaltung › Konto)
            'license' => Saas::enabled() || ! $seesLicense ? null : [...$license->toArray(), 'banner_from' => $this->dates->date($license->bannerFrom())],
            // TB 12 Scheibe 1: Betrieb der Instanz auf der SaaS nur für den Betreiber (GuardSaasInstanceAreas)
            'operations' => $instanceView ? $operations : null,
            'passkeys' => ['count' => $passkeys, 'url' => route('basalto.passkeys')],
        ]);
    }

    private function lead(int $review, int $scheduled, int $failed, int $approved = 0, int $translations = 0): string
    {
        $parts = [];
        if ($review > 0) {
            $parts[] = trans_choice(':count Fassung wartet auf Ihre Prüfung|:count Fassungen warten auf Ihre Prüfung', $review, ['count' => $review]);
        }
        if ($approved > 0) {
            $parts[] = trans_choice(':count freigegebene Fassung wartet auf Veröffentlichung|:count freigegebene Fassungen warten auf Veröffentlichung', $approved, ['count' => $approved]);
        }
        if ($translations > 0) {
            $parts[] = trans_choice(':count Übersetzung ist veraltet oder fehlt|:count Übersetzungen sind veraltet oder fehlen', $translations, ['count' => $translations]);
        }
        if ($scheduled > 0) {
            $parts[] = trans_choice(':count Veröffentlichung ist geplant|:count Veröffentlichungen sind geplant', $scheduled, ['count' => $scheduled]);
        }
        if ($failed > 0) {
            $parts[] = trans_choice(':count Job ist fehlgeschlagen|:count Jobs sind fehlgeschlagen', $failed, ['count' => $failed]);
        }

        return $parts === [] ? __('Nichts wartet auf Sie.') : implode(', ', $parts).'.';
    }
}
