<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai\Http\Middleware;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Auf einer Site ohne das Modul gibt es weder Einstellungen noch Vorschläge — 404 wie bei den anderen Modulen. */
final class EnsureAiEnabled
{
    public function __construct(private readonly CurrentSite $current, private readonly Backend $backend) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->current->get();
        abort_if($site === null || ! $this->backend->moduleEnabled('ai', $site), 404);

        return $next($request);
    }
}
