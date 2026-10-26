<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Notifications\Recipients;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Updates\CoreUpdater;
use Basalto\Core\Updates\PluginUpdater;
use Basalto\Core\Updates\UpdateCheck;
use Basalto\Core\Updates\UpdateStatus;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `basalto:update` (CR-007, U-11): prüfen, was neu ist, und Kern oder Plugin einspielen — auf der Konsole und als
 * Hintergrundprozess der Seite «Updates». Der Scheduler prüft täglich und benachrichtigt die Verwaltung, sobald etwas
 * Neues dazukommt (je Stand einmal).
 */
final class UpdateCommand extends Command
{
    protected $signature = 'basalto:update
        {--kern : den Kern einspielen}
        {--plugin= : ein Plugin aus dem Marktplatz einspielen (hersteller/paket)}
        {--benachrichtigen : bei Neuem die Verwaltung benachrichtigen (Scheduler)}
        {--von= : wer es angestossen hat (für Protokoll und Anzeige)}
        {--freipass= : Geheimnis, mit dem die Person im Wartungsmodus weiterarbeitet}';

    protected $description = 'Updates prüfen (Kern, Plugins, Themes) oder Kern bzw. Plugin aus dem signierten Archiv einspielen';

    public function handle(UpdateCheck $check, Recorder $recorder, Settings $settings): int
    {
        $by = $this->option('von') ? (string) $this->option('von') : null;
        $bypass = $this->option('freipass') ? (string) $this->option('freipass') : null;
        if ($this->option('kern') || $this->option('plugin')) {
            try {
                $updater = $this->option('kern') ? app(CoreUpdater::class) : app(PluginUpdater::class)->for((string) $this->option('plugin'));
                $result = $updater->run($by, $bypass);
            } catch (RuntimeException $e) {
                // Die Seite wartet auf einen Stand — sie soll den Grund sehen, nicht einen Stillstand
                $failed = new UpdateStatus(UpdateStatus::file(), $this->option('kern') ? 'core' : 'plugin:'.(string) $this->option('plugin'), $this->option('kern') ? (string) __('Kern') : (string) $this->option('plugin'), '', $by);
                $failed->finish(false, $e->getMessage());
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            foreach ($result['status']['steps'] ?? [] as $step) {
                $this->line(sprintf('  %s %s%s', $step['state'] === 'ok' ? '✓' : '✗', $step['label'], $step['detail'] !== null && $step['state'] !== 'ok' ? ' — '.$step['detail'] : ''));
            }
            $check->forget();
            if (! $result['ok']) {
                $this->error((string) ($result['status']['error'] ?? 'Update gescheitert.'));

                return self::FAILURE;
            }
            $recorder->record($this->option('kern') ? 'system.updated' : 'system.plugin_updated', null, null, ['version' => $result['version'], 'plugin' => $this->option('plugin') ?: null, 'von' => $by]);
            $this->info(sprintf('Aktualisiert auf %s.', (string) $result['version']));

            return self::SUCCESS;
        }

        $result = $check->run(true);
        $rows = [['Kern', $result['core']['installed'], $result['core']['available'] ?? '—', $result['core']['newer'] ? 'neu' : 'aktuell']];
        foreach ($result['plugins'] as $plugin) {
            $rows[] = ['Plugin '.$plugin['name'], $plugin['installed'] ?? '—', $plugin['available'] ?? '—', $plugin['newer'] ? 'neu' : 'aktuell'];
        }
        foreach ($result['themes'] as $theme) {
            $rows[] = ['Theme '.$theme['name'].' ('.$theme['site'].')', $theme['installed'] ?? '—', $theme['available'] ?? '—', $theme['newer'] ? 'neu' : 'aktuell'];
        }
        $this->table(['', 'installiert', 'verfügbar', ''], $rows);
        $this->line(sprintf('Quelle: %s (%s)', $result['source'], $result['stage']));
        if ($result['error'] !== null) {
            $this->warn($result['error']);
        }
        if ($this->option('benachrichtigen') && $result['count'] > 0 && ! Saas::enabled()) {
            $items = array_merge($result['core']['newer'] ? ['Kern '.$result['core']['available']] : [],
                array_map(static fn (array $p): string => $p['title'].' '.$p['available'], array_filter($result['plugins'], static fn (array $p): bool => $p['newer'])),
                array_map(static fn (array $t): string => $t['theme'].' '.$t['available'], array_filter($result['themes'], static fn (array $t): bool => $t['newer'])));
            $signature = md5(implode('|', $items));
            if ($settings->get('updates.notified') !== $signature) {
                Notify::make('operations.updates')->title('Updates verfügbar')->body(':list', ['list' => implode(', ', $items)])
                    ->url(route('basalto.updates'), 'Updates öffnen')->to(Recipients::installation('updates'))->send();
                $settings->set('updates.notified', $signature);
            }
        }

        return self::SUCCESS;
    }
}
