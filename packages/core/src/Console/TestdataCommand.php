<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Content\Pages;
use Basalto\Core\Demo\DemoMode;
use Basalto\Core\Models\Site;
use Basalto\Core\Testdata\Testdata;
use Basalto\Core\Testdata\TestdataRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `basalto:testdaten` — viele, verschiedene Testdaten für Entwicklung, Test- und Demo-Umgebung: Team und Extranet,
 * Kundenkonten in allen Varianten, Community, Forum, Kommentare, Mitglieder, Marktplatz und Lizenzverkauf, je nachdem,
 * welche Module auf der Site eingeschaltet sind. Mails gehen dabei nirgends hin (Mailer «array», Warteschlange
 * synchron). `--entfernen` räumt alles wieder ab, was die Domain der Testdaten trägt. In Produktion nur im Demo-Modus.
 */
final class TestdataCommand extends Command
{
    protected $signature = 'basalto:testdaten
        {site : Kennung der Site}
        {--umfang=mittel : klein, mittel oder gross}
        {--nur=* : nur diese Bereiche (Schlüssel, der Kern läuft immer mit)}
        {--neu : vorhandene Testdaten vorher entfernen}
        {--entfernen : nur entfernen, nichts anlegen}';

    protected $description = 'Legt viele, verschiedene Testdaten an (Team, Kunden, Community, Forum, Kommentare, Marktplatz, Lizenzen) oder entfernt sie';

    public function handle(Testdata $testdata, PageCache $pageCache, Pages $pages): int
    {
        if (app()->isProduction() && ! DemoMode::enabled()) {
            $this->error('Testdaten gibt es nicht in Produktion — nur lokal, in Test-Umgebungen und in der Demo.');

            return self::FAILURE;
        }
        $site = Site::query()->where('handle', (string) $this->argument('site'))->first();
        if ($site === null) {
            $this->error('Keine Site mit dieser Kennung.');

            return self::FAILURE;
        }
        $scale = TestdataRun::SCALES[(string) $this->option('umfang')] ?? null;
        if ($scale === null) {
            $this->error('Umfang «klein», «mittel» oder «gross».');

            return self::FAILURE;
        }
        // Nichts verlässt die Maschine: Mails in den Speicher, Benachrichtigungen sofort statt über einen Worker
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        if ($this->option('entfernen') || $this->option('neu')) {
            $testdata->remove($site);
            $this->info(sprintf('%s: Testdaten entfernt.', $site->handle));
            if ($this->option('entfernen')) {
                $pageCache->invalidate($site->id);
                $pages->forget();

                return self::SUCCESS;
            }
        }
        $started = microtime(true);
        $run = new TestdataRun($site, $scale);
        // Ganz oder gar nicht: bricht eine Quelle ab, bleibt kein halber Bestand zurück
        $only = array_values(array_map('strval', (array) $this->option('nur')));
        $ran = DB::transaction(static fn (): array => $testdata->seed($run, $only));
        $pageCache->invalidate($site->id);
        $pages->forget();
        $rows = [];
        foreach ($run->counts() as $what => $n) {
            $rows[] = [$what, $n];
        }
        $this->table(['Angelegt', 'Anzahl'], $rows);
        $this->info(sprintf('%s: Testdaten (%s) in %.1f s — Bereiche: %s. Adressen enden auf @%s.', $site->handle, (string) $this->option('umfang'),
            microtime(true) - $started, implode(', ', $ran), TestdataRun::DOMAIN));

        return self::SUCCESS;
    }
}
