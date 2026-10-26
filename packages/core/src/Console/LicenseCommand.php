<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\License\LicenseIssuer;
use Basalto\Core\License\LicenseKey;
use Basalto\Core\License\LicenseRevocations;
use Basalto\Core\License\LicenseStatus;
use Basalto\Core\License\LicenseVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * «basalto:license» zeigt den Lizenzstand (offline) und hält Verstösse in `license_state` fest (stündlich im Scheduler); «--check=<schlüssel>» prüft einen Schlüssel vor dem Eintragen;
 * «--keypair» erzeugt das Schlüsselpaar des Produkts; «--issue» stellt mit dem privaten Schlüssel einen Lizenzschlüssel aus (basaltocms.com).
 */
final class LicenseCommand extends Command
{
    protected $signature = 'basalto:license
        {--check= : Einen Schlüssel prüfen, ohne ihn einzutragen}
        {--keypair : Ed25519-Schlüsselpaar erzeugen (öffentlich in die Konfiguration, privat in die Secrets von basaltocms.com)}
        {--issue : Schlüssel ausstellen (braucht BASALTO_LICENSE_SECRET_KEY)}
        {--plan=site : Stufe: site oder agency}
        {--licensee= : Lizenznehmer}
        {--domains= : Domains, durch Komma getrennt}
        {--seats= : Benutzergrenze (leer = unbegrenzt)}
        {--sites= : Site-Grenze (leer = Vorgabe der Stufe)}
        {--updates-until= : Update-Anspruch bis (JJJJ-MM-TT), Vorgabe ein Jahr}
        {--agency= : Agentur (Unterlizenz für einen Kunden der Agentur)}
        {--customer= : Kunde der Agentur}
        {--white-label : Marke Basalto im Backend zugunsten der Agentur ausblenden}
        {--secret-file= : Datei mit dem privaten Schlüssel (Base64), Vorgabe ~/.ssh/basalto/license-secret.key}
        {--revoke= : Schlüssel-ID in die Sperrliste des Kerns eintragen}';

    protected $description = 'Lizenzstand anzeigen, Schlüssel prüfen oder ausstellen (offline, Ed25519)';

    public function handle(): int
    {
        if ($this->option('keypair')) {
            $pair = LicenseIssuer::keypair();
            $this->components->info('Schlüsselpaar erzeugt — privat nie ins Repo, nur in die Secrets von basaltocms.com.');
            $this->components->twoColumnDetail('BASALTO_LICENSE_PUBLIC_KEY', $pair['public']);
            $this->components->twoColumnDetail('BASALTO_LICENSE_SECRET_KEY', $pair['secret']);

            return self::SUCCESS;
        }
        if ($this->option('issue')) {
            return $this->issue();
        }
        $revoke = $this->option('revoke');
        if (is_string($revoke) && $revoke !== '') {
            $added = LicenseRevocations::default()->add($revoke);
            $this->components->info($added ? sprintf('Schlüssel %s gesperrt — die Sperrliste geht mit dem nächsten Release hinaus.', $revoke) : sprintf('Schlüssel %s war schon gesperrt.', $revoke));

            return self::SUCCESS;
        }
        $verifier = LicenseVerifier::fromBase64(config('basalto.license.public_key'));
        if (! $verifier->configured()) {
            $this->components->warn('Kein öffentlicher Lizenzschlüssel konfiguriert (basalto.license.public_key) — Schlüssel können nicht geprüft werden.');
        }
        $check = $this->option('check');
        if (is_string($check) && $check !== '') {
            $key = $verifier->verify($check);
            if ($key === null) {
                $this->components->error('Schlüssel ungültig: Format oder Signatur passen nicht zu diesem Produkt.');

                return self::FAILURE;
            }
            $this->components->info('Schlüssel gültig.');
            $this->show($key);

            return self::SUCCESS;
        }
        $status = LicenseStatus::current();
        if (LicenseStatus::keySource() !== null) {
            $this->components->twoColumnDetail('Schlüssel aus', LicenseStatus::keySource() === 'backend' ? 'Backend (Verwaltung › Lizenz)' : 'BASALTO_LICENSE_KEY');
        }
        $this->components->twoColumnDetail('Stufe', $status->key?->planLabel() ?? 'Free');
        $this->components->twoColumnDetail('Stand', $status->standLabel());
        $this->components->twoColumnDetail('Backend-Benutzer / Sites', sprintf('%d / %d', $status->users, $status->sites));
        if ($status->key !== null) {
            $this->show($status->key);
        }
        foreach ($status->hints as $hint) {
            $this->components->warn($hint);
        }
        if ($status->bannerFrom() !== null) {
            $this->components->warn(sprintf($status->bannerActive() ? 'Das Frontend zeigt seit %s den Hinweis «unregistriert».' : 'Ohne gültigen Schlüssel zeigt das Frontend ab %s einen Hinweis.', $status->bannerFrom()->format('d.m.Y')));
        }

        return self::SUCCESS;
    }

