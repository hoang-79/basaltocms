<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Models\ApiToken;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\Offboarding\LockedSite;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST-API (M-17): Die Site einer Anfrage kommt allein aus dem Token — nie aus einem Pfad, einer Abfrage oder einem
 * Kopf, den der Aufrufer frei wählt. Danach gelten der Site-Scope (K-02) und die Policies wie im Backend.
 * Lesende Methoden brauchen die Fähigkeit `read`, schreibende `write`. Eine Anmeldung aus der Sitzung (Sanctums
 * «TransientToken») hat keine Site und gilt hier nicht; deaktivierte Konten und frühere Mitglieder sind ausgesperrt.
 */
final class ResolveSiteFromToken
{
    private const READING = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof HasApiTokens ? $user->currentAccessToken() : null;
        if (! $token instanceof ApiToken || $token->site_id === null) {
            throw new AuthenticationException(__('Ein API-Token einer Site ist nötig (Authorization: Bearer …).'));
        }
        abort_if($user->getAttribute('deactivated_at') !== null, 403, __('Dieses Konto ist deaktiviert.'));
        $site = Site::query()->find($token->site_id);
        abort_if($site === null || $site->roleKeyOf($user) === null, 403, __('Kein Zugriff mehr auf die Site dieses Tokens.'));
        $ability = in_array($request->getMethod(), self::READING, true) ? ApiToken::READ : ApiToken::WRITE;
        abort_unless($token->can($ability), 403, __('Diesem Token fehlt die Fähigkeit «:ability».', ['ability' => $ability]));
        $this->current->set($site);
        // TB 12 Scheibe 4 (M-21): gesperrtes Konto — die API liefert keine Inhalte mehr
        abort_if(LockedSite::response($site) !== null, 410, __('Diese Site ist nicht mehr verfügbar.'));

        return $next($request);
    }
}
