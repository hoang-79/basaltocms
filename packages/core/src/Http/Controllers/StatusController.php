<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Console\HeartbeatCommand;
use Basalto\Core\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Betriebsstatus für den Cloud-Nachweis: Treiber, Herzschlag des Schedulers, Medien und Varianten — ohne Geheimnisse. */
final class StatusController
{
    public function __invoke(): JsonResponse
    {
        $heartbeat = Cache::get(HeartbeatCommand::KEY);
        $disk = (string) config('filesystems.default');

        return response()->json([
            'environment' => app()->environment(),
            'laravel_cloud' => (bool) config('basalto.cloud.on_cloud', false),
            'php' => PHP_VERSION,
            'drivers' => [
                'disk' => ['name' => $disk, 'driver' => config('filesystems.disks.'.$disk.'.driver')],
                'cache' => config('cache.default'),
                'queue' => config('queue.default'),
                'session' => config('session.driver'),
                'database' => config('database.default'),
            ],
            'scheduler' => [
                'heartbeat' => $heartbeat,
                'age_seconds' => is_string($heartbeat) ? CarbonImmutable::parse($heartbeat)->diffInSeconds(now()) : null,
            ],
            'media' => [
                'count' => Media::query()->count(),
                'with_variants' => Media::query()->whereNotNull('variants_generated_at')->count(),
                'last_variant_at' => Media::query()->max('variants_generated_at'),
            ],
        ]);
    }
}
