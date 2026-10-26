<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Level;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Auth\Sessions;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Models\Activity;
use Basalto\Core\Models\Invitation;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\InvitationNotification;
use Basalto\Core\Privacy\LastOwnerException;
use Basalto\Core\Privacy\PersonData;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Support\Avatars;
use Basalto\Core\Support\BackendLocale;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\PasskeyUser;

/**
 * Benutzer einer Site (K-03, K-06, Mockup 18/2): Rolle je Site, Einladung per Link mit Ablauf, Konto deaktivieren
 * (sofort ausgesperrt), Sitzungen sehen und beenden. Jede Änderung steht im Protokoll.
 */
final class UserController
{
    public function __construct(private readonly CurrentSite $current, private readonly Recorder $recorder, private readonly Dates $dates, private readonly Sessions $sessions, private readonly Settings $settings) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $lastLogins = Activity::query()->where('action', 'user.logged_in')->whereNotNull('user_id')->selectRaw('user_id, max(created_at) as last_login')->groupBy('user_id')->pluck('last_login', 'user_id');
        $self = (int) $request->user()?->getAuthIdentifier();
        $canManage = Gate::allows('manageMembers', $site);
        $access = app(Access::class);
        $roleNames = array_column($access->options($site->account_id), 'label', 'value');
        $currentSession = $request->session()->getId();
        $users = $site->users()->orderBy('name')->get()->map(function (Model $user) use ($lastLogins, $self, $canManage, $currentSession, $roleNames): array {
            $role = (string) $user->getRelationValue('pivot')?->getAttribute('role');
            $id = (int) $user->getKey();

            return [
                'id' => $id, 'name' => (string) $user->getAttribute('name'), 'email' => (string) $user->getAttribute('email'), 'avatar_url' => Avatars::url($user),
                'role' => $role !== '' ? $role : null, 'role_label' => $roleNames[$role] ?? ($role !== '' ? $role : null),
                'passkeys' => $user instanceof PasskeyUser ? $user->passkeys()->count() : 0,
                'last_login' => $this->dates->dateTime($lastLogins->get($id)),
                'deactivated' => $user->getAttribute('deactivated_at') !== null, 'is_self' => $id === $self,
                'sessions' => $canManage || $id === $self ? $this->sessions->forUser($id, $currentSession) : [],
                'urls' => ['deactivate' => route('basalto.users.deactivate', $id), 'activate' => route('basalto.users.activate', $id), 'sessions_end' => route('basalto.users.sessions.end', $id),
                    'export' => route('basalto.users.export', $id), 'delete' => route('basalto.users.destroy', $id)],
            ];
        })->values()->all();
        $inviters = $this->names(Invitation::query()->whereNull('accepted_at')->pluck('invited_by'));
        $invitations = Invitation::query()->whereNull('accepted_at')->latest('id')->get()->map(fn (Invitation $i): array => [
            'id' => $i->id, 'email' => $i->email, 'role_label' => $roleNames[$i->role] ?? $i->role, 'invited_by' => $i->invited_by === null ? null : ($inviters[$i->invited_by] ?? '—'),
            'expires_at' => $this->dates->dateTime($i->expires_at), 'expired' => $i->isExpired(),
            'urls' => ['resend' => route('basalto.invitations.resend', $i), 'revoke' => route('basalto.invitations.revoke', $i)],
        ])->values()->all();

