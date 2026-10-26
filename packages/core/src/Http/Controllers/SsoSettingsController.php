<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Models\SsoConnection;
use Basalto\Core\Policies\SitePolicy;
use Basalto\Core\Sites\SiteRole;
use Basalto\Core\Sso\ConnectionCheck;
use Basalto\Core\Sso\SsoProvider;
use Basalto\Core\Sso\SsoProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TB 13 Scheibe 4 (M-25): Verwaltung › Sites › Anmeldung über SSO — je Site Microsoft Entra ID und/oder Google.
 * Nur Betreiber der Site und Besitzer/Verwaltung ihres Kontos ({@see SitePolicy::manageSso()}).
 * Das Client-Secret geht nie zurück an den Browser: die Seite erfährt nur, ob eines gespeichert ist; leer gelassen
 * bleibt das gespeicherte. Das Protokoll hält fest, dass es geändert wurde, nie den Wert.
 */
final class SsoSettingsController
{
    /** Verzeichnis-ID der privaten Microsoft-Konten — kein Tenant einer Firma. */
    private const CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly Recorder $recorder) {}

    public function edit(Request $request, Site $site): Response
    {
        Gate::authorize('manageSso', $site);
        $connections = SsoConnection::query()->where('site_id', $site->id)->get()->keyBy('provider');
        $roots = $this->roots($request, $site);

        return Inertia::render('core::Sites/Sso', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'handle' => $site->handle],
            'providers' => array_map(function (SsoProvider $provider) use ($connections, $site, $roots): array {
                $connection = $connections->get($provider->value);

                return [
                    'key' => $provider->value,
                    'label' => $provider->label(),
                    'configured' => $connection !== null,
                    'enabled' => $connection !== null && $connection->enabled,
                    'tenant_id' => $connection?->tenant_id,
                    'client_id' => $connection?->client_id,
                    // Nur ob, nie was: das Secret bleibt auf dem Server
                    'has_secret' => $connection !== null && $connection->getRawOriginal('client_secret') !== null && $connection->getRawOriginal('client_secret') !== '',
                    'hosted_domain' => $connection?->hosted_domain,
                    'auto_create_role' => $connection?->auto_create_role,
                    'updated_at' => $connection?->updated_at?->timezone($site->timezone)->format('d.m.Y, H:i'),
                    'redirect_urls' => array_values(array_unique(array_map(static fn (string $root): string => SsoProviders::callbackUrl($provider, $root), $roots))),
                    'update_url' => route('basalto.sites.sso.update', ['site' => $site, 'provider' => $provider->value]),
                    'destroy_url' => route('basalto.sites.sso.destroy', ['site' => $site, 'provider' => $provider->value]),
                    'check_url' => route('basalto.sites.sso.check', ['site' => $site, 'provider' => $provider->value]),
                ];
            }, SsoProvider::cases()),
            'roles' => array_map(static fn (SiteRole $role): array => ['value' => $role->value, 'label' => $role->label()], SsoConnection::autoCreateRoles()),
            'login_url' => route('basalto.login', ['site' => $site->handle]),
            'back_url' => route('basalto.sites.edit', $site),
            'check' => session('sso_check'),
        ]);
    }

    public function update(Request $request, Site $site, string $provider): RedirectResponse
    {
        Gate::authorize('manageSso', $site);
        $sso = SsoProvider::from($provider);
        $connection = SsoConnection::query()->where('site_id', $site->id)->where('provider', $sso->value)->first();
        $validated = $request->validate($this->rules($sso, $connection), $this->messages(), [
            'tenant_id' => __('Tenant-ID'), 'client_id' => __('Client-ID'), 'client_secret' => __('Client-Secret'),
            'hosted_domain' => __('Erlaubte Domain'), 'auto_create_role' => __('Rolle für neue Benutzer'),
        ]);
        $connection ??= new SsoConnection(['site_id' => $site->id, 'provider' => $sso->value]);
        $before = $connection->exists ? $this->audit($connection) : null;
        $secret = is_string($validated['client_secret'] ?? null) && trim($validated['client_secret']) !== '' ? trim($validated['client_secret']) : null;
        $connection->fill([
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'tenant_id' => $sso === SsoProvider::Entra ? strtolower(trim((string) $validated['tenant_id'])) : null,
            'client_id' => trim((string) $validated['client_id']),
            'hosted_domain' => $sso === SsoProvider::Google && is_string($validated['hosted_domain'] ?? null) && trim($validated['hosted_domain']) !== '' ? strtolower(trim($validated['hosted_domain'])) : null,
            'auto_create_role' => is_string($validated['auto_create_role'] ?? null) && $validated['auto_create_role'] !== '' ? $validated['auto_create_role'] : null,
            'updated_by' => (int) $request->user()?->getAuthIdentifier() ?: null,
        ]);
        if ($secret !== null) {
            $connection->client_secret = $secret;
        }
        $connection->save();
        $this->recorder->record('sso.updated', $connection, $before, [...$this->audit($connection), 'secret_changed' => $secret !== null], $site);

        return back()->with('status', __(':provider für «:site» gespeichert.', ['provider' => $sso->label(), 'site' => $site->name]));
    }

    public function destroy(Site $site, string $provider): RedirectResponse
    {
        Gate::authorize('manageSso', $site);
        $sso = SsoProvider::from($provider);
        $connection = SsoConnection::query()->where('site_id', $site->id)->where('provider', $sso->value)->firstOrFail();
        $this->recorder->record('sso.removed', $connection, $this->audit($connection), null, $site);
        $connection->delete();

        return back()->with('status', __(':provider für «:site» entfernt — Anmeldung per Mail-Code und Passkey bleibt.', ['provider' => $sso->label(), 'site' => $site->name]));
    }

    /** «Verbindung testen» — nur ein Hinweis ({@see ConnectionCheck}); nichts wird gespeichert. */
    public function check(Site $site, string $provider, ConnectionCheck $check): RedirectResponse
    {
        Gate::authorize('manageSso', $site);
        $sso = SsoProvider::from($provider);
        $connection = SsoConnection::query()->where('site_id', $site->id)->where('provider', $sso->value)->firstOrFail();

        return back()->with('sso_check', ['provider' => $sso->value, ...$check->run($connection)]);
    }

    /** @return array<string, mixed> */
    private function rules(SsoProvider $sso, ?SsoConnection $connection): array
    {
        $roles = array_map(static fn (SiteRole $role): string => $role->value, SsoConnection::autoCreateRoles());
        $rules = [
            'enabled' => ['boolean'],
            'client_secret' => [$connection === null ? 'required' : 'nullable', 'string', 'max:500'],
            'auto_create_role' => ['nullable', Rule::in($roles)],
        ];

        return match ($sso) {
            SsoProvider::Entra => [...$rules,
                // Nur eine feste Verzeichnis-ID — «common», «organizations» oder ein Domainname liessen jeden Tenant zu
                'tenant_id' => ['required', 'string', 'regex:'.self::GUID, Rule::notIn([self::CONSUMER_TENANT])],
                'client_id' => ['required', 'string', 'regex:'.self::GUID],
            ],
            SsoProvider::Google => [...$rules,
                'client_id' => ['required', 'string', 'max:200', 'ends_with:.apps.googleusercontent.com'],
                'hosted_domain' => ['nullable', 'string', 'max:190', 'regex:/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
                // Ohne Domain bekäme jedes Google-Konto Zugang — automatische Anlage nur mit Domain
                'auto_create_role' => ['nullable', Rule::in($roles), 'prohibited_if:hosted_domain,null'],
            ],
        };
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'tenant_id.regex' => __('Die Tenant-ID ist eine GUID (Verzeichnis-ID), z. B. 72f988bf-86f1-41af-91ab-2d7cd011db47 — nicht «common» und kein Domainname.'),
            'tenant_id.not_in' => __('Das ist das Verzeichnis der privaten Microsoft-Konten. Tragen Sie die Verzeichnis-ID Ihrer Firma ein.'),
            'client_id.regex' => __('Die Client-ID ist eine GUID (Anwendungs-ID der App-Registrierung).'),
            'client_id.ends_with' => __('Die Client-ID von Google endet auf .apps.googleusercontent.com.'),
            'hosted_domain.regex' => __('Eine Domain ohne https:// und ohne @, z. B. firma.ch.'),
            'auto_create_role.prohibited_if' => __('Benutzer automatisch anlegen geht bei Google nur mit erlaubter Domain — sonst bekäme jedes Google-Konto Zugang.'),
            'client_secret.required' => __('Tragen Sie das Client-Secret ein.'),
        ];
    }

    /** @return array<string, mixed> Angaben fürs Protokoll — ohne Secret */
    private function audit(SsoConnection $connection): array
    {
        return array_filter(['provider' => $connection->provider, 'enabled' => $connection->enabled, 'tenant_id' => $connection->tenant_id, 'client_id' => $connection->client_id,
            'hosted_domain' => $connection->hosted_domain, 'auto_create_role' => $connection->auto_create_role], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Wurzeln, unter denen das Backend dieser Site erreichbar ist — für die Redirect-URLs zum Kopieren: der Host dieser
     * Anfrage, die Domains der Site (ohne reine Umleitungen) und `APP_URL`.
     *
     * @return list<string>
     */
    private function roots(Request $request, Site $site): array
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'https';
        $roots = [$request->getSchemeAndHttpHost()];
        foreach (SiteDomain::query()->where('site_id', $site->id)->where('kind', '!=', SiteDomain::KIND_REDIRECT)->orderByDesc('is_primary')->orderBy('host')->pluck('host') as $host) {
            $roots[] = $scheme.'://'.$host;
        }
        $roots[] = rtrim((string) config('app.url'), '/');

        return array_values(array_unique(array_filter($roots, static fn (string $root): bool => $root !== '')));
    }
}
