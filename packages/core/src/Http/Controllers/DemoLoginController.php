<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Demo\DemoMode;
use Basalto\Core\Demo\DemoTour;
use Basalto\Core\Events\UserLoggedIn;
use Basalto\Core\Support\Preferences;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Schnellzugang der Demo (TB 11 Scheibe 4, G-10): «Als … ansehen» meldet ohne Passwort und Code mit einem der drei
 * Demo-Konten an. Ohne `BASALTO_DEMO=true` gibt es die Adresse nicht (404) — auch nicht für bestehende Konten.
 */
final class DemoLoginController
{
    public function __invoke(Request $request, string $role): RedirectResponse
    {
        $demo = DemoMode::accounts()[$role] ?? null;
        abort_if(! DemoMode::enabled() || $demo === null, 404);
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = $userModel::query()->where('email', $demo['email'])->first();
        if (! $user instanceof Authenticatable) {
            return redirect()->route('basalto.login')->withErrors(['email' => __('Das Demo-Konto fehlt — die Demo wird gerade neu aufgesetzt. Bitte in einer Minute noch einmal.')]);
        }
        Auth::guard('web')->login($user);
        event(new UserLoggedIn($user, 'demo'));
        $request->session()->regenerate();

        // CR-004 (P-07): aus der Funktionsschau direkt an die Stelle im Backend — nur Pfade im Backend
        return redirect()->to(DemoTour::target($request->input('ziel')) ?? Preferences::startUrl($user));
    }
}
