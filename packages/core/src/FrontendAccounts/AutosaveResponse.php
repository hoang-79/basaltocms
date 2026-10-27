<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\Activity\AutosaveRequest;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Antworten für den Autosave im Kundenkonto und in der Community (CR-013): Das Skript `speichern.ts` sendet mit
 * `Accept: application/json` und der Kopfzeile `X-Basalto-Autosave` — dann gibt der Controller JSON statt einer
 * Weiterleitung zurück. Ohne Skript bleibt alles wie bisher (Weiterleitung mit Meldung, Fehler in der Sitzung).
 */
final class AutosaveResponse
{
    public static function wanted(Request $request): bool
    {
        return $request->expectsJson() && $request->headers->get(AutosaveRequest::HEADER) === '1';
    }

    /**
     * Gespeichert; `values` gehen an andere Formulare derselben Gruppe auf der Seite (gleiche Kundendaten), `reload`
     * lädt die Seite neu (etwa nach einem neuen Profilbild, das an mehreren Stellen erscheint).
     *
     * @param  array<string, string|null>  $values
     */
    public static function saved(string $message, array $values = [], bool $reload = false): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'message' => $message, 'values' => (object) $values, 'reload' => $reload]);
    }

    public static function invalid(MessageBag $errors): JsonResponse
    {
        return new JsonResponse(['message' => $errors->first(), 'errors' => $errors->toArray()], 422);
    }
}
