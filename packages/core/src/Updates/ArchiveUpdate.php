<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Core;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Ein Update aus einem signierten Archiv (CR-007, U-05/U-08) — der gemeinsame Ablauf für Kern und Plugins:
 *
 * 1. Sperre, Platz und Schreibrechte prüfen
 * 2. Archiv laden, Grösse/Prüfsumme/Signatur prüfen, in einen Bereitstellungs-Ordner entpacken
 * 3. Sicherung (Datenbank und Medien)
 * 4. Wartungsmodus (wer das Update gestartet hat, kommt mit dem Freipass weiter)
 * 5. Dateien tauschen: jedes Ziel wird in den Ordner `vorher-…` verschoben, das neue an seinen Platz — umkehrbar
 * 6. Folgeschritte in frischen Prozessen (Pakete, Migrationen, Assets, Caches)
 * 7. Scheitert 5 oder 6: alles zurück, Caches leeren; Wartungsmodus aus
 *
 * Migrationen sind abwärtsverträglich (TB 06): Kommen die alten Dateien zurück, läuft der alte Code auf dem neuen
 * Schema. Nach dem Tausch lädt dieser Prozess keine Klassen mehr aus `vendor` — nur noch Dateifunktionen und Prozesse.
 */
abstract class ArchiveUpdate
{
    /** @var list<array{0: string, 1: bool}> getauschte Pfade (relativ) und ob es vorher etwas gab */
    private array $swapped = [];

    private string $work = '';

    public function __construct(protected readonly Installation $installation, protected readonly PostSteps $steps, private readonly UpdateBackup $backup) {}

    /** Kurzname für Status und Sperre, z. B. `core` oder `plugin:pixelwerk/karten`. */
    abstract protected function target(): string;

    abstract protected function title(): string;

    /**
     * Archiv laden und prüfen; liefert den Pfad der Datei und die Version.
     *
     * @return array{0: string, 1: string}
     */
    abstract protected function download(string $work, UpdateStatus $status): array;

    /** Inhalt im Bereitstellungs-Ordner prüfen (passt er zu dem, was getauscht werden soll?). */
    abstract protected function validateStage(string $stage, string $version): void;

    /**
     * Welche Pfade (relativ zur Wurzel der Installation) aus dem Bereitstellungs-Ordner an ihren Platz kommen —
     * `[Quelle im Stage, Ziel in der Installation]`.
     *
     * @return list<array{0: string, 1: string}>
     */
    abstract protected function entries(string $stage): array;

    protected function afterSuccess(string $version): void {}

