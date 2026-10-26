<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Content\Pages;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Theme\ThemeLoader;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Zustimmung zu den Bedingungen der Community (CR-011 TB 60): Wer das Forum öffnet, sieht zuerst ein Fenster mit den
 * Nutzungsbedingungen und den Community-Richtlinien und muss ausdrücklich zustimmen. Kundenkonten stimmen je Fassung
 * zu (Zeitpunkt im Kundenkonto, dort widerrufbar); ohne gültige Zustimmung zählen sie wie Gäste und können nichts
 * schreiben ({@see VisitorGroups::of()}, Middleware `basalto.community.terms`). Gäste stimmen im Browser zu (Cookie mit
 * Site und Fassung) — sie lesen nur. Eine neue Fassung verlangt die Zustimmung neu — für alle, auch das Team.
 */
final class CommunityTerms
{
    public const COOKIE = 'basalto_community_terms';

    public const REQUIRED = 'community.terms.required';

    public const VERSION = 'community.terms.version';

    /** Seiten der Bedingungen aus dem Seed des Themes — in dieser Reihenfolge, sonst die Rechtsseiten aus `publish.legal`. */
    private const PAGES = ['nutzungsbedingungen', 'community-richtlinien'];

    /** @var array<string, bool> Zustimmung je Site und Konto für diese Anfrage */
    private array $memo = [];

    public function __construct(private readonly Settings $settings, private readonly Pages $pages, private readonly ThemeLoader $themes) {}

    public function required(Site $site): bool
    {
        return (bool) $this->settings->get(self::REQUIRED, true, $site);
    }

    public function version(Site $site): string
    {
        $version = $this->settings->get(self::VERSION, '1', $site);

        return is_scalar($version) && trim((string) $version) !== '' ? trim((string) $version) : '1';
    }

    /** Darf das Konto die Community nutzen? Ohne Pflicht immer; ohne Konto nie (Gäste lesen nur). */
    public function accepted(Site $site, ?Customer $customer): bool
    {
        if (! $this->required($site)) {
            return true;
        }
        if ($customer === null) {
            return false;
        }

        // Auch das Team der Site stimmt zu — jedes Konto, das die Community nutzt
        return $this->memo[$site->id.':'.$customer->id] ??= $this->current($site, $customer) !== null;
    }

    /** Die gültige Zustimmung (aktuelle Fassung, nicht widerrufen). */
    public function current(Site $site, Customer $customer): ?CommunityTermsConsent
    {
        return CommunityTermsConsent::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('customer_id', $customer->id)
            ->where('version', $this->version($site))->whereNull('revoked_at')->latest('accepted_at')->latest('id')->first();
    }

    /** Die letzte Zustimmung, auch widerrufen oder zu einer älteren Fassung — für das Kundenkonto. */
    public function latest(Site $site, Customer $customer): ?CommunityTermsConsent
    {
        return CommunityTermsConsent::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('customer_id', $customer->id)
            ->latest('accepted_at')->latest('id')->first();
    }

