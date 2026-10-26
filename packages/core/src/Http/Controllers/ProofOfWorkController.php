<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Security\ProofOfWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** TB 50: eine Aufgabe für den Spamschutz im Browser — ohne Sitzung und Cookies, nie zwischengespeichert. */
final class ProofOfWorkController
{
    public function __invoke(Request $request, ProofOfWork $pow): JsonResponse
    {
        $area = (string) $request->query('bereich', '');
        abort_unless(array_key_exists($area, ProofOfWork::AREAS), 404);

        return response()->json($pow->challenge($area))->header('Cache-Control', 'no-store, private');
    }
}
