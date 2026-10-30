<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting;

use Basalto\Core\Cloud\CommandRunner;
use Basalto\Core\Hosting\Acme\Certificate;
use Carbon\CarbonImmutable;

/**
 * CR-017: das Hosting-Panel über die Kommandozeile `uapi` (cPanel, etwa bei cyon) — Unterdomains anlegen, Zertifikate
 * lesen und einspielen. `uapi` dekodiert jeden Wert wie eine URL, deshalb gehen alle Werte kodiert hinaus (sonst
 * zerbräche ein Zertifikat an seinen Zeilenumbrüchen). Öffentliches DNS gehört nicht dazu: Die Zone im Panel erreicht
 * bei cyon die Nameserver nicht — dafür steht der Platzhalter `*` im DNS-Editor von my.cyon.
 */
final class Cpanel
{
    public function __construct(private readonly CommandRunner $runner, private readonly string $binary = 'uapi') {}

    public static function fromConfig(CommandRunner $runner): self
    {
        $binary = config('basalto.hosting.uapi');

        return new self($runner, is_string($binary) && $binary !== '' ? $binary : 'uapi');
    }

    /** @return list<string> alle Unterdomains des Kontos */
    public function subdomains(): array
    {
        $data = (array) $this->call('DomainInfo', 'list_domains');

        return array_values(array_map(strtolower(...), array_filter((array) ($data['sub_domains'] ?? []), 'is_string')));
    }

    /** @return array{root: string, home: string} Dokumentstamm und Home des Kontos für eine Domain */
    public function documentRoot(string $domain): array
    {
        $data = (array) $this->call('DomainInfo', 'single_domain_data', ['domain' => $domain]);
        $root = (string) ($data['documentroot'] ?? '');
        $home = (string) ($data['homedir'] ?? '');
        if ($root === '' || $home === '' || ! str_starts_with($root, rtrim($home, '/').'/')) {
            throw new HostingException(sprintf('Der Dokumentstamm von %s ist im Panel nicht lesbar.', $domain));
        }

        return ['root' => $root, 'home' => rtrim($home, '/')];
    }

    /** Unterdomain `<name>.<zone>` mit dem Dokumentstamm `$directory` (relativ zum Home) anlegen. */
    public function addSubdomain(string $host, string $zone, string $directory): void
    {
        $name = substr($host, 0, -strlen('.'.$zone));
        $this->call('SubDomain', 'addsubdomain', ['domain' => $name, 'rootdomain' => $zone, 'dir' => $directory, 'disallowdot' => '0']);
    }

    /** Ablauf des Zertifikats, das der Webserver für diesen Host ausliefert — null, wenn keines eingespielt ist. */
    public function certificateExpiry(string $host): ?CarbonImmutable
    {
        foreach ((array) $this->call('SSL', 'installed_hosts') as $installed) {
            if (! is_array($installed)) {
                continue;
            }
            $certificate = (array) ($installed['certificate'] ?? []);
            $names = array_map(strtolower(...), array_filter((array) ($certificate['domains'] ?? []), 'is_string'));
            if ((strtolower((string) ($installed['servername'] ?? '')) === $host || in_array($host, $names, true)) && is_numeric($certificate['not_after'] ?? null)) {
                return CarbonImmutable::createFromTimestamp((int) $certificate['not_after']);
            }
        }

        return null;
    }

    public function installCertificate(string $host, Certificate $certificate): void
    {
        $this->call('SSL', 'install_ssl', ['domain' => $host, 'cert' => $certificate->certificate, 'key' => $certificate->privateKey, 'cabundle' => $certificate->chain]);
    }

    /** @param  array<string, string>  $arguments */
    private function call(string $module, string $function, array $arguments = []): mixed
    {
        $command = [$this->binary, '--output=json', $module, $function];
        foreach ($arguments as $name => $value) {
            $command[] = $name.'='.rawurlencode($value);
        }
        $result = $this->runner->run($command);
        // Warnungen von uapi stehen vor dem JSON in derselben Ausgabe
        $start = strpos($result['output'], '{');
        $json = $start === false ? null : json_decode(substr($result['output'], $start), true);
        $outcome = is_array($json) ? (array) ($json['result'] ?? []) : [];
        if ($outcome === [] || (int) ($outcome['status'] ?? 0) !== 1) {
            $errors = implode(' ', array_filter((array) ($outcome['errors'] ?? []), 'is_string'));
            throw new HostingException(sprintf('Panel (%s %s): %s', $module, $function, trim($errors) !== '' ? trim($errors) : 'keine lesbare Antwort'));
        }

        return $outcome['data'] ?? null;
    }
}
