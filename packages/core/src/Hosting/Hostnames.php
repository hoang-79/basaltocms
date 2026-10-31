<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting;

use Basalto\Core\Events\HostnameReady;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Hosting\Acme\AcmeClient;
use Basalto\Core\Hosting\Acme\AcmeException;
use Basalto\Core\Hosting\Acme\ChallengeFiles;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Saas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * CR-017 (TB 87): Unterdomains der Zone (heute `*.basaltocms.com`), die Basalto beim Hoster selbst einrichtet — etwa das
 * Schaufenster eines Themes nach der Freigabe. Je Host, idempotent und in dieser Reihenfolge:
 *
 * 1. DNS: Der Host löst auf dieselbe Adresse auf wie die Zone (Platzhalter `*` im DNS des Hosters, einmalig).
 * 2. Panel: Unterdomain mit dem Dokumentstamm der Zone anlegen, falls es sie noch nicht gibt.
 * 3. Probe: Eine Datei unter `.well-known/acme-challenge/` kommt über http zurück — erst dann fragt Basalto bei Let's
 *    Encrypt an (gescheiterte Prüfungen zählen dort gegen ein Limit).
 * 4. Zertifikat: Fehlt eines oder läuft es in `renew_days` ab, ein neues holen und einspielen.
 * 5. https: `https://<host>/up` antwortet — dann ist der Host `ready`.
 *
 * Fehlt eine Voraussetzung (1, 3, 5), folgt der nächste Versuch nach zwei Minuten — so lange braucht LiteSpeed bei cyon
 * für eine neue Unterdomain oder ein neues Zertifikat —, sonst nach `retry_minutes`.
 */
final class Hostnames
{
    private const WAITING_RETRY_MINUTES = 2;

    public function __construct(private readonly Cpanel $panel, private readonly AcmeClient $acme, private readonly Dns $dns) {}

    public static function enabled(): bool
    {
        return config('basalto.hosting.panel') === 'cpanel';
    }

    public static function zone(): string
    {
        $zone = config('basalto.hosting.zone');

        return strtolower(is_string($zone) && $zone !== '' ? $zone : Saas::aliasDomain());
    }

    /**
     * Richtet Basalto diesen Host ein? Nur eine Ebene unter der Zone, nie die Zone selbst, ein reservierter Name
     * (`www`, `extranet`, `demo` …), die Adresse der Anwendung oder das Extranet.
     */
    public static function manages(string $host): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $host = strtolower($host);
        $suffix = '.'.self::zone();
        if (! str_ends_with($host, $suffix)) {
            return false;
        }
        $name = substr($host, 0, -strlen($suffix));
        if ($name === '' || str_contains($name, '.') || SiteDomain::isReservedAlias($name)) {
            return false;
        }

