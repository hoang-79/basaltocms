<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\Notifications\NotificationCenter;
use Basalto\Core\Notifications\NotificationType;
use Basalto\Core\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Geteilte Props im Extranet (CR-004, P-09): alles aus dem Backend (Seiten der Module nutzen es weiter) und dazu
 * `extranet` — Menü nach Rechten, Stufen je Bereich, Abzeichen. Das Layout im Browser wählt danach den Rahmen.
 */
final class HandleExtranetRequests extends Middleware
{
    protected $rootView = 'basalto::app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $shared = app(HandleBackendRequests::class)->share($request);
        $user = $request->user();
        $access = app(ExtranetAccess::class);
        $shared['extranet'] = $user === null ? null : [
            'home' => route('extranet.home'),
            'host' => Extranet::host(),
            'menu' => app(ExtranetRegistry::class)->menuFor($user),
            'levels' => $access->levels($user),
            'role' => ($definition = $access->definition($user)) === null ? null : ($definition->locked ? __('Inhaber') : $definition->name),
            'cms_url' => Vendor::url((string) config('basalto.backend.path', 'admin')),
            'logout_url' => route('basalto.logout'),
        ];

        // TB 27: im Extranet zählt die Glocke die Benachrichtigungen des Teams
        if (isset($shared['backend']) && is_array($shared['backend'])) {
            $shared['backend']['notifications'] = $user instanceof Model ? app(NotificationCenter::class)->shared($user, NotificationType::EXTRANET) : null;
        }

        return $shared;
    }
}
