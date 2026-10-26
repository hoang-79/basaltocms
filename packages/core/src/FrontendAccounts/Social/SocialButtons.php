<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Social;

use Basalto\Core\Backend\Backend;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Core\Models\Site;

/**
 * TB 53: Die Knöpfe «Mit Passkey anmelden» und «Mit Google anmelden» usw. über dem Feld für die E-Mail — gewöhnliche
 * Links (die Seite darf aus dem Seiten-Cache kommen), Seite, Sprache und Zweck reisen als Abfrage mit. Der Passkey
 * erscheint erst, wenn das Skript sieht, dass der Browser es kann.
 * Die Gestalt kommt aus den Design-Tokens des Themes, je Anfrage einmal.
 */
final class SocialButtons
{
    public static function render(Site $site, string $lang, string $pagePath, ?string $purpose = null): string
    {
        $providers = SocialConnection::enabledFor($site);
        // Passkey: nur mit Skript und wenn der Browser es kann — bis dahin verborgen
        $passkey = sprintf('<button type="button" class="account-social__knopf" data-konto-passkey data-konto-passkey-zeigen hidden data-optionen="%s" data-anmelden="%s" data-laeuft="%s" data-geht="%s">%s<span>%s</span></button>'
            .'<p class="account-social-fehler" data-konto-passkey-fehler role="alert" hidden></p>',
            Html::e(Html::url(route('basalto.account.passkey.options', [], false), ['_sprache' => $lang])),
            Html::e(Html::url(route('basalto.account.passkey.login', [], false), ['_seite' => $pagePath, '_sprache' => $lang, LoginForm::PURPOSE => $purpose])),
            Html::e(__('Passkey wird geprüft …', [], $lang)), Html::e(__('Mit einem Passkey geht es in diesem Browser nicht. Melden Sie sich mit einem Code per Mail an.', [], $lang)),
            self::KEY, Html::e(__('Mit Passkey anmelden', [], $lang)));
        $links = '';
        foreach ($providers as $provider) {
            $links .= sprintf('<a class="account-social__knopf" href="%s">%s<span>%s</span></a>', Html::e(self::url($provider, $lang, $pagePath, [LoginForm::PURPOSE => $purpose])),
                $provider->icon(), Html::e(__('Mit :provider anmelden', ['provider' => $provider->label()], $lang)));
        }
        // Ohne Anbieter erscheint die Gruppe erst, wenn der Passkey geht
        $only = $providers === [] ? ' data-konto-passkey-zeigen hidden' : '';

        return self::styles().sprintf('<div class="account-social" role="group" aria-label="%s"%s>%s%s</div><p class="account-social__oder"%s><span>%s</span></p>',
            Html::e(__('Anmelden mit einem anderen Konto', [], $lang)), $only, $passkey, $links, $only, Html::e(__('oder mit einem Code per Mail', [], $lang))).self::script();
    }

    /** Das kleine Bündel für Passkeys — je Anfrage einmal. */
    public static function script(): string
    {
        $request = request();
        if ($request->attributes->get('basalto.konto-skript') === true) {
            return '';
        }
        $request->attributes->set('basalto.konto-skript', true);

        return app(Backend::class)->vite()->withEntryPoints(['resources/js/konto.ts'])->toHtml();
    }

    /**
     * Adresse zum Weiterleiten — mit Seite und Sprache für die Rückkehr.
     *
     * @param  array<string, scalar|null>  $extra
     */
    public static function url(SocialProvider $provider, string $lang, string $pagePath, array $extra = []): string
    {
        return Html::url(route('basalto.account.social.redirect', ['provider' => $provider->value], false), ['_seite' => $pagePath, '_sprache' => $lang, ...$extra]);
    }

    public static function styles(): string
    {
        $request = request();
        if ($request->attributes->get('basalto.social-stil') === true) {
            return '';
        }
        $request->attributes->set('basalto.social-stil', true);

        return '<style id="konto-social-stil">'.self::CSS.'</style>';
    }

    /** Schlüssel mit Person — das Zeichen für Passkeys. */
    private const KEY = '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a5 5 0 0 1 5-5h3"/><circle cx="17.5" cy="15.5" r="2.5"/><path d="m19.3 17.3 2.7 2.7M21 19l1-1"/></svg>';

    private const CSS = <<<'CSS'
.account-social{display:grid;gap:8px;margin:0 0 4px}
.account-social .account-social__knopf{font:inherit;cursor:pointer;width:100%}
.account-social .account-social__knopf{display:flex;align-items:center;justify-content:center;gap:10px;min-height:44px;padding:0 16px;border:1px solid var(--color-line,#d9dce0);border-radius:calc(var(--radius,6px) + 4px);background:var(--color-surface,#fff);color:var(--color-text,#23262b);font-weight:600;font-size:.95rem;line-height:1.2;text-decoration:none;transition:background 160ms ease,border-color 160ms ease;touch-action:manipulation}
.account-social .account-social__knopf:hover{border-color:color-mix(in srgb,var(--color-text,#23262b) 35%,var(--color-line,#d9dce0));background:color-mix(in srgb,var(--color-text,#23262b) 5%,var(--color-surface,#fff))}
.account-social .account-social__knopf:focus-visible{outline:2px solid var(--color-focus,var(--color-accent,#b53f1d));outline-offset:2px}
.account-social .account-social__knopf svg{flex:none;display:block}
.account-social__oder{display:flex;align-items:center;gap:12px;margin:14px 0 10px;color:var(--color-quiet,var(--color-muted,#62676e));font-size:.85rem}
.account-social__oder::before,.account-social__oder::after{content:"";flex:1;height:1px;background:var(--color-line,#d9dce0)}
.account-social-fehler{margin:0 0 12px;padding:10px 12px;border-radius:calc(var(--radius,6px) + 2px);background:color-mix(in srgb,#b8232f 10%,var(--color-surface,#fff));color:color-mix(in srgb,#b8232f 58%,var(--color-text,#23262b));font-size:.92rem}
.account-social[hidden],.account-social__oder[hidden],.account-social .account-social__knopf[hidden],.account-social [hidden]{display:none}
CSS;
}
