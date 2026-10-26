<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Lava\Runtime\Context;
use Illuminate\Http\Request;

/**
 * Wo das Kundenkonto gerade ausgegeben wird: Site, Sprache und Pfad der Seite, Anfrage, angemeldetes Konto (oder null)
 * und die Einstellungen des Tags (`buy`, `entry`, `catalog`, `start` — Pfade auf Seiten der Site).
 */
final readonly class AccountPage
{
    /** @param  array<string, mixed>  $settings */
    public function __construct(public Site $site, public string $lang, public string $path, public Request $request, public ?Customer $customer, public array $settings = []) {}

    /** @param  array<string, mixed>  $named */
    public static function from(Context $ctx, array $named, ?Request $request = null): ?self
    {
        $site = app(CurrentSite::class)->get();
        if ($site === null) {
            return null;
        }
        $page = is_array($ctx->get('page')) ? $ctx->get('page') : [];
        $siteVars = is_array($ctx->get('site')) ? $ctx->get('site') : [];
        $lang = is_string($page['locale'] ?? null) ? $page['locale'] : (is_string($siteVars['language'] ?? null) ? $siteVars['language'] : $site->default_locale);
        $url = is_string($page['url'] ?? null) ? (string) parse_url($page['url'], PHP_URL_PATH) : '';
        $path = $url !== '' ? $url : (is_string($page['path'] ?? null) ? $page['path'] : '/');

        return new self($site, $lang, Html::pagePath($path), $request ?? request(), CustomerAuth::current(), $named);
    }

    /** Ein Pfad aus den Einstellungen des Tags, sonst die Vorgabe (leer: nicht gesetzt). */
    public function setting(string $key, string $default = ''): string
    {
        $value = $this->settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Ein Text der Oberfläche in der Sprache der Seite.
     *
     * @param  array<string, string|int>  $replace
     */
    public function t(string $text, array $replace = []): string
    {
        $value = __($text, $replace, $this->lang);

        // Ein Text wie «Backend» trifft sonst eine Sprachdatei (backend.php) und kommt als Liste zurück
        return is_string($value) ? $value : $text;
    }

    /** Der Anker eines Bereichs im Konto. */
    public static function anchor(string $key): string
    {
        return 'konto-'.$key;
    }
}
