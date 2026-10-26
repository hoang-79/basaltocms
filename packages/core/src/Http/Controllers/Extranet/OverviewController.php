<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Extranet;

use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\Http\Middleware\ResolveExtranet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Übersicht «Heute zu tun» (CR-004, P-10): je Aufgabe eine Zahl mit Link auf die passend gefilterte Liste. */
final class OverviewController
{
    public function __invoke(Request $request, ExtranetRegistry $registry): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $last = $request->session()->get(ResolveExtranet::LAST_URL);

        return Inertia::render('core::Extranet/Overview', [
            'todos' => $registry->todosFor($user),
            'last_url' => is_string($last) ? $last : null,
            'name' => (string) $user->getAttribute('name'),
        ]);
    }
}