    public function accept(Site $site, Customer $customer): CommunityTermsConsent
    {
        $existing = $this->current($site, $customer);
        unset($this->memo[$site->id.':'.$customer->id]);

        return $existing ?? CommunityTermsConsent::query()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'version' => $this->version($site),
            'accepted_at' => CarbonImmutable::now()]);
    }

    /** Widerrufen: alle offenen Zustimmungen des Kontos auf der Site — danach kommt das Fenster wieder. */
    public function revoke(Site $site, Customer $customer): bool
    {
        unset($this->memo[$site->id.':'.$customer->id]);

        return CommunityTermsConsent::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('customer_id', $customer->id)
            ->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]) > 0;
    }

    /** Wert des Cookies für Gäste: Site und Fassung — eine neue Fassung fragt neu. */
    public function cookieValue(Site $site): string
    {
        return $site->id.'.'.preg_replace('/[^A-Za-z0-9._-]/', '-', $this->version($site));
    }

    /**
     * Die Seiten der Bedingungen: Nutzungsbedingungen und Community-Richtlinien aus dem Seed, sonst die Rechtsseiten des
     * Themes (`publish.legal`). Adressen relativ.
     *
     * @return list<array{label: string, url: string}>
     */
    public function links(Site $site, string $locale): array
    {
        $links = $this->seedLinks($site, self::PAGES, $locale);
        if ($links !== []) {
            return $links;
        }
        try {
            $legal = $this->themes->forSite($site)->document->find('publish')?->childrenOf('legal')[0] ?? null;
        } catch (Throwable) {
            return [];
        }

        return $legal === null ? [] : $this->seedLinks($site, array_map(static fn ($property): string => $property->text(), $legal->properties), $locale);
    }

    /**
     * Das Fenster für Seiten der Community — leer ohne Pflicht oder mit gültiger Zustimmung. Für Konten ohne
     * Zustimmung offen; für Gäste verborgen, das Skript öffnet es ohne Cookie der aktuellen Fassung (die Seite bleibt
     * so im Seiten-Cache).
     *
     * @param  string  $hidden  Felder für das Formular (Seite, Sprache, CSRF)
     */
    public function modal(Site $site, ?Customer $customer, string $locale, string $hidden, string $acceptUrl, string $guestUrl, string $leaveUrl): string
    {
        if (! $this->required($site) || $this->accepted($site, $customer)) {
            return '';
        }
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $text, array $replace = []): string => $e((string) __($text, $replace, $locale));
        $links = $this->links($site, $locale);
        $list = '';
        foreach ($links as $link) {
            $list .= sprintf('<li><a href="%s" target="_blank" rel="noopener">%s</a></li>', $e($link['url']), $e($link['label']));
        }
        $guest = $customer === null;
        $action = $guest ? $guestUrl : $acceptUrl;
        $label = $links === [] ? $t('Ich habe die Regeln der Community gelesen und stimme ihnen zu.') : $t('Ich habe die oben verlinkten Bedingungen gelesen und stimme ihnen zu.');

        return '<style id="basalto-bedingungen-stil">'.self::CSS.'</style>'
            .sprintf('<div class="basalto-bedingungen" data-community-terms data-cookie="%s" data-wert="%s"%s>', self::COOKIE, $e($this->cookieValue($site)), $guest ? ' hidden' : '')
            .'<div class="basalto-bedingungen__grund"></div><div class="basalto-bedingungen__rahmen" role="dialog" aria-modal="true" aria-labelledby="basalto-bedingungen-titel" aria-describedby="basalto-bedingungen-text">'
            .sprintf('<form class="basalto-bedingungen__karte" method="post" action="%s" tabindex="-1">%s', $e($action), $hidden)
            .sprintf('<div class="basalto-bedingungen__kopf"><span class="basalto-bedingungen__symbol" aria-hidden="true">%s</span><div><h2 id="basalto-bedingungen-titel">%s</h2><p id="basalto-bedingungen-text">%s</p></div></div>',
                self::ICON, $t('Willkommen in der Community'), $t('Bevor Sie die Community nutzen: Bitte lesen Sie die Bedingungen und stimmen Sie ihnen zu. Ohne Zustimmung lässt sich die Community nicht nutzen.'))
            .($list === '' ? '' : '<ul class="basalto-bedingungen__links">'.$list.'</ul>')
            .sprintf('<label class="basalto-bedingungen__haken"><input type="checkbox" name="zustimmung" value="1" required> <span>%s</span></label>', $label)
            .sprintf('<div class="basalto-bedingungen__knoepfe"><a href="%s">%s</a><button type="submit" class="basalto-bedingungen__haupt">%s</button></div>', $e($leaveUrl), $t('Nicht jetzt'), $t('Zustimmen und weiter'))
            .sprintf('<p class="basalto-bedingungen__hinweis">%s</p>', $guest ? $t('Diese Zustimmung merkt sich Ihr Browser. Zum Mitschreiben melden Sie sich an — dann stimmen Sie für Ihr Konto zu.')
                : $t('Den Zeitpunkt Ihrer Zustimmung sehen Sie im Kundenkonto — dort können Sie sie auch widerrufen.'))
            .'</form></div></div>'
            .'<script>'.self::SCRIPT.'</script>';
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{label: string, url: string}>
     */
    private function seedLinks(Site $site, array $keys, string $locale): array
    {
        $links = [];
        foreach ($keys as $key) {
            $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:page:%s', $site->id, $key))->toString();
            $entry = Entry::query()->withoutGlobalScopes()->where('uuid', $uuid)->whereNull('deleted_at')->first();
            $path = $entry === null ? null : $this->pages->urlOf($entry, $locale);
            $title = $entry?->resolve($locale)?->data['title'] ?? null;
            if (is_string($path) && is_string($title) && $title !== '') {
                $links[] = ['label' => $title, 'url' => $path];
            }
        }

        return $links;
    }

    private const ICON = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';

    /**
     * Ohne Cookie der aktuellen Fassung öffnet sich das Fenster für Gäste, die Community dahinter wird `inert`. Gäste
     * stimmen ohne Neuladen zu (Cookie im Browser); Konten schicken das Formular ab.
     */
    private const SCRIPT = <<<'JS'