        return $host !== strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) && $host !== Extranet::host();
    }

    /** Einen Host zum Einrichten vormerken — nur, wenn Basalto ihn einrichtet und er noch keinen Stand hat. */
    public static function request(SiteDomain $domain): void
    {
        if ($domain->hosting_status === null && self::manages($domain->host)) {
            $domain->forceFill(['hosting_status' => SiteDomain::HOSTING_PENDING, 'hosting_error' => null, 'hosting_retry_at' => null])->save();
        }
    }

    /** Antwortet der Host schon über https — oder richtet Basalto ihn gar nicht ein (lokal, Download, eigene Domain)? */
    public static function live(string $host): bool
    {
        $status = SiteDomain::query()->withoutGlobalScopes()->where('host', strtolower($host))->value('hosting_status');

        return $status === null || $status === SiteDomain::HOSTING_READY;
    }

    /** @return Builder<SiteDomain> ausstehend, oder bereit mit bald ablaufendem Zertifikat — ohne Wartezeit nach einem Fehler, wenn `$now` */
    public static function due(bool $now = false): Builder
    {
        $renew = now()->addDays((int) config('basalto.hosting.acme.renew_days', 30));

        return SiteDomain::query()->withoutGlobalScopes()
            ->where(static fn (Builder $q) => $q->where('hosting_status', SiteDomain::HOSTING_PENDING)
                ->orWhere(static fn (Builder $q) => $q->where('hosting_status', SiteDomain::HOSTING_READY)
                    ->where(static fn (Builder $q) => $q->whereNull('certificate_expires_at')->orWhere('certificate_expires_at', '<=', $renew))))
            ->when(! $now, static fn (Builder $q) => $q->where(static fn (Builder $q) => $q->whereNull('hosting_retry_at')->orWhere('hosting_retry_at', '<=', now())));
    }

    /** @return list<array{host: string, ok: bool, waiting: bool, message: string}> */
    public function run(bool $now = false): array
    {
        return array_values(self::due($now)->orderBy('id')->get()->map(fn (SiteDomain $domain): array => $this->provision($domain))->all());
    }

    /** @return array{host: string, ok: bool, waiting: bool, message: string} `waiting`: nur eine Voraussetzung fehlt noch */
    public function provision(SiteDomain $domain): array
    {
        $host = strtolower($domain->host);
        try {
            if (! self::manages($host)) {
                throw new HostingException(sprintf('%s gehört nicht zu den Unterdomains, die Basalto in %s einrichtet.', $host, self::zone()));
            }
            $zone = self::zone();
            $this->pointsHere($host, $zone);
            $root = $this->panel->documentRoot($zone);
            if (! in_array($host, $this->panel->subdomains(), true)) {
                $this->panel->addSubdomain($host, $zone, substr($root['root'], strlen($root['home']) + 1));
            }
            $expires = $this->panel->certificateExpiry($host);
            if ($expires === null || $expires->lessThanOrEqualTo(now()->addDays((int) config('basalto.hosting.acme.renew_days', 30)))) {
                $files = new ChallengeFiles($root['root']);
                $this->servesFiles($host, $files);
                $certificate = $this->acme->issue($host, $files);
                $this->panel->installCertificate($host, $certificate);
                $expires = $certificate->expiresAt;
            }
            $this->answersOverHttps($host);
            $becomesReady = $domain->hosting_status !== SiteDomain::HOSTING_READY;
            $domain->forceFill(['hosting_status' => SiteDomain::HOSTING_READY, 'hosting_error' => null, 'hosting_checked_at' => now(), 'hosting_retry_at' => null,
                'certificate_expires_at' => $expires])->save();
            if ($becomesReady) {
                event(new HostnameReady($domain));
            }

            return ['host' => $host, 'ok' => true, 'waiting' => false, 'message' => sprintf('bereit, Zertifikat bis %s', $expires->format('d.m.Y'))];
        } catch (HostingException|AcmeException $e) {
            $waiting = $e instanceof HostingException && $e->waiting;
            if (! $waiting) {
                report($e);
            }
            // ein bereiter Host bleibt bereit, wenn die Erneuerung scheitert — sein Zertifikat gilt noch
            $domain->forceFill(['hosting_error' => Str::limit($e->getMessage(), 490), 'hosting_checked_at' => now(),
                'hosting_retry_at' => now()->addMinutes($waiting ? self::WAITING_RETRY_MINUTES : (int) config('basalto.hosting.retry_minutes', 30))])->save();

            return ['host' => $host, 'ok' => false, 'waiting' => $waiting, 'message' => $e->getMessage()];
        }
    }

    private function pointsHere(string $host, string $zone): void
    {
        $actual = $this->dns->addresses($host);
        if ($actual === []) {
            throw new HostingException(sprintf('Für %s gibt es keinen DNS-Eintrag — im DNS der Zone %s fehlt der Platzhalter «*».', $host, $zone), waiting: true);
        }
        $expected = $this->dns->addresses($zone);
        if (array_intersect($actual, $expected) === []) {
            throw new HostingException(sprintf('%s zeigt auf %s, die Zone %s auf %s.', $host, implode(', ', $actual), $zone, $expected === [] ? '—' : implode(', ', $expected)), waiting: true);
        }
    }

    /** Eine Probedatei muss über http zurückkommen, bevor Let's Encrypt prüft (Weiterleitungen wie Let's Encrypt). */
    private function servesFiles(string $host, ChallengeFiles $files): void
    {
        $token = 'basalto-probe-'.Str::random(16);
        $files->put($token, $token);
        try {
            $response = Http::timeout(15)->withoutVerifying()->get('http://'.$host.'/.well-known/acme-challenge/'.$token);
            $served = $response->successful() && trim($response->body()) === $token;
        } catch (ConnectionException) {
            $served = false;
        } finally {
            $files->remove($token);
        }
        if (! $served) {
            throw new HostingException(sprintf('%s liefert die Prüfdatei noch nicht aus — die Unterdomain ist beim Webserver noch nicht aktiv.', $host), waiting: true);
        }
    }

    private function answersOverHttps(string $host): void
    {
        try {
            $answers = Http::timeout(15)->get('https://'.$host.'/up')->successful();
        } catch (ConnectionException) {
            $answers = false;
        }
        if (! $answers) {
            throw new HostingException(sprintf('https://%s/up antwortet noch nicht — Zertifikat oder Unterdomain sind beim Webserver noch nicht aktiv.', $host), waiting: true);
        }
    }
}
