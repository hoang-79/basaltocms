<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Core;
use Basalto\Core\Operations\ReleaseInfo;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia für den Installer: dieselbe Blade-Wurzel wie das Backend, aber geteilte Props ohne Datenbank — vor der
 * Migration gibt es weder Sites noch Einstellungen, die das Backend sonst liest.
 */
final class HandleInstallRequests extends Middleware
{
    protected $rootView = 'basalto::app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'backend' => ['name' => 'Basalto', 'brand' => null, 'version' => Core::VERSION],
            'release' => ReleaseInfo::current(),
        ];
    }
}
