<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Lookup\Lookups;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Countries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * CR-021: Vorschläge für Felder mit `lookup()` — Firmen oder Adressen im Land des Formulars. Nur angemeldet, nur mit
 * eingeschaltetem Dienst der Site; zehn Minuten im Cache, höchstens 40 Anfragen je Minute und Person.
 */
final class LookupController
{
    public function __invoke(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, [Lookups::COMPANY, Lookups::ADDRESS], true), 404);
        abort_unless(Lookups::enabled($kind, app(CurrentSite::class)->get()), 404);
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:120'], 'land' => ['nullable', 'string', Rule::in(Countries::codes())]]);
        $query = trim((string) $data['q']);
        $country = (string) ($data['land'] ?? 'CH');
        $limiter = 'basalto.lookup.'.(string) $request->user()?->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($limiter, 40)) {
            return response()->json(['results' => [], 'limited' => true], 429);
        }
        RateLimiter::hit($limiter, 60);
        $results = Cache::remember('basalto.lookup.'.$kind.'.'.$country.'.'.sha1(mb_strtolower($query)), 600,
            static fn (): array => Lookups::search($kind, $query, $country));

        return response()->json(['results' => $results]);
    }
}
