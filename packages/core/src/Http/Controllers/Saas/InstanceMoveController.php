<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Saas;

use Basalto\Core\Http\Middleware\VerifyInstanceSignature;
use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Saas\Move\InstanceMover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Die signierte Schnittstelle des Umzugs zwischen Instanzen (TB 12 Scheibe 4, {@see InstanceMover}) unter
 * `/_basalto/instanz/…` — ohne Sitzung und CSRF, nur mit Signatur ({@see VerifyInstanceSignature}). Antworten in JSON.
 */
final class InstanceMoveController
{
    public function __construct(private readonly InstanceMover $mover) {}

    /** Ziel: Auftrag annehmen — das Archiv holt der Job. */
    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate(['uuid' => ['required', 'uuid'], 'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'size' => ['required', 'integer', 'min:1']]);
        $move = $this->mover->receive(self::source($request), $data);

        return response()->json(['uuid' => $move->uuid, 'status' => $move->status], 202);
    }

    /** Quelle: das Archiv eines gemeldeten Umzugs ausliefern — nur an dessen Zielinstanz. */
    public function archive(Request $request, string $uuid): StreamedResponse
    {
        $move = $this->outgoing($request, $uuid);
        abort_unless(in_array($move->status, [InstanceMove::SENT, InstanceMove::EXPORTING], true) && $move->disk !== null && $move->path !== null, 404);

        return Storage::disk($move->disk)->download($move->path, 'umzug-'.$move->uuid.'.zip', ['Content-Type' => 'application/zip']);
    }

    /** Quelle: Rückmeldung des Ziels (angekommen oder gescheitert). */
    public function complete(Request $request, string $uuid): JsonResponse
    {
        $move = $this->outgoing($request, $uuid);
        $data = $request->validate(['status' => ['required', 'in:imported,failed'], 'error' => ['nullable', 'string', 'max:2000'], 'account' => ['nullable', 'integer'],
            'sites' => ['nullable', 'array'], 'url' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'array']]);
        $this->mover->complete($move, $data);

        return response()->json(['uuid' => $move->uuid, 'status' => $move->refresh()->status]);
    }

    /** Quelle: Bestätigung des Besitzers auf dem Ziel — Kopie löschen. */
    public function cleanup(Request $request, string $uuid): JsonResponse
    {
        $move = $this->outgoing($request, $uuid);
        $counts = $this->mover->cleanup($move);

        return response()->json(['uuid' => $move->uuid, 'status' => $move->refresh()->status] + $counts);
    }

    private function outgoing(Request $request, string $uuid): InstanceMove
    {
        $move = InstanceMove::query()->where('uuid', $uuid)->where('direction', InstanceMove::OUT)->first();
        abort_if($move === null || $move->target !== self::source($request), 404);

        return $move;
    }

    private static function source(Request $request): string
    {
        return (string) $request->attributes->get(VerifyInstanceSignature::ATTRIBUTE);
    }
}
