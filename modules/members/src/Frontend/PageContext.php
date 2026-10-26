<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Frontend;

use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Lava\Runtime\Context;
use Basalto\Modules\Members\Members;
use Illuminate\Http\Request;

/**
 * Wo ein Tag der Mitglieder gerade ausgegeben wird: Site, Sprache und Pfad der Seite, Anfrage. Null ohne Site oder
 * wenn das Modul für die Site aus ist — dann geben die Tags nichts aus (das Starter-Theme zeigt dann kein «Anmelden»).
 */
final readonly class PageContext
{
    public function __construct(public Site $site, public string $lang, public string $path, public Request $request) {}

    public static function from(Context $ctx): ?self
    {
        $site = app(CurrentSite::class)->get();
        if ($site === null || ! app(Members::class)->enabled($site)) {
            return null;
        }
        $page = is_array($ctx->get('page')) ? $ctx->get('page') : [];
        $siteVars = is_array($ctx->get('site')) ? $ctx->get('site') : [];
        $lang = is_string($page['locale'] ?? null) ? $page['locale'] : (is_string($siteVars['language'] ?? null) ? $siteVars['language'] : $site->default_locale);
        $url = is_string($page['url'] ?? null) ? (string) parse_url($page['url'], PHP_URL_PATH) : '';
        $path = $url !== '' ? $url : (is_string($page['path'] ?? null) ? $page['path'] : '/');

        return new self($site, $lang, Html::pagePath($path), request());
    }

    /**
     * Ein Pfad aus den Einstellungen des Tags (z. B. `login: "/mitglieder"`), sonst null.
     *
     * @param  array<string, mixed>  $named
     */
    public static function path(array $named, string $key): ?string
    {
        $value = $named[$key] ?? null;

        return is_string($value) && str_starts_with(trim($value), '/') ? Html::pagePath(trim($value)) : null;
    }
}
