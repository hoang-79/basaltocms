<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Operations\ReleaseInfo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Stand der Installation unten links auch auf den Seiten der Website (Hoang, 27.10.2026). In der Produktion nur für
 * Personen, die im Backend angemeldet sind — Besuchende sehen keine Versionsnummer; auf Testing, Demo und lokal für alle.
 * Backend und Extranet zeigen den Hinweis selbst (ReleaseBadge.vue). Eingesetzt nach dem Seiten-Cache: der Hinweis
 * landet nie im Cache.
 */
final class ShowReleaseBadge
{
    private const TONES = ['prod' => '#b8232f', 'testing' => '#b26b00', 'demo' => '#2557d6', 'lokal' => '#2a744a'];

    public function __construct(private readonly Backend $backend) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);
        $path = trim($this->backend->path(), '/');
        if (! $response instanceof Response || $response->getStatusCode() !== 200 || $request->is($path, $path.'/*') || Extranet::isRequest($request)
            || ! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }
        $content = $response->getContent();
        // Seiten mit Inertia (auch ausserhalb von /admin, etwa das Testmanagement unter /tests) zeigen den Hinweis selbst
        if (! is_string($content) || ! str_contains($content, '</body>') || str_contains($content, 'id="basalto-release"') || str_contains($content, ' data-page=')) {
            return $response;
        }
        $release = ReleaseInfo::current();
        if ($release['environment'] === 'prod' && $request->user() === null) {
            return $response;
        }

        return $response->setContent(str_replace('</body>', self::html($release, str_contains($content, 'id="basalto-license-banner"')).'</body>', $content));
    }

    /** @param  array{version: string, commit: string|null, at: string|null, environment: string, label: string}  $release */
    public static function html(array $release, bool $aboveBanner = false): string
    {
        $tone = self::TONES[$release['environment']] ?? '#62676e';
        $text = e($release['version']).($release['at'] !== null ? ' · '.e($release['at']) : '');

        return sprintf('<div id="basalto-release" aria-hidden="true"%s style="position:fixed;left:6px;bottom:%dpx;z-index:2147483645;display:flex;align-items:center;gap:6px;'
            .'padding:2px 8px 2px 2px;border:1px solid #e3e6ea;border-radius:999px;background:rgba(255,255,255,.92);color:#62676e;font:10.5px/16px system-ui,sans-serif;'
            .'pointer-events:none"><span style="padding:0 6px;border-radius:999px;background:%s;color:#fff;font-weight:600;letter-spacing:.04em">%s</span><span>%s</span></div>'
            .'<style>@media (max-width:640px){#basalto-release{bottom:%dpx!important}}@media print{#basalto-release{display:none}}</style>',
            $release['commit'] !== null ? ' title="Commit '.e($release['commit']).'"' : '', $aboveBanner ? 52 : 6, $tone, e($release['label']), $text, $aboveBanner ? 96 : 64);
    }
}
