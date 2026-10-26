<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

/**
 * Stand des laufenden bzw. letzten Updates (CR-007, U-12) als Datei in `storage/app/updates/status.json` — bewusst
 * ohne Datenbank und ohne Klassen des Frameworks: Während des Tauschs liegen Kern und `vendor` kurz im Umbau, die
 * Datei schreibt sich trotzdem. Die Seite «Updates» liest sie alle zwei Sekunden.
 *
 * @phpstan-type Step array{label: string, state: string, detail: string|null, at: string}
 * @phpstan-type Status array{target: string, title: string, version: string, state: string, started_at: string, finished_at: string|null, by: string|null, steps: list<Step>, error: string|null}
 */
final class UpdateStatus
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Ein Lauf ohne Lebenszeichen seit so vielen Minuten gilt als abgebrochen. */
    public const STALE_MINUTES = 30;

    /** @var Status */
    private array $status;

    public function __construct(private readonly string $file, string $target, string $title, string $version, ?string $by)
    {
        $this->status = ['target' => $target, 'title' => $title, 'version' => $version, 'state' => self::RUNNING, 'started_at' => date('c'), 'finished_at' => null,
            'by' => $by, 'steps' => [], 'error' => null];
        $this->write();
    }

    public static function file(): string
    {
        return storage_path('app/updates/status.json');
    }

    /** @return Status|null */
    public static function read(?string $file = null): ?array
    {
        $data = json_decode((string) @file_get_contents($file ?? self::file()), true);
        if (! is_array($data) || ! isset($data['state'], $data['steps'])) {
            return null;
        }
        // Ein Lauf ohne Lebenszeichen (Prozess abgestürzt oder nie gestartet) blockiert nicht für immer
        $age = time() - (int) filemtime((string) ($file ?? self::file()));
        if ($data['state'] === self::RUNNING && ($age > self::STALE_MINUTES * 60 || ($data['steps'] === [] && $age > 120))) {
            $data['state'] = self::FAILED;
            $data['error'] = $data['steps'] === []
                ? (string) __('Der Update-Prozess ist nicht gestartet — auf dem Server «php artisan basalto:update --kern» ausführen und storage/logs/update.log ansehen.')
                : (string) __('Abgebrochen — kein Lebenszeichen mehr.');
        }

        /** @var Status */
        return $data;
    }

    public static function running(?string $file = null): bool
    {
        return (self::read($file)['state'] ?? null) === self::RUNNING;
    }

    public function step(string $label, string $state = 'ok', ?string $detail = null): void
    {
        $this->status['steps'][] = ['label' => $label, 'state' => $state, 'detail' => $detail, 'at' => date('c')];
        $this->write();
    }

    public function finish(bool $ok, ?string $error = null): void
    {
        $this->status['state'] = $ok ? self::DONE : self::FAILED;
        $this->status['error'] = $error;
        $this->status['finished_at'] = date('c');
        $this->write();
    }

    /** @return Status */
    public function toArray(): array
    {
        return $this->status;
    }

    private function write(): void
    {
        @mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file.'.tmp', (string) json_encode($this->status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($this->file.'.tmp', $this->file);
    }
}
