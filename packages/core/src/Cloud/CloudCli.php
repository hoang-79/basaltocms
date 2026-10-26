<?php

declare(strict_types=1);

namespace Basalto\Core\Cloud;

/**
 * Die Laravel-Cloud-CLI aus der Anwendung heraus (D-06: Kundendomains nur automatisch).
 * Jeder Aufruf: `cloud <befehl> <umgebung> … --json -n` mit dem Token aus der Konfiguration; die Antwort ist JSON.
 */
final class CloudCli
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $binary,
        private readonly ?string $environment,
        private readonly ?string $token,
    ) {}

    public static function fromConfig(CommandRunner $runner): self
    {
        $binary = (string) config('basalto.cloud.binary', 'vendor/bin/cloud');

        return new self($runner, str_starts_with($binary, '/') ? $binary : base_path($binary),
            config('basalto.cloud.environment'), config('basalto.cloud.token'));
    }

    /** @return array<string, mixed> */
    public function domainCreate(string $host, bool $wildcard = false): array
    {
        return $this->json(['domain:create', $this->environment(), '--name='.$host, '--wildcard-enabled='.($wildcard ? 'true' : 'false')]);
    }

    /** @return array<string, mixed> */
    public function domainVerify(string $domainId): array
    {
        return $this->json(['domain:verify', $domainId]);
    }

    /** @return array<string, mixed> */
    public function domainList(): array
    {
        return $this->json(['domain:list', $this->environment()]);
    }

    /**
     * TB 12 Scheibe 3: eine Domain mit ihren DNS-Einträgen (`domain:get`), falls `domain:create` sie nicht mitliefert.
     *
     * @return array<string, mixed>
     */
    public function domainGet(string $domainId): array
    {
        return $this->json(['domain:get', $domainId]);
    }

    /** TB 12 Scheibe 3: eine Kundendomain in der Cloud löschen — wie jede Löschung der CLI mit `-n --force`, ohne JSON. */
    public function domainDelete(string $domainId): void
    {
        $this->run(['domain:delete', $domainId, '-n', '--force']);
    }

    private function environment(): string
    {
        return $this->environment ?? throw new CloudCliException('basalto.cloud.environment ist nicht gesetzt (Cloud-Umgebung für Kundendomains)');
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function json(array $arguments): array
    {
        $output = $this->run([...$arguments, '--json', '-n']);
        $start = strpos($output, '{');
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);
        if (! is_array($decoded)) {
            throw new CloudCliException(sprintf('cloud %s lieferte kein JSON: %s', $arguments[0], trim($output)));
        }

        return $decoded;
    }

    /**
     * Ein Aufruf mit dem Token aus der Konfiguration; ein Exit ungleich 0 ist ein Fehler mit der Ausgabe der CLI.
     *
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): string
    {
        $environment = $this->token !== null ? ['LARAVEL_CLOUD_TOKEN' => $this->token] : [];
        $result = $this->runner->run([$this->binary, ...$arguments], $environment);
        if ($result['exit'] !== 0) {
            throw new CloudCliException(sprintf('cloud %s scheiterte (Exit %d): %s', $arguments[0], $result['exit'], trim($result['output'])));
        }

        return $result['output'];
    }
}
