<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Http\Controllers\Backend;

use Basalto\Core\Access\Level;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Text;
use Basalto\Modules\Members\Members;
use Basalto\Modules\Members\MemberStatus;
use Basalto\Modules\Members\Models\Member;
use Basalto\Modules\Members\Registration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Die Mitglieder einer Site im Backend (M-23): Liste mit Stand, Filter und Suche; freigeben, sperren, wieder
 * freigeben und einladen darf, wer Inhalte bearbeitet (die Redaktion gibt frei); löschen, exportieren und die
 * Einstellungen (Registrierung, Anmeldeseite) nur Betreiber und Administration der Site — es sind Personendaten.
 */
final class MembersController
{
    private const PER_PAGE = 50;

    /** @var list<string> */
    private const FILTERS = ['pending', 'active', 'blocked', 'all'];

    public function __construct(private readonly CurrentSite $current, private readonly Members $members, private readonly Dates $dates, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $site = $this->site();
        $counts = Member::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $pending = (int) ($counts[MemberStatus::Pending->value] ?? 0);
        $status = in_array($request->query('status'), self::FILTERS, true) ? (string) $request->query('status') : ($pending > 0 ? 'pending' : 'all');
        $q = trim((string) $request->query('q', ''));
        $page = $this->query($status, $q)->with('customer')->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();
        /** @var list<Member> $items */
        $items = $page->items();
        $relative = $this->dates->relativeFormatter();
        $pages = app(Pages::class);
        $protected = [];
        foreach ($pages->entries($site) as $entry) {
            if ($pages->isProtected($entry)) {
                $protected[] = ['id' => $entry->id, 'title' => $pages->versionOf($entry, $site->default_locale, false)->title ?? '#'.$entry->id,
                    'path' => $pages->urlOf($entry, $site->default_locale, false), 'edit' => route('basalto.pages.edit', $entry)];
            }
        }
        // CR-005: Einstellungen und Export der Mitglieder — «Mitglieder · alles»
        $manage = Gate::allows('area', [$site, 'members', Level::All]);

        return Inertia::render('members::Index', [
            'rows' => array_map(fn (Member $m): array => [
                'id' => $m->id, 'name' => $m->customer->name, 'email' => $m->customer->email, 'initials' => self::initials($m->customer->name ?? $m->customer->email),
                'locale' => strtoupper($m->customer->locale), 'status' => $m->status->value, 'status_label' => $m->status->label(),
                'since' => $relative($m->created_at), 'last_login' => $m->customer->last_login_at === null ? null : $relative($m->customer->last_login_at),
                'invited' => $m->invited_at !== null,
                'urls' => ['status' => route('basalto.members.status', $m->id), 'destroy' => route('basalto.members.destroy', $m->id)],
            ], $items),
            'counts' => ['pending' => $pending, 'active' => (int) ($counts[MemberStatus::Active->value] ?? 0), 'blocked' => (int) ($counts[MemberStatus::Blocked->value] ?? 0),
                'all' => (int) $counts->sum()],
            'filters' => ['status' => $status, 'q' => $q],
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total(), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'settings' => ['registration' => $this->members->registration($site)->value, 'login_path' => (string) app(Settings::class)->get(Members::LOGIN_PATH, '', $site),
                'login_default' => $this->members->loginPath($site, $site->default_locale)],
            'registrations' => array_map(static fn (Registration $r): array => ['value' => $r->value, 'label' => $r->label()], Registration::cases()),
            'protected' => $protected,
            'locales' => $site->localeCodes(),
            'default_locale' => $site->default_locale,
            'can' => ['manage' => $manage],
            'urls' => ['index' => route('basalto.members.index'), 'invite' => route('basalto.members.invite'), 'settings' => route('basalto.members.settings'),
                'bulk' => route('basalto.members.bulk'), 'export' => route('basalto.members.export'), 'pages' => route('basalto.pages.index')],
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $site = $this->site();
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254'], 'name' => ['nullable', 'string', 'max:120'], 'locale' => ['required', Rule::in($site->localeCodes())]],
            [], ['email' => __('E-Mail'), 'name' => __('Name'), 'locale' => __('Sprache')]);
        $name = is_string($data['name'] ?? null) && trim($data['name']) !== '' ? trim($data['name']) : null;
        $member = $this->members->invite($site, (string) $data['email'], $name, (string) $data['locale'], $request->user());

        return back()->with('status', $member->status === MemberStatus::Blocked
            ? __('«:email» ist gesperrt — keine Einladung gesendet. Geben Sie den Zugang zuerst wieder frei.', ['email' => $member->customer->email])
            : __('Einladung an «:email» gesendet.', ['email' => $member->customer->email]));
    }

    public function status(Request $request, Member $member): RedirectResponse
    {
        $site = $this->site();
        $data = $request->validate(['status' => ['required', Rule::in([MemberStatus::Active->value, MemberStatus::Blocked->value])]]);
        $data['status'] === MemberStatus::Active->value ? $this->members->approve($member, $site, $request->user()) : $this->members->block($member, $site, $request->user());

        return back()->with('status', $data['status'] === MemberStatus::Active->value ? __('Mitglied freigegeben.') : __('Mitglied gesperrt.'));
    }

    public function bulk(Request $request): RedirectResponse
    {
        $site = $this->site();
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'block', 'delete'])], 'ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        if ($data['action'] === 'delete') {
            Gate::authorize('area', [$site, 'members', Level::Delete]);
        }
        $items = Member::query()->whereKey($data['ids'])->get();
        foreach ($items as $member) {
            match ($data['action']) {
                'approve' => $this->members->approve($member, $site, $request->user()),
                'block' => $this->members->block($member, $site, $request->user()),
                default => $this->members->remove($member, $site, $request->user()),
            };
        }

        return back()->with('status', trans_choice(':count Mitglied bearbeitet.|:count Mitglieder bearbeitet.', $items->count()));
    }

    public function destroy(Request $request, Member $member): RedirectResponse
    {
        $site = $this->site();
        Gate::authorize('area', [$site, 'members', Level::Delete]);
        $this->members->remove($member, $site, $request->user());

        return back()->with('status', __('Mitgliedschaft gelöscht. Das Frontend-Konto bleibt — ganz löschen über den Datenschutz (Auskunft und Löschung je E-Mail).'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $site = $this->site();
        Gate::authorize('area', [$site, 'members', Level::All]);
        $data = $request->validate(['registration' => ['required', Rule::enum(Registration::class)], 'login_path' => ['nullable', 'string', 'max:200', 'regex:#^/(?!/)[^\s\\\\]*$#']],
            [], ['registration' => __('Registrierung'), 'login_path' => __('Anmeldeseite')]);
        $settings = app(Settings::class);
        $settings->set(Registration::SETTING, (string) $data['registration'], $site, $request->user(), Registration::Approval->value);
        $settings->set(Members::LOGIN_PATH, is_string($data['login_path'] ?? null) ? trim($data['login_path']) : '', $site, $request->user(), '');

        return back()->with('status', __('Einstellungen der Mitglieder gespeichert.'));
    }

    public function export(Request $request): StreamedResponse
    {
        $site = $this->site();
        Gate::authorize('area', [$site, 'members', Level::All]);
        $status = in_array($request->query('status'), self::FILTERS, true) ? (string) $request->query('status') : 'all';
        $query = $this->query($status, trim((string) $request->query('q', '')))->with('customer')->orderBy('id');
        $this->recorder->record('members.exported', null, null, ['count' => (clone $query)->count()], $site);
        $timezone = $this->dates->timezone();
        $time = static fn (mixed $value): string => $value instanceof \DateTimeInterface ? Carbon::instance($value)->setTimezone($timezone)->format('d.m.Y H:i') : '';

        return response()->streamDownload(static function () use ($query, $time): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}");
            fputcsv($out, [__('E-Mail'), __('Name'), __('Sprache'), __('Stand'), __('Mitglied seit'), __('Eingeladen'), __('Freigegeben'), __('Gesperrt'), __('Letzte Anmeldung')], ';', '"', '');
            $query->chunk(500, static function ($chunk) use ($out, $time): void {
                foreach ($chunk as $m) {
                    /** @var Member $m */
                    fputcsv($out, [$m->customer->email, (string) $m->customer->name, strtoupper($m->customer->locale), $m->status->label(), $time($m->created_at), $time($m->invited_at),
                        $time($m->approved_at), $time($m->blocked_at), $time($m->customer->last_login_at)], ';', '"', '');
                }
            });
            fclose($out);
        }, sprintf('mitglieder-%s-%s.csv', $site->handle, now()->format('Y-m-d')), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<Member> */
    private function query(string $status, string $q): Builder
    {
        $query = Member::query();
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $query->whereHas('customer', static fn (Builder $c) => $c->withoutGlobalScopes()->where(static fn (Builder $w) => $w->whereRaw('LOWER(email) like ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(name, \'\')) like ?', [$like])));
        }

        return $query;
    }

    private function site(): Site
    {
        $site = $this->current->require();
        abort_unless(app(Backend::class)->moduleEnabled(Members::MODULE, $site), 404);
        Gate::authorize('area', [$site, 'members', Level::View]);

        return $site;
    }

    private static function initials(string $name): string
    {
        return Text::initials($name);
    }
}
