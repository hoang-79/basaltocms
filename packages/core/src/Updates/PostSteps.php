<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Die Schritte nach dem Tausch (CR-007, U-05) — je in einem frischen PHP-Prozess mit dem neuen Code, nie im laufenden:
 * Pakete entdecken, migrieren, Assets veröffentlichen, Caches leeren. In Tests ersetzbar. Ohne `proc_open` (cyon im
 * Browser) geht das nicht — dann per SSH mit `php artisan basalto:update --kern` ({@see Installation::problems()}).
 */
class PostSteps
{
    /** @var list<array{0: string, 1: list<string>}> */
    public const AFTER_SWAP = [
        ['Pakete erkennen', ['package:discover']],
        ['Datenbank migrieren', ['migrate', '--force']],
        ['Assets veröffentlichen', ['vendor:publish', '--tag=basalto-assets', '--force']],
        ['Caches leeren', ['optimize:clear']],
    ];

    /** Das PHP für Artisan im eigenen Prozess — eingestellt (`BASALTO_PHP_BINARY`), sonst das des laufenden Prozesses. */
    public static function php(): string
    {
        $configured = config('basalto.updates.php');

        return is_string($configured) && $configured !== '' ? $configured : ((new PhpExecutableFinder)->find(false) ?: 'php');
    }

    /** Darf PHP hier Prozesse starten? Bei cyon ist `proc_open` im Browser oft abgeschaltet (CR-011 TB 62). */
    public static function canStartProcesses(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && function_exists('proc_close') && ! in_array('proc_open', $disabled, true) && ! in_array('proc_close', $disabled, true);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{ok: bool, output: string}
     */
    public function artisan(string $root, array $arguments): array
    {
        $php = self::php();
        $process = new Process([$php, 'artisan', ...$arguments, '--no-interaction'], $root, null, null, 900);
        $process->run();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        return ['ok' => $process->isSuccessful(), 'output' => mb_substr($output, -2000)];
    }
}
