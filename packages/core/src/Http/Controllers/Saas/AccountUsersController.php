<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Account;
use Basalto\Core\Saas\AccountContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Konto › Benutzer (TB 12 Scheibe 1, Mockup 23): die Konto-Rollen Besitzer, Verwaltung und Abrechnung — getrennt von
 * den Site-Rollen (Verwaltung › Benutzer). Eine Konto-Rolle bekommt, wer schon Mitglied einer Site des Kontos ist (neue
 * Personen lädt man über die Site ein). Besitzer und Verwaltung ändern Rollen; Besitzer vergibt und entzieht nur ein
 * Besitzer, und der letzte Besitzer bleibt.
 */
final class AccountUsersController
{
    public function __construct(private readonly Recorder $recorder) {}

    public function index(Request $request): Response
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        $members = $account->users()->orderBy('name')->get();
        $memberIds = $members->map(static fn (Model $m): int => (int) $m->getKey())->all();
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $candidates = $userModel::query()->whereIn('id', DB::table('site_user')->whereIn('site_id', $account->sites()->pluck('id'))->select('user_id'))
            ->whereNotIn('id', $memberIds)->orderBy('name')->get(['id', 'name', 'email']);

        $owners = $members->filter(static fn (Model $m): bool => $m->getRelationValue('pivot')?->getAttribute('role') === AccountRole::Owner->value)->count();

        return AccountPage::render($request, 'Users', 'users', [
            'users' => $members->map(static function (Model $member) use ($user, $owners): array {
                $pivot = $member->getRelationValue('pivot');
                $role = AccountRole::tryFrom((string) ($pivot?->getAttribute('role') ?? ''));

                return ['id' => (int) $member->getKey(), 'name' => (string) $member->getAttribute('name'), 'email' => (string) $member->getAttribute('email'),
                    'role' => $role?->value, 'role_label' => $role?->label(), 'self' => $user !== null && $member->getKey() === $user->getAuthIdentifier(),
                    'deactivated' => $member->getAttribute('deactivated_at') !== null, 'url' => route('basalto.saas.account.users.update', (int) $member->getKey()),
                    // Der letzte Besitzer bleibt — ohne Knopf «Entfernen» und ohne andere Rolle
                    'last_owner' => $role === AccountRole::Owner && $owners <= 1];
            })->values()->all(),
            'candidates' => $candidates->map(static fn (Model $c): array => ['id' => (int) $c->getKey(), 'name' => (string) $c->getAttribute('name'), 'email' => (string) $c->getAttribute('email')])->values()->all(),
            'roles' => array_map(static fn (AccountRole $r): array => ['value' => $r->value, 'label' => $r->label()], AccountRole::cases()),
            'can_owner' => $user !== null && $account->roleOf($user) === AccountRole::Owner,
            'urls' => ['store' => route('basalto.saas.account.users.store'), 'users' => route('basalto.users.index')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->managed($request);
        $data = $request->validate(['user' => ['required', 'integer'], 'role' => ['required', Rule::enum(AccountRole::class)]]);
        $isSiteMember = DB::table('site_user')->whereIn('site_id', $account->sites()->pluck('id'))->where('user_id', (int) $data['user'])->exists();
        abort_unless($isSiteMember, 404);
        $role = AccountRole::from((string) $data['role']);
        $this->guardOwner($request, $account, $role);
        $account->users()->syncWithoutDetaching([(int) $data['user'] => ['role' => $role->value]]);
        $this->recorder->record('account.role', $account, null, ['user' => (int) $data['user'], 'role' => $role->value]);

        return back()->with('status', __('Konto-Rolle vergeben.'));
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $account = $this->managed($request);
        $current = $this->roleOf($account, $user);
        $data = $request->validate(['role' => ['required', Rule::enum(AccountRole::class)]]);
        $role = AccountRole::from((string) $data['role']);
        $this->guardOwner($request, $account, $current === AccountRole::Owner ? AccountRole::Owner : $role);
        if ($current === AccountRole::Owner && $role !== AccountRole::Owner) {
            $this->keepLastOwner($account);
        }
        $account->users()->updateExistingPivot($user, ['role' => $role->value]);
        $this->recorder->record('account.role', $account, ['user' => $user, 'role' => $current->value], ['user' => $user, 'role' => $role->value]);

        return back()->with('status', __('Konto-Rolle geändert.'));
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        $account = $this->managed($request);
        $current = $this->roleOf($account, $user);
        $this->guardOwner($request, $account, $current);
        if ($current === AccountRole::Owner) {
            $this->keepLastOwner($account);
        }
        $account->users()->detach($user);
        $this->recorder->record('account.role', $account, ['user' => $user, 'role' => $current->value], ['user' => $user, 'role' => null]);

        return back()->with('status', __('Konto-Rolle entfernt — die Rollen auf den Sites bleiben.'));
    }

    private function managed(Request $request): Account
    {
        $account = AccountContext::from($request);
        $user = $request->user();
        abort_unless($user !== null && AccountContext::manages($user, $account), 403);

        return $account;
    }

    /** Die Konto-Rolle eines Benutzers dieses Kontos — wer keine hat, ist hier 404. */
    private function roleOf(Account $account, int $userId): AccountRole
    {
        $role = DB::table('account_user')->where('account_id', $account->id)->where('user_id', $userId)->value('role');
        $parsed = is_string($role) ? AccountRole::tryFrom($role) : null;
        abort_if($parsed === null, 404);

        return $parsed;
    }

    private function guardOwner(Request $request, Account $account, AccountRole $role): void
    {
        $user = $request->user();
        if ($role === AccountRole::Owner && (! $user instanceof Authenticatable || $account->roleOf($user) !== AccountRole::Owner)) {
            throw ValidationException::withMessages(['role' => __('Nur ein Besitzer vergibt oder entzieht die Rolle Besitzer.')]);
        }
    }

    private function keepLastOwner(Account $account): void
    {
        if (DB::table('account_user')->where('account_id', $account->id)->where('role', AccountRole::Owner->value)->count() <= 1) {
            throw ValidationException::withMessages(['role' => __('Das Konto braucht mindestens einen Besitzer.')]);
        }
    }
}
