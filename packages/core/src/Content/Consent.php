<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Analytics\Statistics;

/**
 * Zustimmung im Frontend (M-14, N-05, E-11; seit TB 54 wie bei Fristo): Ohne Zustimmung lädt keine Fremdressource.
 * Einbettungen (YouTube, Vimeo, Karten) stehen als Platzhalter mit Knopf — ein Klick lädt genau diese eine.
 *
 * Die **Cookie-Einstellungen** erscheinen, wenn die Site Besuche zählt (Statistik) oder das Theme weitere Dienste
 * erklärt (`publish.cookie: consent`, Skripte mit `type="text/plain" data-consent`): Kategorien «Notwendig» (immer
 * aktiv), «Statistik» und «Externe Inhalte», je mit Schalter (ab Werk aus — vorab angekreuzt wäre keine Einwilligung),
 * dazu «Nur notwendige», «Auswahl speichern» und «Alle akzeptieren». Die Wahl steht im Cookie `basalto_consent`;
 * jeder Link auf `#cookie-einstellungen` öffnet die Einstellungen wieder. Alles inline, keine Bibliothek, kein Skript
 * von einem fremden Server; die Seite bleibt für den Seiten-Cache dieselbe — entschieden wird im Browser.
 */
final class Consent
{
    /** Kategorien neben «Notwendig». */
    public const EXTERNAL = 'extern';

    /** Fügt Script (und bei Bedarf die Einstellungen) vor `</body>` ein — nur, wenn die Seite es braucht. */
    public static function inject(string $html, string $mode, string $lang, bool $statistics = false, ?string $privacyUrl = null): string
    {
        $embeds = str_contains($html, 'class="embed-consent"');
        $external = $mode === 'consent';
        if (! $embeds && ! $external && ! $statistics) {
            return $html;
        }
        $out = $external || $statistics ? self::banner($lang, $statistics, $external, $privacyUrl) : '';
        $out .= '<script>'.self::script(self::t('Eingebetteter Inhalt', $lang)).'</script>';

        return str_contains($html, '</body>') ? (string) preg_replace('#</body>#i', str_replace(['\\', '$'], ['\\\\', '\\$'], $out).'</body>', $html, 1) : $html.$out;
    }

