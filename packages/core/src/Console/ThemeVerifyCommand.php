<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Theme\ThemePackage;
use Illuminate\Console\Command;
use RuntimeException;

/** Ein Theme-Paket prüfen (L-15): jede Datei gegen das Manifest, mit `--public-key` die Signatur. */
final class ThemeVerifyCommand extends Command
{
    protected $signature = 'basalto:theme-verify {path : Pfad zum ZIP} {--public-key= : öffentlicher Ed25519-Schlüssel (Base64 oder Datei)}';

    protected $description = 'Ein Theme-Paket gegen Manifest und Signatur prüfen';

    public function handle(ThemePackage $package): int
    {
        $key = $this->option('public-key');
        try {
            $result = $package->verify((string) $this->argument('path'), is_string($key) && $key !== '' ? $key : null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }
        if (! $result['ok']) {
            return self::FAILURE;
        }
        $this->info(sprintf('Paket in Ordnung: %s %s · %d Dateien%s', $result['manifest']['name'] ?? '', $result['manifest']['version'] ?? '', count($result['manifest']['files'] ?? []), $result['signed'] ? ' · signiert' : ''));

        return self::SUCCESS;
    }
}
