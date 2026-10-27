<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Demo\DemoQuota;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Öffentliche Demo (TB 66 Nachtrag): Jede Anfrage mit Dateien — Mediathek, Formulare, Forum, Profilbild, Theme-Bilder,
 * Importe, API — wird abgewiesen, wenn sie das Kontingent der Demo überschreiten würde ({@see DemoQuota}). Die Meldung
 * steht am ersten Dateifeld wie ein Validierungsfehler; ausserhalb der Demo läuft alles unverändert durch.
 */
final class LimitDemoStorage
{
    public function handle(Request $request, Closure $next): Response
    {
        $files = array_filter(Arr::dot($request->allFiles()), static fn (mixed $file): bool => $file instanceof UploadedFile);
        if ($files !== []) {
            $bytes = array_sum(array_map(static fn (UploadedFile $file): int => (int) $file->getSize(), $files));
            $reason = DemoQuota::refuse($bytes);
            if ($reason !== null) {
                throw ValidationException::withMessages([(string) array_key_first($files) => $reason]);
            }
        }

        return $next($request);
    }
}