    private function issue(): int
    {
        $secret = config('basalto.license.secret_key');
        $file = $this->option('secret-file') ?: (getenv('HOME') ?: '').'/.ssh/basalto/license-secret.key';
        if ((! is_string($secret) || $secret === '') && is_file($file)) {
            $secret = trim((string) file_get_contents($file));
        }
        if (! is_string($secret) || $secret === '') {
            $this->components->error('Privater Schlüssel fehlt (BASALTO_LICENSE_SECRET_KEY oder --secret-file) — Ausstellen nur beim Hersteller.');

            return self::FAILURE;
        }
        $plan = (string) $this->option('plan');
        if (! in_array($plan, [LicenseKey::PLAN_SITE, LicenseKey::PLAN_AGENCY], true)) {
            $this->components->error('Stufe muss site oder agency sein.');

            return self::FAILURE;
        }
        $domains = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('domains')))));
        $until = $this->option('updates-until');
        $key = new LicenseKey(
            id: (string) Str::ulid(),
            plan: $plan,
            licensee: (string) ($this->option('licensee') ?: 'Unbekannt'),
            domains: $domains,
            seats: is_numeric($this->option('seats')) ? (int) $this->option('seats') : null,
            sites: is_numeric($this->option('sites')) ? (int) $this->option('sites') : null,
            issuedAt: CarbonImmutable::today(),
            updatesUntil: is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : CarbonImmutable::today()->addYear(),
            agency: is_string($this->option('agency')) && $this->option('agency') !== '' ? $this->option('agency') : null,
            customer: is_string($this->option('customer')) && $this->option('customer') !== '' ? $this->option('customer') : null,
            whiteLabel: (bool) $this->option('white-label'),
        );
        $this->line(LicenseIssuer::fromBase64($secret)->issue($key));

        return self::SUCCESS;
    }

    private function show(LicenseKey $key): void
    {
        $this->components->twoColumnDetail('Lizenznehmer', $key->licensee);
        $this->components->twoColumnDetail('Stufe', $key->planLabel());
        $this->components->twoColumnDetail('Domains', $key->domains === [] ? '—' : implode(', ', $key->domains));
        $this->components->twoColumnDetail('Benutzer / Sites', sprintf('%s / %s', $key->seats ?? 'unbegrenzt', $key->sites ?? 'Vorgabe der Stufe'));
        $this->components->twoColumnDetail('Updates bis', $key->updatesUntil?->format('d.m.Y') ?? 'unbegrenzt');
        if ($key->agency !== null) {
            $this->components->twoColumnDetail('Agentur', $key->agency.($key->customer !== null ? ' · Kunde '.$key->customer : '').($key->whiteLabel ? ' · White-Label' : ''));
        }
        $this->components->twoColumnDetail('Schlüssel-ID', $key->id);
    }
}
