<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Backend\Backend;
use Basalto\Core\License\LicenseStatus;
use Basalto\Core\Models\LicenseState;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\Trial;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Vendor;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * D-12: Ist die Installation seit {@see LicenseStatus::bannerDays()} Tagen unregistriert, bekommt jede HTML-Seite des Frontends
 * vor `</body>` einen Hinweis. Das Backend bleibt frei (dort steht der Hinweis auf der Übersicht), Sperren gibt es nie.
 * Im SaaS-Modus (TB 12) stattdessen die Fussleiste einer seit 14 Tagen abgelaufenen Testphase ({@see Trial}).
 */
final class ShowLicenseBanner
{
    public function __construct(private readonly Backend $backend) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);
        $path = trim($this->backend->path(), '/');
        if (! $response instanceof Response || $response->getStatusCode() !== 200 || $request->is($path, $path.'/*')) {
            return $response;
        }
        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, '</body>') || ! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }
        try {
            // TB 12 Scheibe 1: Auf der SaaS gilt keine Download-Lizenz — dort zeigt die Fussleiste die abgelaufene Testphase
            $banner = Saas::enabled()
                ? (Trial::frontendBanner(app(CurrentSite::class)->get()) ? Trial::footerHtml() : null)
                : (LicenseState::bannerActive(LicenseStatus::bannerDays()) ? self::html() : null);
        } catch (QueryException) {
            return $response;
        }
        if ($banner === null) {
            return $response;
        }

        return $response->setContent(str_replace('</body>', $banner.'</body>', $content));
    }

    public static function html(): string
    {
        return '<div id="basalto-license-banner" style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;padding:10px 16px;background:#1f1d1c;color:#f5f1ea;font:14px/1.4 system-ui,sans-serif;text-align:center">'
            .e(__('Diese Website läuft mit einer unregistrierten Basalto-Installation.')).' '
            .'<a href="'.e(Vendor::url('lizenz-kaufen')).'" style="color:#ff6a3d;text-decoration:underline">'.e(__('Lizenz auf :vendor', ['vendor' => Vendor::host()])).'</a></div>';
    }
}
