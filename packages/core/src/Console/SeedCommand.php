<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Models\Site;
use Basalto\Core\Publish\SeedImporter;
use Database\Seeders\BasaltoSiteSeeder;
use Illuminate\Console\Command;

/** Startinhalte aus `seed` einspielen (L-11) — idempotent: zweimal einspielen ergibt keine Duplikate. */
final class SeedCommand extends Command
{
    protected $signature = 'basalto:seed {site : Handle der Site}
        {--auffrischen : Unveränderte Einträge aus dem Seed neu einspielen (Bearbeitetes bleibt)}';

    protected $description = 'Startinhalte aus dem seed des Themes einspielen (idempotent)';

    public function handle(SeedImporter $importer): int
    {
        $site = Site::query()->where('handle', (string) $this->argument('site'))->first();
        if ($site === null) {
            $this->error(sprintf('Site «%s» gibt es nicht.', (string) $this->argument('site')));

            return self::FAILURE;
        }
        $result = $importer->import($site, (bool) $this->option('auffrischen'));
        foreach ($result['created'] as $line) {
            $this->line('  + '.$line);
        }
        $this->info(sprintf('Seed: %d angelegt, %d schon vorhanden.', count($result['created']), count($result['kept'])));

        // Lokal zeigen die Inhalte der Hersteller-Site auf die lokale Landschaft (demo-basalto.test …) — der Seed bringt
        // die Adressen der Produktion zurück, also danach wieder umschreiben
        // Das Release liefert database/seeders nicht mit — ohne die Prüfung brach der Befehl auf jeder lokalen Installation
        // (APP_ENV=local, etwa der Sandbox des Marktplatzes) nach dem Einspielen ab
        if ($result['created'] !== [] && app()->environment('local') && class_exists(BasaltoSiteSeeder::class) && $site->handle === BasaltoSiteSeeder::HANDLE) {
            $this->call('basalto:local', ['--no-publish' => true]);
        }

        return self::SUCCESS;
    }
}
