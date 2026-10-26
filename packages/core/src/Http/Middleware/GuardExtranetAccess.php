<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Extranet\ExtranetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechte im Extranet (CR-004 P-09, CR-005 R-12): nur das Team des Herstellers; je Route der Bereich und die Stufe aus
 * dem Register — dieselbe Regel wie `GuardAreaAccess` im CMS.
 */
final class GuardExtranetAccess
{
    public function __construct(private readonly ExtranetAccess $access, private readonly Areas $areas) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user !== null && $this->access->isMember($user), 403, __('Das Extranet ist dem Team des Herstellers vorbehalten.'));
        $requirement = $this->areas->requirement((string) $request->route()?->getName(), $request->method());
        if ($requirement !== null && $requirement['area'] !== null) {
            abort_unless($this->access->allows($user, $requirement['area'], $requirement['level'] ?? Level::View), 403, __('Ihre Rolle hat keinen Zugang zu diesem Bereich.'));
        }

        return $next($request);
    }
}