(function(){var d=document,root=d.querySelector('[data-community-terms]');if(!root){return;}
var name=root.getAttribute('data-cookie'),value=root.getAttribute('data-wert'),guest=root.hasAttribute('hidden');
function get(n){var m=d.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?decodeURIComponent(m[1]):null;}
function block(on){d.querySelectorAll('.forum').forEach(function(f){if(on){f.setAttribute('inert','');}else{f.removeAttribute('inert');}});}
if(guest&&get(name)===value){root.remove();return;}
root.hidden=false;block(true);var card=root.querySelector('form');setTimeout(function(){var first=root.querySelector('a,input');(first||card).focus();},0);
if(guest){card.addEventListener('submit',function(ev){ev.preventDefault();if(!card.checkValidity()){card.reportValidity();return;}
d.cookie=name+'='+value+'; Max-Age=31536000; Path=/; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');block(false);root.remove();});}
})();
JS;

    private const CSS = <<<'CSS'
.basalto-bedingungen[hidden]{display:none}
.basalto-bedingungen{--bb-text:var(--color-text,#23262b);--bb-surface:var(--color-surface,#fff);--bb-muted:var(--color-quiet,var(--color-muted,#62676e));--bb-line:var(--color-line,#e3e6ea);--bb-accent:var(--color-accent,#b53f1d);--bb-accent-text:var(--color-accent-text,#fff);--bb-r:calc(var(--radius,6px) + 8px);position:relative;z-index:2147483000;font:inherit;line-height:1.5;color:var(--bb-text)}
.basalto-bedingungen *{box-sizing:border-box}
.basalto-bedingungen__grund{position:fixed;inset:0;background:rgb(0 0 0 / 55%)}
.basalto-bedingungen__rahmen{position:fixed;inset:0;display:grid;place-items:center;padding:16px}
.basalto-bedingungen__karte{width:100%;max-width:34rem;max-height:calc(100dvh - 32px);overflow:auto;scrollbar-width:none;margin:0;padding:24px;border:1px solid var(--bb-line);border-top:3px solid var(--bb-accent);border-radius:calc(var(--bb-r) + 4px);background:var(--bb-surface);box-shadow:0 25px 50px -12px rgb(0 0 0 / 35%)}
.basalto-bedingungen__karte::-webkit-scrollbar{display:none}
.basalto-bedingungen__kopf{display:flex;align-items:flex-start;gap:12px}
.basalto-bedingungen__symbol{display:grid;place-items:center;flex:none;width:40px;height:40px;border-radius:var(--bb-r);background:color-mix(in srgb,var(--bb-accent) 15%,transparent);color:var(--bb-accent)}
.basalto-bedingungen h2{margin:0;font-size:1.25rem;font-weight:700;line-height:1.3;color:var(--bb-text)}
.basalto-bedingungen__kopf p{margin:6px 0 0;font-size:.9375rem;color:var(--bb-muted)}
.basalto-bedingungen__links{display:grid;gap:8px;margin:18px 0 0;padding:0;list-style:none}
.basalto-bedingungen__links a{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:44px;padding:10px 14px;border:1px solid var(--bb-line);border-radius:var(--bb-r);color:var(--bb-text);font-weight:600;text-decoration:none;background:color-mix(in srgb,var(--bb-text) 3%,var(--bb-surface))}
.basalto-bedingungen__links a::after{content:"↗";color:var(--bb-accent)}
.basalto-bedingungen__links a:hover{border-color:var(--bb-accent)}
.basalto-bedingungen__haken{display:flex;align-items:flex-start;gap:10px;margin-top:18px;font-size:.9375rem;cursor:pointer}
.basalto-bedingungen__haken input{flex:none;width:20px;height:20px;margin:2px 0 0;accent-color:var(--bb-accent)}
.basalto-bedingungen__knoepfe{display:flex;flex-direction:column-reverse;gap:12px;margin-top:20px}
@media (min-width:520px){.basalto-bedingungen__knoepfe{flex-direction:row;justify-content:flex-end}}
.basalto-bedingungen__knoepfe a,.basalto-bedingungen__knoepfe button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin:0;padding:10px 18px;border:1px solid color-mix(in srgb,var(--bb-text) 22%,var(--bb-line));border-radius:var(--bb-r);background:var(--bb-surface);color:var(--bb-text);font:inherit;font-size:.875rem;font-weight:600;text-decoration:none;cursor:pointer}
.basalto-bedingungen__knoepfe .basalto-bedingungen__haupt{border-color:var(--bb-accent);background:var(--bb-accent);color:var(--bb-accent-text)}
.basalto-bedingungen__knoepfe .basalto-bedingungen__haupt:hover{background:color-mix(in srgb,var(--bb-accent) 85%,var(--bb-text))}
.basalto-bedingungen__hinweis{margin:16px 0 0;font-size:.8125rem;color:var(--bb-muted)}
.basalto-bedingungen :focus-visible{outline:2px solid var(--color-focus,var(--bb-accent));outline-offset:2px}
.basalto-bedingungen .basalto-bedingungen__karte:focus{outline:none}
CSS;
}
