<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Social\SocialConnection;
use Basalto\Core\FrontendAccounts\Social\SocialIdentity;
use Basalto\Core\FrontendAccounts\Social\SocialProbe;
use Basalto\Core\FrontendAccounts\Social\SocialProvider;
use Basalto\Core\FrontendAccounts\Social\SocialProviders;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Policies\SitePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * TB 53 (CR-009 Nr. 2): Verwaltung › Sites › Anmeldung für Kundenkonten — je Site Google, Microsoft, GitHub und
 * LinkedIn für das Frontend. Nur Betreiber der Site und Besitzer/Verwaltung ihres Kontos ({@see SitePolicy::manageSso()}).
 * Das Client-Secret geht nie zurück an den Browser; leer gelassen bleibt das gespeicherte. «Ausprobieren» meldet die
 * Person einmal echt beim Anbieter an, ohne dass ein Kundenkonto entsteht ({@see SocialProbe}).
 */
final class SocialSettingsController
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly Recorder $recorder) {}

    public function edit(Request $request, Site $site): Response
    {
        Gate::authorize('manageSso', $site);
        $connections = SocialConnection::query()->where('site_id', $site->id)->get()->keyBy('provider');
        $linked = SocialIdentity::query()->where('site_id', $site->id)->selectRaw('provider, count(*) as total')->groupBy('provider')->pluck('total', 'provider');
        $roots = $this->roots($request, $site);
        $probe = SocialProbe::result($request->query('probe'), (int) $request->user()?->getAuthIdentifier());

        return Inertia::render('core::Sites/SocialLogin', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'handle' => $site->handle],
            'available' => CustomerAuth::available($site),
            'opens_registration' => CustomerAuth::available($site) && CustomerAuth::opensRegistration($site),
            'providers' => array_map(static function (SocialProvider $provider) use ($connections, $linked, $site, $roots): array {
                $connection = $connections->get($provider->value);

                return [
                    'key' => $provider->value,
                    'label' => $provider->label(),
                    'icon' => $provider->icon(),
                    'console' => $provider->console(),
                    'configured' => $connection !== null,
                    'enabled' => $connection !== null && $connection->enabled,
                    'client_id' => $connection?->client_id,
                    // Nur ob, nie was: das Secret bleibt auf dem Server
                    'has_secret' => $connection !== null && $connection->hasSecret(),
                    'tenant' => $connection === null ? SocialConnection::TENANT_ALL : $connection->microsoftTenant(),
                    'linked' => (int) ($linked[$provider->value] ?? 0),
                    'updated_at' => $connection?->updated_at?->timezone($site->timezone)->format('d.m.Y, H:i'),
                    'redirect_urls' => array_values(array_unique(array_map(static fn (string $root): string => SocialProviders::callbackUrl($provider, $root), $roots))),
                    'update_url' => route('basalto.sites.social.update', ['site' => $site, 'provider' => $provider->value]),
                    'destroy_url' => route('basalto.sites.social.destroy', ['site' => $site, 'provider' => $provider->value]),
                    'probe_url' => route('basalto.sites.social.probe', ['site' => $site, 'provider' => $provider->value]),
                ];
            }, SocialProvider::cases()),
            'login_url' => ($roots[0] ?? '').'/account/login',
            'back_url' => route('basalto.sites.edit', $site),
            'probe' => $probe,
        ]);
    }

    public function update(Request $request, Site $site, string $provider): RedirectResponse
    {
        Gate::authorize('manageSso', $site);
        $social = SocialProvider::from($provider);
        $connection = SocialConnection::query()->where('site_id', $site->id)->where('provider', $social->value)->first();
        $validated = $request->validate($this->rules($social, $connection), $this->messages(), [
            'client_id' => __('Client-ID'), 'client_secret' => __('Client-Secret'), 'tenant' => __('Konten'),
        ]);
        $connection ??= new SocialConnection(['site_id' => $site->id, 'provider' => $social->value]);
        $before = $connection->exists ? $this->audit($connection) : null;
        $secret = is_string($validated['client_secret'] ?? null) && trim($validated['client_secret']) !== '' ? trim($validated['client_secret']) : null;
        $connection->fill([
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'client_id' => trim((string) $validated['client_id']),
            'tenant' => $social === SocialProvider::Microsoft ? (string) ($validated['tenant'] ?? SocialConnection::TENANT_ALL) : null,
            'updated_by' => (int) $request->user()?->getAuthIdentifier() ?: null,
        ]);
        if ($secret !== null) {
            $connection->client_secret = $secret;
        }
        $connection->save();
        $this->recorder->record('social.updated', $connection, $before, [...$this->audit($connection), 'secret_changed' => $secret !== null], $site);
        // Die Knöpfe stehen in Seiten aus dem Seiten-Cache
        app(PageCache::class)->invalidate($site->id);

        return back()->with('status', __(':provider für «:site» gespeichert.', ['provider' => $social->label(), 'site' => $site->name]));
    }

    public function destroy(Site $site, string $provider): RedirectResponse
    {
        Gate::authorize('manageSso', $site);
        $social = SocialProvider::from($provider);
        $connection = SocialConnection::query()->where('site_id', $site->id)->where('provider', $social->value)->firstOrFail();
        $this->recorder->record('social.removed', $connection, $this->audit($connection), null, $site);
        $connection->delete();
        app(PageCache::class)->invalidate($site->id);

        return back()->with('status', __(':provider für «:site» entfernt — der Code per Mail bleibt. Verknüpfte Konten bleiben gespeichert und gelten wieder, wenn Sie :provider neu einrichten.', ['provider' => $social->label(), 'site' => $site->name]));
    }

    /** «Ausprobieren»: weiter zum Anbieter über die Adresse der Site — zurück kommt das Ergebnis, kein Kundenkonto. */
    public function probe(Request $request, Site $site, string $provider): SymfonyResponse
    {
        Gate::authorize('manageSso', $site);
        $social = SocialProvider::from($provider);
        $connection = SocialConnection::query()->where('site_id', $site->id)->where('provider', $social->value)->firstOrFail();
        abort_unless(CustomerAuth::available($site) && $connection->hasSecret(), 409);
        $token = SocialProbe::create($site, $social, (int) $request->user()?->getAuthIdentifier(), route('basalto.sites.social', $site));
        $root = $this->roots($request, $site)[0] ?? $request->getSchemeAndHttpHost();

        // Inertia folgt fremden Adressen nur über `Inertia::location` (409 mit Ziel), nie über eine Weiterleitung
        return Inertia::location($root.route('basalto.account.social.redirect', ['provider' => $social->value, 'probe' => $token], false));
    }

    /** @return array<string, mixed> */
    private function rules(SocialProvider $social, ?SocialConnection $connection): array
    {
        $rules = [
            'enabled' => ['boolean'],
            'client_secret' => [$connection === null || ! $connection->hasSecret() ? 'required' : 'nullable', 'string', 'max:500'],
        ];

        return match ($social) {
            SocialProvider::Google => [...$rules, 'client_id' => ['required', 'string', 'max:200', 'ends_with:.apps.googleusercontent.com']],
            SocialProvider::Microsoft => [...$rules, 'client_id' => ['required', 'string', 'regex:'.self::GUID],
                'tenant' => ['required', Rule::in([SocialConnection::TENANT_ALL, SocialConnection::TENANT_PERSONAL])]],
            SocialProvider::GitHub => [...$rules, 'client_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/']],
            SocialProvider::LinkedIn => [...$rules, 'client_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9]+$/']],
        };
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'client_id.ends_with' => __('Die Client-ID von Google endet auf .apps.googleusercontent.com.'),
            'client_id.regex' => __('Diese Client-ID hat nicht die Form, die der Anbieter vergibt. Kopieren Sie sie aus der Konsole des Anbieters.'),
            'client_secret.required' => __('Tragen Sie das Client-Secret ein.'),
        ];
    }

    /** @return array<string, mixed> Angaben fürs Protokoll — ohne Secret */
    private function audit(SocialConnection $connection): array
    {
        return array_filter(['provider' => $connection->provider, 'enabled' => $connection->enabled, 'client_id' => $connection->client_id, 'tenant' => $connection->tenant],
            static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Adressen, unter denen das Frontend dieser Site erreichbar ist — für die Redirect-URLs zum Kopieren: die Domains
     * der Site (Hauptadresse zuerst, ohne reine Umleitungen) und der Host dieser Anfrage.
     *
     * @return list<string>
     */
    private function roots(Request $request, Site $site): array
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'https';
        $roots = [];
        foreach (SiteDomain::query()->where('site_id', $site->id)->where('kind', '!=', SiteDomain::KIND_REDIRECT)->orderByDesc('is_primary')->orderBy('host')->pluck('host') as $host) {
            $roots[] = $scheme.'://'.$host;
        }
        $roots[] = $request->getSchemeAndHttpHost();

        return array_values(array_unique(array_filter($roots, static fn (string $root): bool => $root !== '')));
    }
}