    /** @return array{ok: bool, version: string|null, status: array<string, mixed>} */
    public function run(?string $by = null, ?string $bypass = null): array
    {
        $root = $this->installation->root();
        $statusFile = $root.'/storage/app/updates/status.json';
        // Dateisperre statt Cache: endet der Prozess (auch abgestürzt), ist sie weg — und sie liegt nicht in der Datenbank
        @mkdir($root.'/storage/app/updates', 0775, true);
        $lock = fopen($root.'/storage/app/updates/.sperre', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException((string) __('Es läuft schon ein Update.'));
        }
        $status = new UpdateStatus($statusFile, $this->target(), $this->title(), '', $by);
        $version = null;
        $down = false;
        try {
            $problems = $this->installation->problems();
            if ($problems !== []) {
                throw new RuntimeException(implode(' ', $problems));
            }
            $this->work = $root.'/storage/app/updates/lauf-'.date('YmdHis');
            @mkdir($this->work, 0775, true);
            [$archive, $version] = $this->download($this->work, $status);
            $stage = $this->work.'/neu';
            $this->extract($archive, $stage);
            $this->validateStage($stage, $version);
            $status->step((string) __('Archiv entpackt und geprüft'));

            $backup = $this->backup->create($root.'/storage/app/backups');
            $status->step((string) __('Sicherung angelegt'), 'ok', basename($backup));

            Artisan::call('down', array_filter(['--retry' => 30, '--refresh' => 15, '--secret' => $bypass]));
            $down = true;
            $status->step((string) __('Wartungsmodus an'));

            // Ab hier keine neuen Klassen aus vendor laden: was gebraucht wird, steht schon bereit
            class_exists(ZipArchive::class);
            $this->swap($stage, $root, $this->work.'/vorher');
            $status->step((string) __(':count Teile getauscht', ['count' => count($this->swapped)]));

            foreach (PostSteps::AFTER_SWAP as [$label, $arguments]) {
                $result = $this->steps->artisan($root, $arguments);
                if (! $result['ok']) {
                    $status->step((string) __($label), 'failed', $result['output']);

                    throw new RuntimeException((string) __(':step ist gescheitert.', ['step' => __($label)]));
                }
                $status->step((string) __($label));
            }
            $this->afterSuccess($version);
            $this->cleanup($root);
            $status->finish(true);

            return ['ok' => true, 'version' => $version, 'status' => $status->toArray()];
        } catch (Throwable $e) {
            if ($this->swapped !== []) {
                $this->rollback($root, $this->work.'/vorher');
                $status->step((string) __('Alte Dateien zurückgeholt'), 'rollback');
                $this->steps->artisan($root, ['optimize:clear']);
            }
            $status->finish(false, $e->getMessage());

            return ['ok' => false, 'version' => $version, 'status' => $status->toArray()];
        } finally {
            if ($down) {
                $this->steps->artisan($root, ['up']);
                $status->step((string) __('Wartungsmodus aus'));
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Laden mit Prüfung von Grösse und SHA-256.
     *
     * @param  array{0: string, 1: string}|null  $basicAuth
     */
    protected function fetch(string $url, string $target, int $maxBytes, ?string $sha256, ?int $size = null, ?array $basicAuth = null): void
    {
        try {
            $request = Http::timeout(600)->connectTimeout(10)->withUserAgent('Basalto/'.Core::VERSION)->sink($target);
            $response = ($basicAuth !== null ? $request->withBasicAuth($basicAuth[0], $basicAuth[1]) : $request)->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException((string) __(':host ist nicht erreichbar.', ['host' => UpdateSource::host()]));
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException((string) __('Kein Zugriff auf das Paket (Kundenkonto, Token oder Update-Jahr prüfen).'));
        }
        if (! $response->successful()) {
            throw new RuntimeException((string) __('Der Download antwortet mit :status.', ['status' => $response->status()]));
        }
        $bytes = (int) @filesize($target);
        if ($bytes === 0 || $bytes > $maxBytes || ($size !== null && $bytes !== $size)) {
            throw new RuntimeException((string) __('Das Archiv hat nicht die erwartete Grösse.'));
        }
        if ($sha256 !== null && ! hash_equals($sha256, (string) hash_file('sha256', $target))) {
            throw new RuntimeException((string) __('Die Prüfsumme des Archivs passt nicht.'));
        }
    }

    private function extract(string $archive, string $stage): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException((string) __('Das Archiv lässt sich nicht öffnen.'));
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            // Kein Pfad aus dem Archiv heraus
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                $zip->close();
                throw new RuntimeException((string) __('Das Archiv enthält ungültige Pfade.'));
            }
        }
        @mkdir($stage, 0775, true);
        if (! $zip->extractTo($stage)) {
            $zip->close();
            throw new RuntimeException((string) __('Das Archiv lässt sich nicht entpacken.'));
        }
        $zip->close();
    }

    private function swap(string $stage, string $root, string $before): void
    {
        foreach ($this->entries($stage) as [$from, $to]) {
            $source = $stage.'/'.$from;
            if (! file_exists($source)) {
                continue;
            }
            $target = $root.'/'.$to;
            $existed = file_exists($target) || is_link($target);
            if ($existed) {
                @mkdir(dirname($before.'/'.$to), 0775, true);
                if (! rename($target, $before.'/'.$to)) {
                    throw new RuntimeException('Verschieben gescheitert: '.$to);
                }
            }
            $this->swapped[] = [$to, $existed];
            @mkdir(dirname($target), 0775, true);
            if (! rename($source, $target)) {
                throw new RuntimeException('Einsetzen gescheitert: '.$to);
            }
        }
    }

    private function rollback(string $root, string $before): void
    {
        $trash = $before.'-verworfen';
        foreach (array_reverse($this->swapped) as [$to, $existed]) {
            $target = $root.'/'.$to;
            if (file_exists($target) || is_link($target)) {
                @mkdir(dirname($trash.'/'.$to), 0775, true);
                @rename($target, $trash.'/'.$to);
            }
            if ($existed) {
                @rename($before.'/'.$to, $target);
            }
        }
        $this->swapped = [];
    }

    /** Nur der letzte Lauf bleibt liegen (mit `vorher/` zum Zurückholen von Hand), ältere gehen. */
    private function cleanup(string $root): void
    {
        @self::remove($this->work.'/neu');
        foreach (glob($root.'/storage/app/updates/lauf-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($dir !== $this->work) {
                self::remove($dir);
            }
        }
        foreach (glob($this->work.'/*.zip') ?: [] as $zip) {
            @unlink($zip);
        }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
