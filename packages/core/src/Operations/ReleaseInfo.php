<?php

declare(strict_types=1);

namespace Basalto\Core\Operations;

use Basalto\Core\Core;
use Basalto\Core\Demo\DemoMode;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Stand der Installation für den Hinweis unten links auf jeder Seite (Hoang, 27.10.2026: «damit ich sehen kann, auf
 * welchen stand es ist»): Version, Zeitpunkt der Auslieferung und die Umgebung — Produktion, Testing, Demo oder lokal —
 * mit eigener Farbe. Ausgeliefert steht der Stand in `RELEASE` («c946cc00 20261027-121621», Zeit des Macs in Zürich);
 * lokal der letzte Commit aus `.git`.
 */
final class ReleaseInfo
{
    /** Zeitzone, in der `tools/ausliefern.sh` den Zeitpunkt schreibt und in der der Hinweis ihn zeigt */
    public const ZONE = 'Europe/Zurich';

    /**
     * @return array{version: string, commit: string|null, at: string|null, environment: string, label: string}
     */
    public static function current(): array
    {
        [$commit, $at] = self::fromReleaseFile() ?? self::fromGit() ?? [null, null];
        $environment = self::environment();

        return [
            'version' => Core::VERSION,
            'commit' => $commit,
            'at' => $at?->setTimezone(self::ZONE)->format('d.m.Y H:i'),
            'environment' => $environment,
            'label' => match ($environment) {
                'prod' => 'PROD',
                'testing' => 'TESTING',
                'demo' => 'DEMO',
                'lokal' => (string) __('LOKAL'),
                default => mb_strtoupper($environment),
            },
        ];
    }

    /** prod, testing, demo, lokal — oder der Name der Umgebung. */
    public static function environment(): string
    {
        if (DemoMode::enabled()) {
            return 'demo';
        }

        return match ((string) app()->environment()) {
            'production' => 'prod',
            'staging', 'testing' => 'testing',
            'local' => 'lokal',
            default => (string) app()->environment(),
        };
    }

    /** @return array{0: string|null, 1: CarbonImmutable|null}|null */
    private static function fromReleaseFile(): ?array
    {
        $line = app(Health::class)->release();
        if ($line === null) {
            return null;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        $at = null;
        if (isset($parts[1]) && preg_match('/^\d{8}-\d{6}$/', $parts[1]) === 1) {
            try {
                $at = CarbonImmutable::createFromFormat('Ymd-His', $parts[1], self::ZONE) ?: null;
            } catch (Throwable) {
                $at = null;
            }
        }

        return [$parts[0] ?? null, $at];
    }

    /**
     * Lokal: der Commit, auf den HEAD zeigt, und wann der Zweig zuletzt weiterging.
     *
     * @return array{0: string|null, 1: CarbonImmutable|null}|null
     */
    private static function fromGit(): ?array
    {
        $git = base_path('.git');
        $head = is_file($git.'/HEAD') ? trim((string) file_get_contents($git.'/HEAD')) : '';
        if ($head === '') {
            return null;
        }
        if (str_starts_with($head, 'ref: ')) {
            $ref = $git.'/'.substr($head, 5);
            $sha = is_file($ref) ? trim((string) file_get_contents($ref)) : '';
            $time = is_file($ref) ? filemtime($ref) : false;

            return [$sha !== '' ? substr($sha, 0, 8) : null, $time !== false ? CarbonImmutable::createFromTimestamp($time) : null];
        }

        return [substr($head, 0, 8), null];
    }
}