        return Inertia::render('core::Users/Index', [
            'users' => $users,
            'invitations' => $canManage ? $invitations : [],
            'roles' => array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], $access->options($site->account_id)),
            'can_manage' => $canManage,
            'roles_url' => Gate::allows('area', [$site, 'users', Level::View]) ? route('basalto.roles.index') : null,
            'update_url' => route('basalto.users.role', ['user' => '__ID__']),
            'invitation_hours' => $this->invitationHours(),
            'urls' => ['invite' => route('basalto.invitations.store')],
            'status' => session('status'),
        ]);
    }

    public function updateRole(Request $request, int $user): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('manageMembers', $site);
        $access = app(Access::class);
        $validated = $request->validate(['role' => ['required', Rule::in($access->keys($site->account_id))]]);
        $member = $site->users()->whereKey($user)->first();
        abort_if($member === null, 404);
        $before = (string) $member->getRelationValue('pivot')?->getAttribute('role');
        $this->guardGrant($request, $site, $validated['role']);
        if ($before !== '') {
            $this->guardGrant($request, $site, $before);
        }
        $owners = $site->users()->wherePivot('role', SiteRole::Owner->value)->count();
        if ($before === SiteRole::Owner->value && $validated['role'] !== SiteRole::Owner->value && $owners <= 1) {
            return back()->withErrors(['role' => __('Die Site braucht mindestens einen Betreiber.')]);
        }
        $site->users()->updateExistingPivot($user, ['role' => $validated['role']]);
        $this->recorder->record('user.role_changed', $member, ['role' => $before], ['role' => $validated['role']], $site, $this->actor($request));

        return back()->with('status', __('Rolle von :name: :role.', ['name' => (string) $member->getAttribute('name'), 'role' => $access->name($validated['role'], $site->account_id)]));
    }

    /** Einladen: Mail mit Link, gültig `auth.invitation_hours`; eine offene Einladung derselben Adresse wird ersetzt. */
    public function invite(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('manageMembers', $site);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:200'],
            'role' => ['required', Rule::in(app(Access::class)->keys($site->account_id))],
        ], [], ['email' => __('E-Mail'), 'role' => __('Rolle')]);
        $this->guardGrant($request, $site, $validated['role']);
        $email = strtolower((string) $validated['email']);
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $existing = $userModel::query()->where('email', $email)->first();
        if ($existing !== null && $site->users()->whereKey($existing->getKey())->exists()) {
            return back()->withErrors(['email' => __(':email gehört schon zu dieser Site — die Rolle ändern Sie in der Liste.', ['email' => $email])]);
        }
        Invitation::query()->where('email', $email)->whereNull('accepted_at')->delete();
        $invitation = new Invitation(['site_id' => $site->id, 'email' => $email, 'role' => $validated['role'], 'invited_by' => (int) $request->user()?->getAuthIdentifier() ?: null]);
        $token = $invitation->renew($this->invitationHours());
        $invitation->save();
        $this->send($invitation, $token, $site, $request);
        $this->recorder->record('user.invited', $invitation, null, ['email' => $email, 'role' => $validated['role']], $site, $this->actor($request));

        return back()->with('status', __('Einladung an :email gesendet — der Link gilt :hours Stunden.', ['email' => $email, 'hours' => $this->invitationHours()]));
    }

    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('manageMembers', $site);
        abort_if($invitation->accepted_at !== null, 404);
        $token = $invitation->renew($this->invitationHours());
        $invitation->save();
        $this->send($invitation, $token, $site, $request);
        $this->recorder->record('user.invitation_resent', $invitation, null, ['email' => $invitation->email], $site, $this->actor($request));

        return back()->with('status', __('Einladung an :email erneut gesendet.', ['email' => $invitation->email]));
    }

    public function revoke(Request $request, Invitation $invitation): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('manageMembers', $site);
        $email = $invitation->email;
        $invitation->delete();
        $this->recorder->record('user.invitation_revoked', null, ['email' => $email], null, $site, $this->actor($request));

        return back()->with('status', __('Einladung an :email zurückgezogen.', ['email' => $email]));
    }

    /** Deaktivieren: sofort ausgesperrt — offene Sitzungen enden, die nächste Anfrage meldet ab. */
    public function deactivate(Request $request, int $user): RedirectResponse
    {
        [$site, $member] = $this->member($request, $user);
        abort_if((int) $request->user()?->getAuthIdentifier() === $user, 422, __('Das eigene Konto lässt sich nicht deaktivieren.'));
        if ((string) $member->getRelationValue('pivot')?->getAttribute('role') === SiteRole::Owner->value && $site->users()->wherePivot('role', SiteRole::Owner->value)->count() <= 1) {
            return back()->withErrors(['user' => __('Der einzige Betreiber lässt sich nicht deaktivieren.')]);
        }
        $member->forceFill(['deactivated_at' => now()])->save();
        $ended = $this->sessions->end($user);
        $this->recorder->record('user.deactivated', $member, ['active' => true], ['active' => false, 'sessions_ended' => $ended], $site, $this->actor($request));

        return back()->with('status', __(':name ist deaktiviert und ausgesperrt.', ['name' => (string) $member->getAttribute('name')]));
    }

    public function activate(Request $request, int $user): RedirectResponse
    {
        [$site, $member] = $this->member($request, $user);
        $member->forceFill(['deactivated_at' => null])->save();
        $this->recorder->record('user.activated', $member, ['active' => false], ['active' => true], $site, $this->actor($request));

        return back()->with('status', __(':name ist wieder aktiv.', ['name' => (string) $member->getAttribute('name')]));
    }

    /** Auskunft (N-05): alles, was Basalto zu dieser Person speichert, als JSON — auch ihre Formulareingänge. */
    public function export(Request $request, int $user): \Symfony\Component\HttpFoundation\Response
    {
        [$site, $member] = $this->member($request, $user);
        $email = (string) $member->getAttribute('email');
        $this->recorder->record('privacy.exported', $member, null, ['email' => $email], $site, $this->actor($request));

        return response()->json(app(PersonData::class)->export($email), 200, ['Content-Disposition' => sprintf('attachment; filename="auskunft-%s.json"', trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($email)), '-'))], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Löschen (N-05): Konto und Formulareingänge endgültig, Protokoll anonymisiert. Das Konto gilt über alle Sites —
     * gelöscht wird nur, wer in keiner Site Mitglied ist, die die handelnde Person nicht verwalten darf.
     */
    public function destroy(Request $request, int $user): RedirectResponse
    {
        [$site, $member] = $this->member($request, $user);
        abort_if((int) $request->user()?->getAuthIdentifier() === $user, 422, __('Das eigene Konto löscht man nicht hier.'));
        $actor = $request->user();
        foreach (Site::query()->whereHas('users', static fn ($q) => $q->whereKey($user))->get() as $other) {
            if ($actor === null || ! Gate::forUser($actor)->allows('manageMembers', $other)) {
                return back()->withErrors(['user' => __(':name ist auch in «:site» — dort dürfen Sie keine Benutzer verwalten. Entfernen Sie die Person dort zuerst.', ['name' => (string) $member->getAttribute('name'), 'site' => $other->name])]);
            }
        }
        $email = (string) $member->getAttribute('email');
        try {
            $result = app(PersonData::class)->delete($email);
        } catch (LastOwnerException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }
        $this->recorder->record('privacy.deleted', null, null, ['eingaenge' => $result['eingaenge'], 'protokoll' => $result['protokoll']], $site, $this->actor($request));

        return back()->with('status', __('Konto gelöscht — :submissions Formulareingang/-eingänge gelöscht, :entries Protokolleinträge anonymisiert.', ['submissions' => $result['eingaenge'], 'entries' => $result['protokoll']]));
    }

    public function endSessions(Request $request, int $user): RedirectResponse
    {
        [$site, $member] = $this->member($request, $user);
        $self = (int) $request->user()?->getAuthIdentifier() === $user;
        $ended = $this->sessions->end($user, $self ? $request->session()->getId() : null);
        $this->recorder->record('user.sessions_ended', $member, null, ['sessions' => $ended], $site, $this->actor($request));

        return back()->with('status', trans_choice(':count Sitzung von :name beendet.|:count Sitzungen von :name beendet.', $ended, ['count' => $ended, 'name' => (string) $member->getAttribute('name')]));
    }

    /** @return array{0: Site, 1: Model} */
    private function member(Request $request, int $user): array
    {
        $site = $this->current->require();
        Gate::authorize('manageMembers', $site);
        $member = $site->users()->whereKey($user)->first();
        abort_if($member === null, 404);

        return [$site, $member];
    }

    private function send(Invitation $invitation, string $token, Site $site, Request $request): void
    {
        $inviter = $request->user()?->getAttribute('name');
        // In der Sprache des Kontos, falls die Adresse schon eines hat — sonst in der Sprache der einladenden Person (N-01)
        $locale = array_key_first(BackendLocale::group([$invitation->email])) ?? app()->getLocale();
        Notification::route('mail', $invitation->email)->notify((new InvitationNotification(
            route('basalto.invitation.show', $token), $site->name, app(Access::class)->name($invitation->role, $site->account_id), is_string($inviter) ? $inviter : null, $this->invitationHours(), $invitation->role,
        ))->locale($locale));
    }

    /** Keine Rechte verschenken (CR-005): vergeben und entziehen nur, wer die Rolle selbst abdeckt. */
    private function guardGrant(Request $request, Site $site, string $key): void
    {
        $access = app(Access::class);
        $role = $access->resolve($key, $site->account_id);
        $actor = $this->actor($request);
        abort_if($role === null || $actor === null || ! $access->covers($actor, $site, $role), 403,
            __('Die Rolle «:role» geht über Ihre eigenen Rechte hinaus — vergeben oder entziehen kann sie nur, wer mindestens dasselbe darf.', ['role' => $access->name($key, $site->account_id)]));
    }

    private function invitationHours(): int
    {
        return (int) $this->settings->get('auth.invitation_hours', 72);
    }

    private function actor(Request $request): ?Authenticatable
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? $user : null;
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
        foreach ($userModel::query()->whereIn('id', $ids->filter()->unique()->values())->get(['id', 'name']) as $u) {
            $out[(int) $u->getKey()] = (string) $u->getAttribute('name');
        }

        return $out;
    }
}
