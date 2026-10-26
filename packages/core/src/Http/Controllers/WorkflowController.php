<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Backend\Dates;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Content\VersionDiff;
use Basalto\Core\Content\WorkflowException;
use Basalto\Core\Media\MediaUsages;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Freigabe (M-08, M-09, Mockup 13): eine Sprachfassung durch das Statusmodell führen — Einreichen, Zurückziehen,
 * Freigeben (Vier-Augen), Zurückweisen mit Kommentar, Planen in der Zeitzone der Site, Planung aufheben, Veröffentlichen,
 * Offline nehmen; dazu Diff gegen die live Fassung, Pflichtsprachen, Fassungen mit Vergleich. Die Liste «Freigabe»
 * zeigt, was wartet.
 */
final class WorkflowController
{
    public function __construct(private readonly CurrentSite $current, private readonly Dates $dates, private readonly Operations $operations, private readonly ThemeLoader $themes) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $status = in_array($request->query('status'), ['in_review', 'approved', 'scheduled'], true) ? (string) $request->query('status') : '';
        $states = [EntryStatus::InReview->value, EntryStatus::Approved->value, EntryStatus::Scheduled->value];
        $versions = EntryVersion::query()->whereIn('status', $states)
            ->whereHas('entry', static fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id))
            ->with('entry')->orderByRaw('CASE WHEN scheduled_for IS NULL THEN 1 ELSE 0 END')->orderBy('scheduled_for')->orderByDesc('id')->get();
        $names = $this->names($versions->pluck('submitted_by')->merge($versions->pluck('author_id')));
        $labels = $this->typeLabels($site);
        $userId = (int) $request->user()?->getAuthIdentifier();
        $counts = ['in_review' => 0, 'approved' => 0, 'scheduled' => 0];
        $rows = [];
        foreach ($versions as $v) {
            $key = $v->status->value;
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
            if ($status !== '' && $v->status->value !== $status) {
                continue;
            }
            $by = $v->submitted_by ?? $v->author_id;
            $rows[] = ['entry_id' => $v->entry_id, 'version_id' => $v->id, 'title' => $v->title, 'type_label' => $v->entry->pageType()?->label() ?? ($labels[$v->entry->type] ?? $v->entry->type),
                'locale' => $v->locale, 'number' => $v->number, 'status' => $v->status->value, 'status_label' => $v->status->label(),
                'author' => $by === null ? null : ($names[$by] ?? '—'), 'at' => $this->dates->relative($v->submitted_at ?? $v->updated_at),
                'scheduled_for' => $v->scheduled_for === null ? null : $this->moment($v->scheduled_for, $site),
                'url' => route('basalto.entries.review', [$v->entry_id, $v->locale]), 'own' => $by === $userId];
        }

        return Inertia::render('core::Reviews/Index', [
            'rows' => $rows, 'counts' => $counts, 'filters' => ['status' => $status], 'urls' => ['index' => route('basalto.reviews.index')],
        ]);
    }

    public function show(Request $request, Entry $entry, string $locale): Response|RedirectResponse
    {
        Gate::authorize('view', $entry);
        $site = $entry->site;
        abort_unless(in_array($locale, $site->localeCodes(), true), 404);
        $version = $entry->latestVersion($locale);
        if ($version === null) {
            return redirect()->route($locale === $site->default_locale ? 'basalto.entries.edit' : 'basalto.entries.translation', [$entry, $locale]);
        }
        $user = $request->user();
        $userId = (int) $user?->getAuthIdentifier();
        $live = $entry->publishedVersion($locale);
        $versions = $entry->versions()->where('locale', $locale)->orderByDesc('number')->get();
        $names = $this->names($versions->pluck('author_id')->merge($versions->pluck('approved_by'))->merge($versions->pluck('submitted_by'))->merge($versions->pluck('reviewed_by')));
        $schema = $this->schema($site);
        $status = $version->status;
        $canUpdate = Gate::allows('update', $entry);
        $canApprove = Gate::allows('approve', $entry);
        $canPublish = Gate::allows('publish', $entry);
        $blocked = ['approve' => null, 'publish' => null];
        if ($status === EntryStatus::InReview) {
            if ($version->fourEyesBlocks($userId)) {
                $blocked['approve'] = __('Sie haben diese Fassung selbst erstellt oder eingereicht. Mit Vier-Augen-Prinzip gibt eine andere Person frei.');
            } elseif (($missing = $version->missingRequiredLocales()) !== []) {
                $blocked['approve'] = __('Freigabe erst mit allen Pflichtsprachen — es fehlen: :locales.', ['locales' => strtoupper(implode(', ', $missing))]);
            } elseif (! $canApprove) {
                $blocked['approve'] = __('Ihre Rolle gibt nicht frei — das tut «:approver», «:administrator» oder «:owner».', ['approver' => SiteRole::Approver->label(), 'administrator' => SiteRole::Administrator->label(), 'owner' => SiteRole::Owner->label()]);
            }
        }
        if (in_array($status, [EntryStatus::Approved, EntryStatus::Scheduled], true)) {
            if ($version->slugIsTaken()) {
                $blocked['publish'] = __('Slug «:slug» ist in dieser Sprache schon veröffentlicht.', ['slug' => $version->slug]);
            } elseif (app(Settings::class)->get('media.alt_required', false, $site) === true && ($alt = app(MediaUsages::class)->missingAltTexts($version)) !== []) {
                $blocked['publish'] = __('Alt-Text fehlt: :files.', ['files' => implode(', ', array_map(static fn (string $n): string => '«'.$n.'»', $alt))]);
            } elseif (! $canPublish) {
                $blocked['publish'] = __('Ihre Rolle veröffentlicht nicht — das tut «:approver», «:administrator» oder «:owner».', ['approver' => SiteRole::Approver->label(), 'administrator' => SiteRole::Administrator->label(), 'owner' => SiteRole::Owner->label()]);
            }
        }
        $against = $live !== null && $live->id !== $version->id ? $live : $versions->firstWhere('number', $version->number - 1);
        $changes = $this->changes($against, $version, $schema);
        $compare = null;
        if ($request->filled(['a', 'b'])) {
            $a = $versions->firstWhere('id', (int) $request->query('a'));
            $b = $versions->firstWhere('id', (int) $request->query('b'));
            if ($a !== null && $b !== null) {
                $compare = ['a' => ['id' => $a->id, 'number' => $a->number], 'b' => ['id' => $b->id, 'number' => $b->number], 'fields' => $this->changes($a, $b, $schema)['fields']];
            }
        }
        $required = [];
        foreach ($site->locales as $l) {
            $latest = $entry->latestVersion($l->locale);
            $required[] = ['locale' => $l->locale, 'is_default' => (bool) $l->is_default, 'is_required' => (bool) $l->is_required || $l->locale === $site->default_locale,
                'status' => $latest?->status->value, 'status_label' => $latest?->status->label(), 'ok' => $latest !== null && $latest->status !== EntryStatus::Draft];
        }
        $heartbeat = $this->operations->summary()['heartbeat_age_seconds'];
        $scheduled = $version->scheduled_for?->timezone($site->timezone);

        return Inertia::render('core::Review', [
            'entry' => ['id' => $entry->id, 'type' => $entry->type, 'type_label' => $entry->pageType()?->label() ?? ($this->typeLabels($site)[$entry->type] ?? $entry->type), 'title' => $version->title, 'is_page' => $entry->pageType() !== null],
            'site' => ['name' => $site->name, 'timezone' => $site->timezone, 'four_eyes' => app(Settings::class)->get('four_eyes', false, $site) === true],
            'locale' => $locale,
            'version' => ['id' => $version->id, 'number' => $version->number, 'status' => $status->value, 'status_label' => $status->label(),
                'author' => $version->author_id === null ? null : ($names[$version->author_id] ?? '—'),
                'submitted_at' => $version->submitted_at === null ? null : $this->dates->relative($version->submitted_at),
                'approved_by' => $version->approved_by === null ? null : ($names[$version->approved_by] ?? '—'),
                'scheduled_for' => $version->scheduled_for === null ? null : $this->moment($version->scheduled_for, $site),
                'scheduled_date' => $scheduled?->format('Y-m-d'), 'scheduled_time' => $scheduled?->format('H:i'),
                'comment' => $version->comment === null ? null : sprintf('%s%s', $version->comment, ''), 'commented_by' => $version->reviewed_by === null ? null : ($names[$version->reviewed_by] ?? '—')],
            'live' => $live === null ? null : ['id' => $live->id, 'number' => $live->number, 'published_at' => $this->dates->date($live->published_at), 'author' => $live->author_id === null ? null : ($names[$live->author_id] ?? '—')],
            'stage' => $status->value,
            'actions' => [
                'submit' => $canUpdate && $status === EntryStatus::Draft,
                'withdraw' => $canUpdate && $status === EntryStatus::InReview,
                'approve' => $status === EntryStatus::InReview,
                'reject' => $canApprove && $status === EntryStatus::InReview,
                'schedule' => $canPublish && $status === EntryStatus::Approved,
                'unschedule' => $canPublish && $status === EntryStatus::Scheduled,
                'publish' => in_array($status, [EntryStatus::Approved, EntryStatus::Scheduled], true),
                'unpublish' => $canPublish && $live !== null,
            ],
            'blocked' => $blocked,
            'required_locales' => $required,
            'changes' => $changes,
            'versions' => $versions->map(fn (EntryVersion $v): array => [
                'id' => $v->id, 'number' => $v->number, 'status' => $v->status->value, 'status_label' => $v->status->label(),
                'author' => $v->author_id === null ? null : ($names[$v->author_id] ?? '—'), 'approved_by' => $v->approved_by === null ? null : ($names[$v->approved_by] ?? '—'),
                'created_at' => $this->dates->dateTime($v->created_at), 'published_at' => $this->dates->dateTime($v->published_at),
                'is_live' => $live !== null && $v->id === $live->id, 'is_current' => $v->id === $version->id,
                'preview_url' => route('basalto.entries.preview', [$entry, $v]), 'restore_url' => route('basalto.entries.restore', [$entry, $locale, $v]),
                'compare_url' => route('basalto.entries.review', [$entry, $locale, 'a' => $v->id, 'b' => $version->id]),
            ])->values()->all(),
            'compare' => $compare,
            'heartbeat' => ['age_seconds' => $heartbeat, 'ok' => $heartbeat !== null && $heartbeat < 300],
            'urls' => [
                'submit' => route('basalto.entries.submit', [$entry, $locale]),
                'withdraw' => route('basalto.entries.workflow', [$entry, $locale, 'withdraw']),
                'approve' => route('basalto.entries.workflow', [$entry, $locale, 'approve']),
                'reject' => route('basalto.entries.workflow', [$entry, $locale, 'reject']),
                'schedule' => route('basalto.entries.workflow', [$entry, $locale, 'schedule']),
                'unschedule' => route('basalto.entries.workflow', [$entry, $locale, 'unschedule']),
                'publish' => route('basalto.entries.workflow', [$entry, $locale, 'publish']),
                'unpublish' => route('basalto.entries.workflow', [$entry, $locale, 'unpublish']),
                'preview' => route('basalto.entries.preview', [$entry, $version]),
                'editor' => route('basalto.entries.edit', [$entry, $locale]),
                'back' => $entry->pageType() !== null ? route('basalto.pages.index') : route('basalto.entries.index', ['type' => $entry->type]),
                'review_list' => route('basalto.reviews.index'),
            ],
            'status' => session('status'),
        ]);
    }

    /** Ein Übergang — Rolle je Übergang über die Policy, Regeln im Modell; Verstösse kommen als Fehler «workflow» zurück. */
    public function transition(Request $request, Entry $entry, string $locale, string $action): RedirectResponse
    {
        $site = $entry->site;
        abort_unless(in_array($locale, $site->localeCodes(), true), 404);
        $user = $request->user();
        $userId = (int) $user?->getAuthIdentifier();
        $ability = match ($action) {
            'withdraw' => 'update',
            'approve', 'reject' => 'approve',
            'schedule', 'unschedule', 'publish', 'unpublish' => 'publish',
            default => abort(404),
        };
        Gate::authorize($ability, $entry);
        $version = $action === 'unpublish' ? $entry->publishedVersion($locale) : $entry->latestVersion($locale);
        abort_if($version === null, 404);
        $comment = '';
        $at = null;
        if ($action === 'reject') {
            $comment = (string) $request->validate(['comment' => ['required', 'string', 'max:2000']], ['comment.required' => __('Sagen Sie, was fehlt — der Kommentar steht danach am Entwurf.')])['comment'];
        }
        if ($action === 'schedule') {
            $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i']], [], ['date' => __('Datum'), 'time' => __('Zeit')]);
            $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], $site->timezone);
            if ($at === null || $at->isPast()) {
                return back()->withErrors(['date' => __('Der Zeitpunkt liegt in der Vergangenheit — für sofort «Veröffentlichen».')]);
            }
        }
        /** @var array<string, Closure(): string> $steps */
        $steps = [
            'withdraw' => static function () use ($version): string {
                $version->withdraw();

                return __('Fassung :number zurückgezogen — wieder Entwurf.', ['number' => $version->number]);
            },
            'approve' => static function () use ($version, $userId): string {
                $version->approve($userId);

                return __('Fassung :number freigegeben.', ['number' => $version->number]);
            },
            'reject' => static function () use ($version, $userId, $comment): string {
                $version->reject($userId, $comment);

                return __('Fassung :number zurückgewiesen — der Kommentar steht am Entwurf.', ['number' => $version->number]);
            },
            'schedule' => function () use ($version, $at, $site): string {
                // Eloquent speichert die Wanduhrzeit — darum vorher in UTC (Zeitzone der Anwendung) umrechnen
                $version->schedule(($at ?? CarbonImmutable::now())->utc());

                return __('Fassung :number geplant für :moment.', ['number' => $version->number, 'moment' => $this->moment($version->scheduled_for ?? CarbonImmutable::now(), $site)]);
            },
            'unschedule' => static function () use ($version): string {
                $version->unschedule();

                return __('Planung von Fassung :number aufgehoben — wieder freigegeben.', ['number' => $version->number]);
            },
            'publish' => static function () use ($version): string {
                $version->publish();

                return __('Fassung :number ist live.', ['number' => $version->number]);
            },
            'unpublish' => static function () use ($version): string {
                $version->unpublish();

                return __('Fassung :number ist offline — die Sprache hat keine veröffentlichte Fassung mehr.', ['number' => $version->number]);
            },
        ];
        try {
            $message = $steps[$action]();
        } catch (WorkflowException $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }

        return redirect()->route('basalto.entries.review', [$entry, $locale])->with('status', $message);
    }

    /**
     * Änderungen mit lesbarer Bezeichnung («hero.lead», «Seitentitel») und der Zahl unveränderter Felder.
     *
     * @return array{against: string|null, fields: list<array{path: string, label: string, kind: string, old: string|null, new: string|null}>, unchanged: int}
     */
    private function changes(?EntryVersion $old, EntryVersion $new, ?ThemeSchema $schema): array
    {
        $raw = VersionDiff::between($old, $new);
        $fields = [];
        foreach ($raw as $change) {
            $fields[] = ['path' => $change['path'], 'label' => self::label($change['path'], $new), 'kind' => $change['kind'], 'old' => self::text($change['old']), 'new' => self::text($change['new'])];
        }
        $total = 3 + count(is_array($new->data['settings']['meta'] ?? null) ? $new->data['settings']['meta'] : []);
        foreach (is_array($new->data['content'] ?? null) ? $new->data['content'] : [] as $block) {
            $total += is_array($block) && is_array($block['data'] ?? null) ? count($block['data']) : 1;
        }
        $against = $old === null ? null : ($old->status === EntryStatus::Published ? 'live' : 'previous');

        return ['against' => $against, 'fields' => $fields, 'unchanged' => max(0, $total - count($fields))];
    }

    private static function label(string $path, EntryVersion $version): string
    {
        $fixed = ['title' => __('Titel'), 'slug' => __('Slug'), 'intro' => __('Einleitung'), 'settings.meta.title' => __('Seitentitel'), 'settings.meta.description' => __('Beschreibung')];
        if (isset($fixed[$path])) {
            return $fixed[$path];
        }
        if (preg_match('/^content\.(\d+)(?:\.(.+))?$/', $path, $m) === 1) {
            $type = (string) ($version->data['content'][(int) $m[1]]['type'] ?? 'Block');

            return isset($m[2]) ? $type.'.'.$m[2] : __('Block :number (:type)', ['number' => (int) $m[1] + 1, 'type' => $type]);
        }

        return $path;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null);
    }

    private function moment(\DateTimeInterface $at, Site $site): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($site->timezone);

        return $local->settings(['locale' => 'de'])->isoFormat('dd, DD.MM.YYYY, HH:mm').' '.$site->timezone;
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function names(Collection $ids): array
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $out = [];
        foreach ($userModel::query()->whereIn('id', $ids->filter()->unique()->values())->get(['id', 'name']) as $user) {
            $out[(int) $user->getKey()] = (string) $user->getAttribute('name');
        }

        return $out;
    }

    private function schema(Site $site): ?ThemeSchema
    {
        try {
            return ThemeSchema::fromTheme($this->themes->forSite($site));
        } catch (ThemeException) {
            return null;
        }
    }

    /** @return array<string, string> */
    private function typeLabels(Site $site): array
    {
        $schema = $this->schema($site);

        return $schema === null ? [] : array_map(static fn (array $t): string => $t['label'], $schema->types);
    }
}
