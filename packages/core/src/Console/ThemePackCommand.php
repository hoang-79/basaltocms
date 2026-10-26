<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Theme\ThemePackage;
use Illuminate\Console\Command;
use RuntimeException;

/** Ein Theme als Paket schnüren (L-15): ZIP mit Manifest und Prüfsummen, mit `--key` Ed25519-signiert. */
final class ThemePackCommand extends Command
{
    protected $signature = 'basalto:theme-pack {name : Ordner unter themes/} {--output= : Zielordner, sonst storage/app/themes} {--key= : geheimer Ed25519-Schlüssel (Base64 oder Datei)}';

    protected $description = 'Ein Theme als Paket mit Manifest (und Signatur) schnüren';

    public function handle(ThemePackage $package): int
    {
        $key = $this->option('key');
        try {
            $result = $package->pack((string) $this->argument('name'), (string) ($this->option('output') ?: storage_path('app/themes')), is_string($key) && $key !== '' ? $key : null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('%s · %d Dateien · %s', $result['path'], count($result['manifest']['files']), $result['signed'] ? 'signiert' : 'nicht signiert'));

        return self::SUCCESS;
    }
}
