<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Extranet\Notifications\ExtranetWelcome;
use Basalto\Core\Install\Installer;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\ExtranetMember;
use Basalto\Core\Vendor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team im Extranet (CR-004 P-09, CR-005 R-12): Inhaber (Besitz und Verwaltung des Installationskontos) dürfen alles;
 * weitere Personen bekommen eine Rolle — dieselben Rollen wie im CMS, eingestellt im Rollen-Editor. Niemand vergibt
 * eine Rolle, die mehr darf als er selbst.
 */
final class TeamController
{
    public function __construct(private readonly Access $access, private readonly ExtranetAccess $extranet, private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $account = Account::installation();
        $owners = $account->users()->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])->orderBy('name')->get()
            ->map(static fn (Model $u): array => ['name' => (string) $u->getAttribute('name'), 'email' => (string) $u->getAttribute('email')])->all();
        $options = $this->access->options($account->id, true);
        $names = array_column($options, 'label', 'value');
        $members = ExtranetMember::query()->with('user')->get()->sortBy(static fn (ExtranetMember $m): string => mb_strtolower((string) $m->user?->getAttribute('name')))->values()
            ->map(static fn (ExtranetMember $m): array => ['id' => $m->id, 'name' => (string) $m->user?->getAttribute('name'), 'email' => (string) $m->user?->getAttribute('email'),
                'role' => $m->role, 'role_label' => $names[$m->role] ?? $m->role,
                'urls' => ['update' => route('extranet.team.update', $m->id), 'destroy' => route('extranet.team.destroy', $m->id)]])->all();

        return Inertia::render('core::Extranet/Team', [
            'owners' => $owners,
            'members' => $members,
            'roles' => $options,
            'urls' => ['store' => route('extranet.team.store'), 'roles' => Vendor::url((string) config('basalto.backend.path', 'admin').'/users/rollen')],
        ]);
    }

    public function store(Request $request, Installer $installer): RedirectResponse
    {
        $account = Account::installation();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', Rule::in($this->access->keys($account->id, true))],
        ], [], ['name' => __('Name'), 'email' => __('E-Mail'), 'role' => __('Rolle')]);
        $this->guard($request, $data['role']);
        $user = $installer->user(trim((string) $data['name']), (string) $data['email']);
        if (ExtranetMember::query()->where('user_id', $user->getKey())->exists()) {
            return back()->withErrors(['email' => __(':email gehört schon zum Team.', ['email' => strtolower((string) $data['email'])])]);
        }
        $member = ExtranetMember::query()->create(['user_id' => $user->getKey(), 'role' => $data['role'], 'added_by' => $request->user()?->getAuthIdentifier()]);
        if (method_exists($user, 'notify')) {
            $user->notify(new ExtranetWelcome(Extranet::url('/')));
        }
        $this->recorder->record('extranet.member_added', $member, null, ['email' => (string) $user->getAttribute('email'), 'role' => $data['role']]);

        return back()->with('status', __(':name gehört jetzt zum Team — die Mail mit dem Zugang ist unterwegs.', ['name' => (string) $user->getAttribute('name')]));
    }

    public function update(Request $request, int $member): RedirectResponse
    {
        $item = ExtranetMember::query()->findOrFail($member);
        $data = $request->validate(['role' => ['required', Rule::in($this->access->keys(Account::installation()->id, true))]]);
        $this->guard($request, $item->role);
        $this->guard($request, $data['role']);
        $before = $item->role;
        $item->forceFill(['role' => $data['role']])->save();
        $this->extranet->forget();
        $this->recorder->record('extranet.member_role', $item, ['role' => $before], ['role' => $data['role']]);

        return back()->with('status', __('Rolle gespeichert.'));
    }

    public function destroy(Request $request, int $member): RedirectResponse
    {
        $item = ExtranetMember::query()->findOrFail($member);
        abort_if($item->user_id === (int) $request->user()?->getAuthIdentifier(), 422, __('Sich selbst entfernt man nicht aus dem Team.'));
        $this->guard($request, $item->role);
        $item->delete();
        $this->recorder->record('extranet.member_removed', null, ['user_id' => $item->user_id, 'role' => $item->role], null);

        return back()->with('status', __('Aus dem Team entfernt.'));
    }

    /** Keine Rechte verschenken: jede Stufe der Rolle muss die handelnde Person selbst haben. */
    private function guard(Request $request, string $role): void
    {
        $actor = $request->user();
        $own = $actor instanceof Authenticatable ? $this->extranet->definition($actor) : null;
        $wanted = $this->access->resolve($role, Account::installation()->id);
        $ok = $own !== null && $wanted !== null && ($own->isUnrestricted() || collect(array_keys(app(Areas::class)->all()))
            ->every(static fn (string $area): bool => $own->level($area)->includes($wanted->level($area))));
        abort_unless($ok, 403, __('Die Rolle geht über Ihre eigenen Rechte hinaus.'));
    }
}
