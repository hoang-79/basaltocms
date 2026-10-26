<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Operations\Health;
use Illuminate\Http\JsonResponse;

/**
 * `/up` (Readiness: Datenbank und Herzschlag des Schedulers; 503, wenn gestört) und `/up/live` (Liveness: nur der
 * Prozess, ohne Datenbank) — für die Proben von Laravel Cloud und jede Überwachung (K-17). Ohne Geheimnisse.
 */
final class UpController
{
    public function __construct(private readonly Health $health) {}

    public function ready(): JsonResponse
    {
        $result = $this->health->readiness();

        return response()->json($result, $result['ok'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    public function live(): JsonResponse
    {
        return response()->json($this->health->liveness(), 200, ['Cache-Control' => 'no-store']);
    }
}
