<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Events\UserLoggedIn;
use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\Invitation;
use Basalto\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einladung annehmen (K-03): der Link zeigt Site, Rolle und Einladende; wer annimmt, bekommt ein Konto (oder das
 * bestehende zur Mail) mit der Rolle auf der Site und ist angemeldet — ohne Passwort, der Passkey folgt im Cockpit.
 */
final class InvitationController
{
    public function __construct(private readonly Recorder $recorder) {}

    public function show(string $token): Response
    {
        [$invitation, $error] = $this->resolve($token);
        $site = $invitation === null ? null : Site::query()->find($invitation->site_id);
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $inviter = $invitation?->invited_by === null ? null : $userModel::query()->whereKey($invitation->invited_by)->value('name');

        return Inertia::render('core::Invitation', [
            'invitation' => $invitation === null || $site === null ? null : ['email' => $invitation->email, 'site_name' => $site->name, 'role_label' => app(Access::class)->name($invitation->role, $site->account_id),
                'expires_at' => $invitation->expires_at->timezone($site->timezone)->format('d.m.Y, H:i'), 'invited_by' => is_string($inviter) ? $inviter : null],
            'error' => $error,
            'accept_url' => $invitation === null ? null : route('basalto.invitation.accept', $token),
            'login_url' => route('basalto.login'),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        [$invitation, $error] = $this->resolve($token);
        if ($invitation === null) {
            return redirect()->route('basalto.invitation.show', $token)->withErrors(['first_name' => $error ?? __('Diese Einladung gilt nicht mehr.')]);
        }
        $validated = $request->validate(['first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80']], [], ['first_name' => __('Vorname'), 'last_name' => __('Nachname')]);
        $validated['name'] = trim($validated['first_name'].' '.$validated['last_name']);
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = DB::transaction(function () use ($invitation, $validated, $userModel): Model {
            $user = $userModel::query()->where('email', $invitation->email)->first();
            if ($user === null) {
                $user = new $userModel;
                $user->forceFill(['name' => $validated['name'], 'first_name' => trim($validated['first_name']), 'last_name' => trim($validated['last_name']), 'email' => $invitation->email, 'password' => Hash::make(Str::random(64)), 'email_verified_at' => now()])->save();
            }
            $site = Site::query()->findOrFail($invitation->site_id);
            $site->users()->syncWithoutDetaching([$user->getKey() => ['role' => $invitation->role]]);
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
        if (! $user instanceof Authenticatable) {
            abort(500);
        }
        if ($user->getAttribute('deactivated_at') !== null) {
            return redirect()->route('basalto.login')->withErrors(['email' => __('Dieses Konto ist deaktiviert. Wenden Sie sich an die Verwaltung der Site.')]);
        }
        $site = Site::query()->findOrFail($invitation->site_id);
        $this->recorder->record('user.invitation_accepted', $user, null, ['email' => $invitation->email, 'role' => $invitation->role], $site, $user);
        Auth::guard('web')->login($user, remember: true);
        event(new UserLoggedIn($user, 'invitation'));
        $request->session()->regenerate();
        $request->session()->put(SelectBackendSite::SESSION_KEY, $site->id);

        return redirect()->route('basalto.dashboard')->with('status', __('Willkommen bei :site. Richten Sie als Nächstes einen Passkey ein.', ['site' => $site->name]));
    }

    /** @return array{0: Invitation|null, 1: string|null} */
    private function resolve(string $token): array
    {
        $invitation = Invitation::findByToken($token);
        if ($invitation === null) {
            return [null, __('Dieser Einladungslink ist ungültig. Bitten Sie um eine neue Einladung.')];
        }
        if ($invitation->accepted_at !== null) {
            return [null, __('Diese Einladung wurde schon angenommen. Melden Sie sich an.')];
        }
        if ($invitation->isExpired()) {
            return [null, __('Diese Einladung ist abgelaufen. Bitten Sie um eine neue — sie lässt sich mit einem Klick erneut senden.')];
        }

        return [$invitation, null];
    }
}
