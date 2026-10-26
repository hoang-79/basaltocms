<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Core;
use Basalto\Core\Release\ReleaseBuilder;
use Basalto\Core\Release\ReleaseSignature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `basalto:release` (CR-004, P-05): baut das Release-Archiv für den Web-Installer — beim Hersteller, aus dem
 * Repository. Ergebnis: `basalto-<version>.zip` und das signierte Manifest `latest.json` im Release-Ordner
 * (`basalto.release.path`), von dort liefert die Hersteller-Site sie unter `/download/…` aus.
 */
final class ReleaseCommand extends Command
{
    protected $signature = 'basalto:release
        {--release-version= : Version (Vorgabe: Version des Kerns mit Datum und Zeit)}
        {--source= : Ordner des Repositorys (Vorgabe: diese Installation)}
        {--out= : Ziel-Ordner (Vorgabe: basalto.release.path)}
        {--key= : Privater Schlüssel als Base64 oder Pfad (Vorgabe: BASALTO_RELEASE_SECRET_KEY bzw. Marktplatz-Schlüssel)}
        {--vendor-from= : vorhandenes vendor übernehmen statt composer install --no-dev (Probe)}
        {--store : ohne Kompression packen (schneller, grösser)}
        {--notes= : Was ist neu? Text oder Pfad zu einer Datei — erscheint unter Einstellungen › Updates}';

    protected $description = 'Release-Archiv für den Web-Installer bauen und signieren';

    public function handle(ReleaseBuilder $builder): int
    {
        $version = (string) ($this->option('release-version') ?: Core::VERSION.'.'.now()->format('YmdHi'));
        $source = rtrim((string) ($this->option('source') ?: base_path()), '/');
        $out = (string) ($this->option('out') ?: config('basalto.release.path'));
        try {
            $secret = ReleaseSignature::secretKey((string) ($this->option('key') ?: config('basalto.release.secret_key')), (string) config('basalto.release.public_key'));
            $notes = $this->option('notes') ? (string) $this->option('notes') : null;
            $notes = $notes !== null && is_file($notes) ? (string) file_get_contents($notes) : $notes;
            $manifest = $builder->build($source, $version, $out, $secret, $this->option('vendor-from') ? (string) $this->option('vendor-from') : null, (bool) $this->option('store'),
                function (string $step): void {
                    $this->components->twoColumnDetail('Release', $step);
                }, $notes);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->components->info(sprintf('Release %s: %s (%.1f MB), SHA-256 %s…, signiert.', $manifest['version'], $manifest['file'], $manifest['size'] / 1048576, substr($manifest['sha256'], 0, 12)));

        return self::SUCCESS;
    }
}
