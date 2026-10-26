<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Live\Live;
use Basalto\Core\Live\LivePush;
use Basalto\Core\Live\LiveSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Abfrage der Live-Aktualisierung (CR-008 LV-01): `GET /_live?s=<Site>&k[]=<Schlüssel>~<Unterschrift>~<bekannte Version>`.
 * Ohne Session und Cookies; liest nur die Versionen aus dem Cache. `204`, wenn sich nichts geändert hat, sonst die neuen
 * Versionen und — für geänderte Schlüssel mit Quelle — kleine Nutzdaten. Unbekannte oder falsch unterschriebene
 * Schlüssel fallen still weg.
 */
final class LiveController
{
    /** Höchstens so viele Schlüssel je Abfrage. */
    public const MAX_KEYS = 20;

    public function __invoke(Request $request, Live $live, LiveSources $sources): Response|JsonResponse
    {
        $siteId = max(0, (int) $request->query('s', '0'));
        $raw = $request->query('k', []);
        $known = [];
        foreach (array_slice(is_array($raw) ? $raw : [$raw], 0, self::MAX_KEYS) as $item) {
            $parts = is_string($item) ? explode('~', $item, 3) : [];
            if (count($parts) === 3 && $live->valid($siteId, $parts[0], $parts[1])) {
                $known[$parts[0]] = (int) $parts[2];
            }
        }
        $changed = [];
        foreach ($live->versions($siteId, array_keys($known)) as $key => $version) {
            if ($version > $known[$key]) {
                $changed[$key] = $version;
            }
        }
        if ($changed === []) {
            return response('', 204)->header('Cache-Control', 'no-store');
        }
        $data = [];
        foreach (array_keys($changed) as $key) {
            $payload = $sources->payload($siteId, $key);
            if ($payload !== null) {
                $data[$key] = $payload;
            }
        }

        return response()->json(['v' => $changed, 'd' => (object) $data])->header('Cache-Control', 'no-store');
    }

    /**
     * Anmeldung des Browsers an einem privaten Kanal (CR-008 LV-11): `POST /_live/auth` mit `socket_id`, `channel_name`
     * (`private-basalto.<Site>.<Schlüssel>`) und der Unterschrift des Schlüssels — ohne Sitzung, auch für Gäste.
     */
    public function auth(Request $request, Live $live, LivePush $push): JsonResponse
    {
        $socket = (string) $request->input('socket_id', '');
        $channel = (string) $request->input('channel_name', '');
        if (! $push->enabled() || preg_match('/^\d+\.\d+$/', $socket) !== 1 || preg_match('/^private-basalto\.(\d+)\.(.+)$/', $channel, $m) !== 1
            || ! $live->valid((int) $m[1], $m[2], (string) $request->input('token', ''))) {
            return response()->json(['message' => 'Kein Zugriff auf diesen Kanal.'], 403);
        }

        return response()->json(['auth' => $push->auth($socket, $channel)])->header('Cache-Control', 'no-store');
    }
}
