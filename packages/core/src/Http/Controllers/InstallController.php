<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Help\LegalTexts;
use Basalto\Core\Http\Middleware\PrepareInstallation;
use Basalto\Core\Install\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Installer im Browser (K-05, Mockup 19): Datenbank → Site → Konto → Fertig — dieselben Fragen wie `basalto:install`,
 * nur solange nichts eingerichtet ist ({@see PrepareInstallation}). Am Ende steht der
 * Einmal-Link zur ersten Anmeldung — ohne Passwort, ohne Mail-Server.
 */
final class InstallController
{
    public function __construct(private readonly Installer $installer) {}

    public function show(Request $request): Response
    {
        $token = $request->query('token');
        $query = is_string($token) && $token !== '' ? ['token' => $token] : [];

        return Inertia::render('core::Install', [
            'database' => $this->installer->database(),
            'defaults' => ['locale' => 'de', 'timezone' => 'Europe/Zurich'],
            'locales' => ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano'],
            'timezones' => ['Europe/Zurich', 'Europe/Berlin', 'Europe/Vienna', 'Europe/Paris', 'Europe/Rome', 'Europe/London', 'UTC'],
            'result' => $request->session()->get('basalto.install.result'),
            'urls' => ['migrate' => route('basalto.install.migrate', $query), 'store' => route('basalto.install.store', $query)],
            // TB 11 Scheibe 3 (G-07): Mit der Installation gilt der Lizenzvertrag — öffentlich lesbar, auch ohne Datenbank
            'license_url' => LegalTexts::publicUrl('lizenzvertrag'),
        ]);
    }

    public function migrate(Request $request): RedirectResponse
    {
        $state = $this->installer->database();
        abort_unless($state['ok'], 422, __('Keine Verbindung zur Datenbank.'));
        $this->installer->migrate();

        return redirect()->route('basalto.install', $request->only('token'))->with('status', __('Datenbank eingerichtet.'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'handle' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/'],
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', 'in:de,en,fr,it'],
            'timezone' => ['required', 'timezone:all'],
            'user' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
        ], [
            'handle.regex' => __('Kleinbuchstaben, Ziffern und Bindestriche, ohne Bindestrich am Anfang oder Ende.'),
        ], ['handle' => __('Handle'), 'name' => __('Name'), 'user' => __('Ihr Name'), 'email' => __('E-Mail')]);
        if ($this->installer->database()['pending'] > 0) {
            return back()->withErrors(['database' => __('Zuerst die Datenbank einrichten.')]);
        }
        $result = $this->installer->install($data['handle'], $data['name'], $data['locale'], $data['timezone'], $data['user'], $data['email']);
        // Ab jetzt gibt es den Installer nicht mehr — der Link kommt einmal über die Sitzung auf die Seite «Fertig»
        $request->session()->flash('basalto.install.result', ['url' => $result['url'], 'site' => $result['site']->name, 'email' => (string) $result['user']->getAttribute('email')]);

        return redirect()->route('basalto.install.done');
    }

    /** Seite «Fertig»: nur mit dem Ergebnis aus der Sitzung, danach 404. */
    public function done(Request $request): Response
    {
        $result = $request->session()->get('basalto.install.result');
        abort_unless(is_array($result), 404);

        return Inertia::render('core::Install', [
            'database' => null, 'defaults' => null, 'locales' => [], 'timezones' => [], 'result' => $result, 'urls' => null, 'license_url' => null,
        ]);
    }
}