    private static function banner(string $lang, bool $statistics, bool $external, ?string $privacyUrl): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $text): string => $e(self::t($text, $lang));
        $category = static fn (string $key, string $name, string $text): string => sprintf(
            '<div class="basalto-cookies__kategorie"><div><p class="basalto-cookies__name">%s</p><p class="basalto-cookies__text">%s</p></div>'
            .'<button type="button" role="switch" aria-checked="false" class="basalto-cookies__schalter" data-kategorie="%s" aria-label="%s"><span></span></button></div>',
            $t($name), $t($text), $key, $t($name));
        $more = $privacyUrl === null ? '' : sprintf(' <a href="%s">%s</a>', $e($privacyUrl), $t('Mehr erfahren'));

        return '<style id="basalto-cookies-stil">'.self::CSS.'</style>'
            .sprintf('<div class="basalto-cookies" data-cookie-banner hidden><div class="basalto-cookies__grund"></div><div class="basalto-cookies__rahmen" role="dialog" aria-modal="true" aria-labelledby="basalto-cookies-titel">'
                .'<div class="basalto-cookies__karte" tabindex="-1"><div class="basalto-cookies__kopf"><span class="basalto-cookies__symbol" aria-hidden="true">%s</span><div><h2 id="basalto-cookies-titel">%s</h2><p>%s%s</p></div></div>'
                .'<div class="basalto-cookies__kategorien"><div class="basalto-cookies__kategorie"><div><p class="basalto-cookies__name">%s</p><p class="basalto-cookies__text">%s</p></div><span class="basalto-cookies__aktiv">%s</span></div>%s%s</div>'
                .'<div class="basalto-cookies__knoepfe"><button type="button" data-wahl="notwendig">%s</button><button type="button" data-wahl="auswahl">%s</button><button type="button" data-wahl="alle" class="basalto-cookies__haupt">%s</button></div>'
                .'</div></div></div>',
                self::ICON, $t('Cookie-Einstellungen'), $t('Wir verwenden Cookies, damit die Website funktioniert, und — mit Ihrer Einwilligung — um zu verstehen, wie sie genutzt wird.'), $more,
                $t('Notwendig'), $t('Sitzung, Schutz vor fremden Anfragen, Sprache und Darstellung. Ohne sie funktioniert die Website nicht.'), $t('Aktiv'),
                $statistics ? $category(Statistics::CATEGORY, 'Statistik', 'Zusammengefasste Zahlen zur Nutzung, damit wir die Website verbessern können. Nur auf dieser Website, keine Weitergabe an Dritte.') : '',
                $external ? $category(self::EXTERNAL, 'Externe Inhalte', 'Dienste anderer Anbieter, die diese Website einbindet — sie laden erst mit Ihrer Zustimmung.') : '',
                $t('Nur notwendige'), $t('Auswahl speichern'), $t('Alle akzeptieren'));
    }

    private static function t(string $text, string $lang): string
    {
        $value = __($text, [], $lang);

        return is_string($value) ? $value : $text;
    }

    private static function script(string $frameTitle): string
    {
        $title = json_encode($frameTitle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        $visitor = Statistics::VISITOR_COOKIE;
        $session = Statistics::SESSION_COOKIE;
        $cookie = Statistics::CONSENT_COOKIE;

        return <<<JS
(function(){
  var d=document,w=window,root=d.querySelector('[data-cookie-banner]'),last=null;
  var secure=location.protocol==='https:'?'; Secure':'';
  function set(n,v,age){d.cookie=n+'='+v+'; Max-Age='+age+'; Path=/; SameSite=Lax'+secure;}
  function id(){var c='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',a=new Uint8Array(32),s='';(w.crypto||w.msCrypto).getRandomValues(a);for(var i=0;i<32;i++){s+=c[a[i]%62];}return s;}
  function get(n){var m=d.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?decodeURIComponent(m[1]):null;}
  function read(){var v=get('{$cookie}');if(v===null){return null;}if(v==='yes'){return ['statistik','extern'];}if(v==='no'||v==='notwendig'){return [];}return v.split(',');}
  function apply(list){
    if(list.indexOf('statistik')>-1&&!navigator.globalPrivacyControl){if(!/^[A-Za-z0-9]{16,40}$/.test(get('{$visitor}')||'')){set('{$visitor}',id(),31536000);}set('{$session}',/^[A-Za-z0-9]{16,40}$/.test(get('{$session}')||'')?get('{$session}'):id(),1800);}
    else{set('{$visitor}','',0);set('{$session}','',0);}
    if(list.indexOf('extern')>-1){run();}
  }
  function save(list){set('{$cookie}',list.length?list.join(','):'notwendig',31536000);apply(list);close();}
  function open(){if(!root){return;}var list=read()||[];root.querySelectorAll('[data-kategorie]').forEach(function(s){s.setAttribute('aria-checked',list.indexOf(s.getAttribute('data-kategorie'))>-1?'true':'false');});
    last=d.activeElement;root.hidden=false;var k=root.querySelector('.basalto-cookies__karte');if(k){k.focus();}}
  function close(){if(root){root.hidden=true;}if(last&&last.focus){last.focus();}}
  d.addEventListener('click',function(e){
    var b=e.target.closest&&e.target.closest('.embed-consent');
    if(b){var box=b.closest('.embed');var src=box&&box.getAttribute('data-src');
      if(src&&/^https:\/\//.test(src)){var f=d.createElement('iframe');f.src=src;f.title={$title};f.loading='lazy';f.allowFullscreen=true;
        f.setAttribute('allow','fullscreen; picture-in-picture');f.setAttribute('referrerpolicy','strict-origin-when-cross-origin');box.replaceChildren(f);box.classList.add('embed-loaded');}
      return;}
    var o=e.target.closest&&e.target.closest('a[href$="#cookie-einstellungen"],[data-cookie-einstellungen]');
    if(o&&root){e.preventDefault();open();return;}
    var s=e.target.closest&&e.target.closest('[data-kategorie]');
    if(s){s.setAttribute('aria-checked',s.getAttribute('aria-checked')==='true'?'false':'true');return;}
    var c=e.target.closest&&e.target.closest('[data-wahl]');
    if(c&&root){var how=c.getAttribute('data-wahl'),all=[],chosen=[];
      root.querySelectorAll('[data-kategorie]').forEach(function(x){all.push(x.getAttribute('data-kategorie'));if(x.getAttribute('aria-checked')==='true'){chosen.push(x.getAttribute('data-kategorie'));}});
      save(how==='alle'?all:how==='auswahl'?chosen:[]);}
  });
  d.addEventListener('keydown',function(e){if(!root||root.hidden||e.key!=='Tab'){return;}var f=root.querySelectorAll('a[href],button');if(!f.length){return;}
    var a=f[0],z=f[f.length-1],k=root.querySelector('.basalto-cookies__karte');if(e.shiftKey&&(d.activeElement===a||d.activeElement===k)){e.preventDefault();z.focus();}else if(!e.shiftKey&&d.activeElement===z){e.preventDefault();a.focus();}});
  function run(){d.querySelectorAll('script[type="text/plain"][data-consent]').forEach(function(s){var n=d.createElement('script');
    for(var i=0;i<s.attributes.length;i++){var a=s.attributes[i];if(a.name!=='type'){n.setAttribute(a.name,a.value);}}n.text=s.text;s.replaceWith(n);});}
  if(root){d.querySelectorAll('[data-cookie-link]').forEach(function(x){x.hidden=false;});}
  var list=read();
  if(list!==null){apply(list);}else if(root){open();}
  if(location.hash==='#cookie-einstellungen'){open();}
})();
JS;
    }

    /** Das Keks-Zeichen (wie bei Fristo). */
    private const ICON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17v.01"/><path d="M7 14v.01"/></svg>';

    private const CSS = <<<'CSS'
.basalto-cookies[hidden]{display:none}
.basalto-cookies{--bc-text:var(--color-text,#23262b);--bc-surface:var(--color-surface,#fff);--bc-muted:var(--color-quiet,var(--color-muted,#62676e));--bc-line:var(--color-line,#e3e6ea);--bc-accent:var(--color-accent,#b53f1d);--bc-accent-text:var(--color-accent-text,#fff);--bc-r:calc(var(--radius,6px) + 8px);position:relative;z-index:2147483647;font:inherit;line-height:1.5;color:var(--bc-text)}
.basalto-cookies *{box-sizing:border-box}
.basalto-cookies__grund{position:fixed;inset:0;background:rgb(0 0 0 / 50%)}
.basalto-cookies__rahmen{position:fixed;inset:auto 0 0;padding:16px}
@media (min-width:640px){.basalto-cookies__rahmen{padding:24px}}
.basalto-cookies__karte{max-width:42rem;max-height:calc(100dvh - 32px);overflow:auto;margin:0 auto;padding:24px;border:1px solid var(--bc-line);border-radius:calc(var(--bc-r) + 4px);background:var(--bc-surface);box-shadow:0 25px 50px -12px rgb(0 0 0 / 35%)}
.basalto-cookies__kopf{display:flex;align-items:flex-start;gap:12px}
.basalto-cookies__symbol{display:grid;place-items:center;flex:none;width:40px;height:40px;border-radius:var(--bc-r);background:color-mix(in srgb,var(--bc-accent) 15%,transparent);color:var(--bc-accent)}
.basalto-cookies h2{margin:0;font-size:1.125rem;font-weight:700;line-height:1.3;color:var(--bc-text)}
.basalto-cookies__kopf p{margin:4px 0 0;font-size:.875rem;color:var(--bc-muted)}
.basalto-cookies__kopf a{color:inherit;text-decoration:underline}
.basalto-cookies__kategorien{display:grid;gap:12px;margin-top:20px}
.basalto-cookies__kategorie{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border:1px solid var(--bc-line);border-radius:var(--bc-r);background:color-mix(in srgb,var(--bc-text) 3%,var(--bc-surface))}
.basalto-cookies__name{margin:0;font-size:.875rem;font-weight:600;color:var(--bc-text)}
.basalto-cookies__text{margin:2px 0 0;font-size:.75rem;color:var(--bc-muted)}
.basalto-cookies__aktiv{flex:none;padding:2px 10px;border-radius:999px;background:color-mix(in srgb,#2a744a 14%,var(--bc-surface));color:color-mix(in srgb,#2a744a 60%,var(--bc-text));font-size:.75rem;font-weight:600}
.basalto-cookies__schalter{position:relative;flex:none;width:44px;height:24px;margin:0;padding:0;border:2px solid transparent;border-radius:999px;background:color-mix(in srgb,var(--bc-text) 25%,var(--bc-surface));cursor:pointer;transition:background .2s}
.basalto-cookies__schalter span{position:absolute;top:0;left:0;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgb(0 0 0 / 25%);transition:transform .2s}
.basalto-cookies__schalter[aria-checked=true]{background:var(--bc-accent)}
.basalto-cookies__schalter[aria-checked=true] span{transform:translateX(20px)}
.basalto-cookies__knoepfe{display:flex;flex-direction:column;gap:12px;margin-top:20px}
@media (min-width:640px){.basalto-cookies__knoepfe{flex-direction:row}}
.basalto-cookies__knoepfe button{flex:1;min-height:44px;margin:0;padding:10px 16px;border:1px solid color-mix(in srgb,var(--bc-text) 22%,var(--bc-line));border-radius:var(--bc-r);background:var(--bc-surface);color:var(--bc-text);font:inherit;font-size:.875rem;font-weight:600;cursor:pointer;transition:background .15s}
.basalto-cookies__knoepfe button:hover{background:color-mix(in srgb,var(--bc-text) 5%,var(--bc-surface))}
.basalto-cookies__knoepfe .basalto-cookies__haupt{border-color:var(--bc-accent);background:var(--bc-accent);color:var(--bc-accent-text)}
.basalto-cookies__knoepfe .basalto-cookies__haupt:hover{background:color-mix(in srgb,var(--bc-accent) 85%,var(--bc-text))}
.basalto-cookies :focus-visible{outline:2px solid var(--color-focus,var(--bc-accent));outline-offset:2px}
@media (prefers-reduced-motion:reduce){.basalto-cookies *{transition:none}}
.basalto-cookies .basalto-cookies__karte:focus,.basalto-cookies .basalto-cookies__karte:focus-visible{outline:none}
CSS;
}
