<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

/**
 * Die Gestalt des Kundenkontos (TB 48, neu geordnet in TB 63) aus den Design-Tokens des Themes — dieselbe Sprache wie
 * das Forum: Sechsecke, Lava als Signal, ein kompakter dunkler Kopf, links die Bereiche, rechts einer zur Zeit. Erfolg, Warnung und Fehler mischen ihren Farbton mit
 * der Textfarbe des Themes (hell und dunkel ohne eigene Regeln). Je Anfrage nur einmal in der Seite.
 */
final class AccountStyles
{
    public static function once(): string
    {
        $request = request();
        if ($request->attributes->get('basalto.konto-stil') === true) {
            return '';
        }
        $request->attributes->set('basalto.konto-stil', true);

        return '<style id="konto-stil">'.self::CSS.'</style>';
    }

    private const CSS = <<<'CSS'
.konto{--konto-text:var(--color-text,#23262b);--konto-bg:var(--color-background,#f6f4f0);--konto-surface:var(--color-surface,#fff);--konto-muted:var(--color-muted,#3b3f46);--konto-quiet:var(--color-quiet,var(--color-muted,#62676e));--konto-line:var(--color-line,#e3e6ea);--konto-accent:var(--color-accent,#b53f1d);--konto-accent-text:var(--color-accent-text,#fff);--konto-link:var(--color-link,var(--color-accent,#b53f1d));--konto-lava:var(--color-marker,var(--color-lava,#e4572e));--konto-lava-text:var(--color-marker-text,var(--color-lava-text,#fff));--konto-focus:var(--color-focus,var(--color-accent,#b53f1d));--konto-dark:var(--color-dark,#23262b);--konto-dark-text:var(--color-dark-text,#f6f4f0);--konto-dark-muted:var(--color-dark-muted,#b5bac0);--konto-ok:color-mix(in srgb,#2a744a 62%,var(--konto-text));--konto-ok-bg:color-mix(in srgb,#2a744a 12%,var(--konto-surface));--konto-warn:color-mix(in srgb,#8f5c00 55%,var(--konto-text));--konto-warn-bg:color-mix(in srgb,#d9a606 15%,var(--konto-surface));--konto-err:color-mix(in srgb,#b8232f 58%,var(--konto-text));--konto-err-bg:color-mix(in srgb,#b8232f 10%,var(--konto-surface));--konto-hover:color-mix(in srgb,var(--konto-text) 5%,var(--konto-surface));--konto-sanft:color-mix(in srgb,var(--konto-text) 4%,var(--konto-bg));--konto-r:calc(var(--radius,6px) + 4px);--konto-r-gross:calc(var(--radius,6px) * 2 + 6px);--konto-schatten:0 1px 2px rgb(0 0 0 / 5%),0 14px 36px -22px rgb(0 0 0 / 35%);--konto-hex:polygon(25% 5%,75% 5%,100% 50%,75% 95%,25% 95%,0 50%);--konto-tempo:180ms cubic-bezier(.2,.7,.3,1);display:grid;gap:20px;color:var(--konto-text);line-height:1.5;text-align:left}
.konto *,.konto *::before,.konto *::after{box-sizing:border-box}
.konto :focus-visible{outline:2px solid var(--konto-focus);outline-offset:2px}
.konto h2,.konto h3,.konto p,.konto ul,.konto ol,.konto dl,.konto details,.konto form,.konto figure,.konto pre{margin:0}
.konto ul,.konto ol{padding:0;list-style:none}
.konto a{color:var(--konto-link)}
.konto .konto-icon{display:inline-block;width:20px;height:20px;flex:none;vertical-align:middle}
.konto .konto-icon--klein{width:16px;height:16px;vertical-align:-3px}
.konto .visually-hidden,.konto .sr-only{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
/* Knöpfe */
.konto .konto-knopf{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;margin:0;padding:0 16px;border:1px solid var(--konto-line);border-radius:var(--konto-r);background:var(--konto-surface);color:var(--konto-text);font:inherit;font-size:.9rem;font-weight:600;line-height:1.2;text-decoration:none;white-space:nowrap;cursor:pointer;transition:background var(--konto-tempo),border-color var(--konto-tempo),transform var(--konto-tempo);touch-action:manipulation}
.konto .konto-knopf:hover{border-color:color-mix(in srgb,var(--konto-text) 35%,var(--konto-line));background:var(--konto-hover)}
.konto .konto-knopf:active{transform:scale(.97)}
.konto .konto-knopf--lava{background:var(--konto-accent);border-color:var(--konto-accent);color:var(--konto-accent-text)}
.konto .konto-knopf--lava:hover{background:color-mix(in srgb,var(--konto-accent) 84%,var(--konto-text));border-color:transparent}
.konto .konto-knopf--dunkel{background:var(--konto-text);border-color:var(--konto-text);color:var(--konto-surface)}
.konto .konto-knopf--dunkel:hover{background:color-mix(in srgb,var(--konto-text) 85%,var(--konto-surface))}
.konto .konto-knopf--leise{border-color:transparent;background:transparent;color:var(--konto-quiet)}
.konto .konto-knopf--leise:hover{border-color:transparent;background:var(--konto-hover);color:var(--konto-text)}
.konto .konto-knopf--gefahr{color:var(--konto-err);border-color:color-mix(in srgb,var(--konto-err) 35%,var(--konto-line))}
.konto .konto-knopf--gefahr:hover{background:var(--konto-err-bg)}
.konto .konto-knopf[disabled]{opacity:.5;cursor:not-allowed}
.konto .konto-knopf.ist-kopiert{border-color:var(--konto-ok);color:var(--konto-ok)}
.konto-knoepfe{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
/* Sechseck */
.konto-sechseck{position:relative;display:grid;place-items:center;flex:none;width:44px;height:44px;color:var(--konto-link)}
.konto-sechseck::before{content:"";position:absolute;inset:0;clip-path:var(--konto-hex);background:color-mix(in srgb,var(--konto-lava) 13%,var(--konto-surface))}
.konto-sechseck .konto-icon{position:relative;width:20px;height:20px}
.konto-sechseck--klein{width:34px;height:34px}.konto-sechseck--klein .konto-icon{width:16px;height:16px}
.konto-sechseck--lava{color:var(--konto-lava-text,#fff)}.konto-sechseck--lava::before{background:var(--konto-lava)}
.konto-sechseck--warn{color:var(--konto-warn)}.konto-sechseck--warn::before{background:var(--konto-warn-bg)}
.konto-sechseck--ok{color:var(--konto-ok)}.konto-sechseck--ok::before{background:var(--konto-ok-bg)}
.konto-sechseck--dunkel{color:var(--konto-lava)}.konto-sechseck--dunkel::before{background:var(--konto-dark)}
.konto-sechseck--blau{color:color-mix(in srgb,#2b5ea7 75%,var(--konto-text))}.konto-sechseck--blau::before{background:color-mix(in srgb,#2b5ea7 13%,var(--konto-surface))}
.konto-sechseck--lila{color:color-mix(in srgb,#7a52a6 75%,var(--konto-text))}.konto-sechseck--lila::before{background:color-mix(in srgb,#7a52a6 13%,var(--konto-surface))}
.konto-sechseck--grau{color:var(--konto-muted)}.konto-sechseck--grau::before{background:var(--konto-sanft)}
.konto-sechseck--hell{color:var(--konto-lava)}.konto-sechseck--hell::before{background:color-mix(in srgb,var(--konto-dark-text) 10%,transparent)}
/* Chips */
.konto-chip{display:inline-flex;align-items:center;gap:5px;min-height:24px;padding:1px 9px;border:1px solid var(--konto-line);border-radius:999px;background:var(--konto-surface);color:var(--konto-muted);font-size:.74rem;font-weight:700;line-height:1.3;white-space:nowrap}
.konto-chip .konto-icon{width:13px;height:13px}
.konto-chip--ok{background:var(--konto-ok-bg);border-color:transparent;color:var(--konto-ok)}
.konto-chip--warn{background:var(--konto-warn-bg);border-color:transparent;color:var(--konto-warn)}
.konto-chip--fehler{background:var(--konto-err-bg);border-color:transparent;color:var(--konto-err)}
.konto-chip--lava{background:var(--konto-accent);border-color:transparent;color:var(--konto-accent-text)}
.konto-chip--dunkel{background:var(--konto-text);border-color:transparent;color:var(--konto-surface)}
/* Meldungen */
.konto-meldung,.konto .licensing-status,.konto .account-status{margin:0;padding:12px 16px;border:1px solid color-mix(in srgb,var(--konto-ok) 40%,var(--konto-line));border-radius:var(--konto-r-gross);background:var(--konto-ok-bg);color:var(--konto-ok);font-weight:600}
.konto-meldung--warn{border-color:color-mix(in srgb,var(--konto-warn) 40%,var(--konto-line));background:var(--konto-warn-bg);color:var(--konto-warn)}
/* Kopf (TB 63): kompakt, dunkel, Abmelden abgesetzt */
.konto-held{position:relative;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 20px;padding:18px 20px;border-radius:calc(var(--konto-r-gross) + 4px);background:radial-gradient(circle at 92% 0%,color-mix(in srgb,var(--konto-lava,#e4572e) 34%,transparent),transparent 42%),linear-gradient(125deg,var(--konto-dark),color-mix(in srgb,var(--konto-dark) 88%,#4a4f57));color:var(--konto-dark-text);overflow:hidden;box-shadow:var(--konto-schatten)}
.konto-held__person{display:flex;align-items:center;gap:14px;min-width:0;flex:1 1 18rem}
.konto-held__person>div{display:grid;gap:2px;min-width:0}
.konto-avatar{display:inline-grid;place-items:center;flex:none;width:56px;height:56px;clip-path:var(--konto-hex);object-fit:cover;background:linear-gradient(140deg,#5a616b,#2c3036);color:#fff;font-size:1.1rem;font-weight:700;letter-spacing:.02em}
.konto-avatar--f1{background:linear-gradient(140deg,#d0643c,#8f2f14)}.konto-avatar--f2{background:linear-gradient(140deg,#4f8c70,#24503e)}.konto-avatar--f3{background:linear-gradient(140deg,#4f7fb8,#24477a)}.konto-avatar--f4{background:linear-gradient(140deg,#9363b0,#55306d)}.konto-avatar--f5{background:linear-gradient(140deg,#b98336,#704a17)}.konto-avatar--f6{background:linear-gradient(140deg,#6b7883,#38434c)}
.konto-held__gruss{color:var(--konto-dark-text);font-size:clamp(1.2rem,1.05rem + .7vw,1.55rem);line-height:1.2;letter-spacing:-.015em;text-wrap:balance}
.konto-held__meta{color:var(--konto-dark-muted);font-size:.86rem;overflow-wrap:anywhere}
.konto-held__knoepfe{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.konto-held .konto-knopf{min-height:40px;padding:0 14px;font-size:.86rem}
.konto-held .konto-knopf--hell{background:transparent;border-color:color-mix(in srgb,var(--konto-dark-text) 24%,transparent);color:var(--konto-dark-text)}
.konto-held .konto-knopf--hell:hover{background:color-mix(in srgb,var(--konto-dark-text) 10%,transparent)}
.konto-held .konto-knopf--lava{background:var(--konto-lava);border-color:var(--konto-lava);color:#15171a}
.konto-held .konto-knopf--lava:hover{background:color-mix(in srgb,var(--konto-lava) 85%,#fff)}
.konto-held .konto-knopf--leise{border-color:transparent;color:var(--konto-dark-muted)}
.konto-abmelden{display:inline-flex;padding-left:8px;margin-left:4px;border-left:1px solid color-mix(in srgb,var(--konto-dark-text) 18%,transparent)}
/* Seitenleiste und ein Bereich zur Zeit (TB 63) */
.konto-app{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;align-items:start}
.konto-inhalt{display:grid;gap:16px;min-width:0}
.konto-nav ul{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,9.5rem),1fr));gap:6px}
.konto-nav__punkt{position:relative;display:flex;align-items:center;gap:10px;min-height:44px;padding:8px 12px;border:1px solid var(--konto-line);border-radius:var(--konto-r);background:var(--konto-surface);color:var(--konto-muted)!important;font-size:.92rem;font-weight:600;line-height:1.25;text-decoration:none;transition:background var(--konto-tempo),border-color var(--konto-tempo),color var(--konto-tempo)}
.konto-nav__punkt .konto-icon{color:var(--konto-quiet)}
.konto-nav__punkt:hover{background:var(--konto-hover);color:var(--konto-text)!important}
.konto-nav__punkt.ist-aktiv{border-color:var(--konto-text);background:var(--konto-text);color:var(--konto-surface)!important}
.konto-nav__punkt.ist-aktiv .konto-icon{color:var(--konto-lava)}
.konto-nav__name{flex:1;min-width:0;overflow-wrap:normal;hyphens:auto}
.konto-nav__zahl{flex:none;min-width:24px;padding:1px 7px;border-radius:999px;background:var(--konto-sanft);color:var(--konto-muted);font-size:.76rem;font-variant-numeric:tabular-nums;text-align:center}
.konto-nav__punkt.ist-aktiv .konto-nav__zahl{background:color-mix(in srgb,var(--konto-surface) 18%,transparent);color:var(--konto-surface)}
.konto-nav__punkt--lava .konto-nav__zahl{background:var(--konto-accent);color:var(--konto-accent-text)}
.konto-nav__punkt--warn::after{content:"";position:absolute;top:8px;right:8px;width:8px;height:8px;border-radius:50%;background:#d39a1f}
.konto--tafeln .konto-tafel[hidden]{display:none}
.konto .konto-tafel:focus,.konto .konto-tafel:focus-visible{outline:none}
.konto [id]{scroll-margin-top:96px}
/* Kacheln der Übersicht */
.konto-kacheln{display:none;gap:10px}
.konto-kacheln ul{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,15rem),1fr));gap:10px}
.konto-kachel{display:flex;align-items:center;gap:12px;min-height:72px;padding:12px 14px;border:1px solid var(--konto-line);border-radius:var(--konto-r-gross);background:var(--konto-surface);color:var(--konto-text)!important;text-decoration:none;transition:border-color var(--konto-tempo),box-shadow var(--konto-tempo),transform var(--konto-tempo)}
.konto-kachel:hover{border-color:color-mix(in srgb,var(--konto-text) 30%,var(--konto-line));box-shadow:var(--konto-schatten);transform:translateY(-1px)}
.konto-kachel--lava{border-color:color-mix(in srgb,var(--konto-lava) 50%,var(--konto-line))}
.konto-kachel--warn{border-color:color-mix(in srgb,var(--konto-warn) 40%,var(--konto-line))}
.konto-kachel__text{display:grid;flex:1;min-width:0}
.konto-kachel__text b{font-size:.98rem}
.konto-kachel__text small{color:var(--konto-quiet);font-size:.82rem;overflow-wrap:anywhere}
.konto-kachel__zahl{flex:none;font-size:1.35rem;font-weight:800;font-variant-numeric:tabular-nums}
.konto-zuletzt{display:grid;gap:10px}
/* Als Nächstes */
.konto-kleintitel{color:var(--konto-quiet);font-size:.76rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.konto-naechstes{display:grid;gap:10px}
.konto-naechstes ul{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,17rem),1fr));gap:10px}
.konto-aufgabe{display:flex;flex-wrap:wrap;align-items:center;gap:10px 12px;padding:12px;border:1px solid var(--konto-line);border-radius:var(--konto-r-gross);background:var(--konto-surface);font-size:.9rem;font-weight:600;line-height:1.35}
.konto-aufgabe p{flex:1 1 11rem;min-width:0}
.konto-aufgabe--lava{border-color:color-mix(in srgb,var(--konto-lava) 45%,var(--konto-line))}
.konto-aufgabe--warn{border-color:color-mix(in srgb,var(--konto-warn) 35%,var(--konto-line))}
.konto-aufgabe .konto-knopf{min-height:38px;padding:0 12px;font-size:.84rem}
/* Raster und Bereiche */
.konto-bereich{display:grid;grid-template-columns:minmax(0,1fr);gap:14px;align-content:start;min-width:0;padding:18px;border:1px solid var(--konto-line);border-radius:var(--konto-r-gross);background:var(--konto-surface);scroll-margin-top:90px}
.konto-bereich__kopf{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px}
.konto-bereich__kopf h2{display:flex;align-items:center;gap:10px;color:var(--konto-text);font-size:1.12rem;letter-spacing:-.005em}
.konto-bereich__kopf h2 .konto-icon{color:var(--konto-lava)}
.konto-bereich__kopf h2 small{color:var(--konto-quiet);font-size:.82rem;font-weight:500}
.konto-bereich__werkzeuge{display:flex;flex-wrap:wrap;gap:6px}
.konto-bereich__werkzeuge .konto-knopf{min-height:36px;padding:0 12px;font-size:.84rem}
.konto--eingebettet{margin-top:20px}
/* Zeilen (Sites, Installationen, Käufe, Rechnungen, Meldungen) */
.konto-zeilen{display:grid}
.konto-zeile{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;padding:12px 0;border-top:1px solid var(--konto-line)}
.konto-zeile:first-child{border-top:0;padding-top:0}
.konto-zeile__text{display:grid;flex:1 1 12rem;min-width:0}
.konto-zeile__text b,.konto-zeile__text a{overflow-wrap:anywhere}
.konto-zeile__text a{color:var(--konto-text);font-weight:700;text-decoration:none}
.konto-zeile__text a:hover{color:var(--konto-link);text-decoration:underline}
.konto-zeile__text small{color:var(--konto-quiet);font-size:.84rem;overflow-wrap:anywhere}
.konto-zeile__wert{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.konto-zeile .konto-knoepfe{gap:6px}
.konto-zeile .konto-knopf{min-height:36px;padding:0 12px;font-size:.84rem}
.konto-zeile.ist-widerrufen{background:var(--konto-sanft)}.ist-widerrufen .konto-sechseck{filter:grayscale(1)}.konto-zeile.ist-widerrufen .konto-zeile__text>b{text-decoration:line-through;text-decoration-color:var(--konto-quiet)}
.konto-befehl{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:4px 4px 4px 10px;border-radius:8px;background:var(--konto-sanft);font:600 .8rem/1.4 var(--font-code,ui-monospace,monospace)}
.konto-befehl code{overflow-wrap:anywhere}
.konto-befehl .konto-knopf{min-height:30px;padding:0 8px}
/* Leere Zustände und Hinweise */
.konto-leer{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;padding:16px;border:1px dashed color-mix(in srgb,var(--konto-text) 22%,var(--konto-line));border-radius:var(--konto-r);background:var(--konto-sanft)}
.konto-leer p{flex:1 1 14rem;color:var(--konto-muted)}
.konto-tipp{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--konto-r);background:var(--konto-sanft);color:var(--konto-muted);font-size:.88rem}
.konto-tipp .konto-icon{color:var(--konto-lava);margin-top:2px}
/* Lizenzen */
.konto-lizenzen{display:grid;grid-template-columns:minmax(0,1fr);gap:14px}
.konto-lizenz{display:grid;grid-template-columns:minmax(0,1fr);gap:12px;min-width:0;padding:16px;border:1px solid var(--konto-line);border-radius:var(--konto-r-gross);background:color-mix(in srgb,var(--konto-text) 1.5%,var(--konto-surface))}
.konto-lizenz.ist-widerrufen{background:var(--konto-sanft)}.konto-lizenz.ist-widerrufen h3{text-decoration:line-through;text-decoration-color:var(--konto-quiet)}
.konto-lizenz__kopf{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px}
.konto-lizenz__kopf h3{color:var(--konto-text);font-size:1.08rem}
.konto-lizenz__kopf p{color:var(--konto-quiet);font-size:.88rem;overflow-wrap:anywhere}
.konto-lizenz__kopf>div{flex:1 1 12rem;min-width:0}
.konto-schluessel{display:flex;align-items:stretch;gap:8px;padding:8px;border-radius:var(--konto-r);background:var(--konto-dark);color:var(--konto-dark-text)}
.konto-schluessel>span{display:grid;place-items:center;padding:0 6px;color:color-mix(in srgb,var(--konto-lava) 70%,#fff);font:700 .72rem/1 var(--font-code,ui-monospace,monospace);letter-spacing:.08em}
.konto .konto-schluessel textarea{flex:1;min-width:0;height:auto;min-height:40px;max-height:40px;margin:0;padding:10px 4px;border:0;background:transparent;color:var(--konto-dark-text);font:.82rem/1.5 var(--font-code,ui-monospace,monospace);white-space:nowrap;overflow:hidden;resize:none;box-shadow:none}
.konto .konto-schluessel textarea:focus{max-height:none;white-space:normal;overflow-wrap:anywhere;outline:none}
.konto .konto-schluessel .konto-knopf{flex:none;max-width:50%;overflow:hidden;min-height:40px;background:var(--konto-lava);border-color:var(--konto-lava);color:#15171a}
.konto-balken{display:grid;gap:4px;font-size:.8rem;color:var(--konto-quiet)}
.konto-balken>span{height:6px;border-radius:3px;background:var(--konto-line);overflow:hidden}
.konto-balken i{display:block;height:100%;border-radius:3px;background:linear-gradient(90deg,color-mix(in srgb,var(--konto-lava) 45%,var(--konto-surface)),var(--konto-lava))}
.konto-balken--warn i{background:linear-gradient(90deg,#e6b45c,#c78a1d)}
.konto-fakten{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,8rem),1fr));gap:8px}
.konto-fakten div{padding:10px 12px;border-radius:var(--konto-r);background:var(--konto-sanft)}
.konto-fakten dt{color:var(--konto-quiet);font-size:.74rem}
.konto-fakten dd{margin:0;font-weight:700;overflow-wrap:anywhere}
.konto-klappe{border-radius:var(--konto-r);background:var(--konto-sanft)}
.konto-klappe>summary{list-style:none;display:flex;align-items:center;gap:10px;min-height:42px;padding:0 12px;border-radius:var(--konto-r);font-size:.9rem;font-weight:600;cursor:pointer}
.konto-klappe>summary::-webkit-details-marker{display:none}
.konto-klappe>summary::after{content:"";width:7px;height:7px;margin-left:auto;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg) translate(-2px,-2px);opacity:.6;transition:transform var(--konto-tempo)}
.konto-klappe[open]>summary::after{transform:rotate(225deg) translate(-2px,-2px)}
.konto-klappe>summary:hover{background:var(--konto-hover)}
.konto-klappe>summary .konto-icon{color:var(--konto-lava)}
.konto-klappe__inhalt{display:grid;gap:12px;padding:4px 12px 14px}
.konto pre{overflow-x:auto;padding:12px 14px;border-radius:var(--konto-r);background:var(--konto-dark);color:var(--konto-dark-text);font:.82rem/1.6 var(--font-code,ui-monospace,monospace);white-space:pre-wrap;overflow-wrap:anywhere}
.konto-tabelle{display:grid;grid-template-columns:minmax(0,1fr);gap:8px}
.konto-zugang{display:grid;gap:14px}
.konto-zugang__leer{color:var(--konto-muted);font-size:.9rem}
.konto .konto-zugang__neu{grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:8px 10px}
.konto .konto-zugang__neu .account-social-fehler{grid-column:1/-1;margin:0}
.konto .konto-zugang__neu[hidden]{display:none}
.konto-knopf:disabled{opacity:.6;cursor:progress}
.konto-unterlizenz{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:10px 12px;border-radius:var(--konto-r);background:var(--konto-sanft)}

/* Formulare im Konto */
.konto .form{display:grid;gap:12px;max-width:none}
.konto .form-field{display:grid;gap:4px;margin:0}
.konto .form label{color:var(--konto-muted);font-size:.86rem;font-weight:600}
.konto .form input:not([type=checkbox]):not([type=radio]):not([type=hidden]),.konto .form select,.konto .form textarea{width:100%;min-height:42px;padding:8px 12px;border:1px solid color-mix(in srgb,var(--konto-text) 22%,var(--konto-line));border-radius:var(--konto-r);background:var(--konto-surface);color:var(--konto-text);font:inherit}
.konto .form input:focus,.konto .form select:focus,.konto .form textarea:focus{border-color:var(--konto-focus);outline:2px solid color-mix(in srgb,var(--konto-focus) 30%,transparent);outline-offset:0}
.konto .form button[type=submit]:not(.konto-knopf){justify-self:start;min-height:42px;padding:0 18px;border:0;border-radius:var(--konto-r);background:var(--konto-accent);color:var(--konto-accent-text);font:inherit;font-weight:600;cursor:pointer}
.konto .form-help{color:var(--konto-quiet);font-size:.82rem}
.konto .licensing-row{display:grid;grid-template-columns:minmax(0,8rem) minmax(0,1fr);gap:10px}
.konto .licensing-row--gleich{grid-template-columns:repeat(2,minmax(0,1fr))}
.konto .form-consent label{display:flex;gap:8px;align-items:flex-start;font-weight:500}
.konto-datenschutz{display:grid;gap:10px}
.konto-datenschutz__intro{margin:0;color:var(--konto-quiet)}
.konto-datenschutz .konto-kleintitel{margin-top:14px}
.konto-datenschutz__angaben{display:grid;gap:12px}
.konto-datenschutz__felder{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,14rem),1fr));gap:12px}
.konto-datenschutz__liste{margin:0;padding-left:1.2em;display:grid;gap:4px}
.konto-bedingungen{display:grid;gap:8px}
.konto-bedingungen p{margin:0}
.konto-adresse{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,12rem),1fr));gap:12px}
.konto-adresse dt{color:var(--konto-quiet);font-size:.76rem}
.konto-adresse dd{margin:0;font-weight:600;overflow-wrap:anywhere}
/* Marktplatz: Regal mit Vorschau */
.konto-regal{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,13.5rem),1fr));gap:12px}
.konto-kauf{display:grid;grid-template-rows:auto 1fr auto;min-width:0;border:1px solid var(--konto-line);border-radius:var(--konto-r-gross);background:var(--konto-surface);overflow:hidden;transition:box-shadow var(--konto-tempo),transform var(--konto-tempo)}
.konto-kauf:hover{box-shadow:var(--konto-schatten);transform:translateY(-2px)}
.konto-kauf__bild{display:block;aspect-ratio:16/10;background:var(--konto-sanft)}
.konto-kauf__bild img{display:block;width:100%;height:100%;object-fit:cover}
.konto-kauf__bild.ist-leer{display:grid;place-items:center;color:#fff;background:radial-gradient(circle at 80% 20%,color-mix(in srgb,var(--konto-lava,#e4572e) 45%,transparent),transparent 55%),linear-gradient(140deg,#55306d,var(--konto-dark))}
.konto-kauf__bild.ist-leer .konto-icon{width:44px;height:44px;opacity:.9}
.konto-kauf__text{display:grid;gap:6px;align-content:start;padding:12px 14px 4px}
.konto-kauf__art{display:flex;flex-wrap:wrap;gap:6px}
.konto-kauf h3{font-size:1rem;line-height:1.3}
.konto-kauf h3 a{color:var(--konto-text);text-decoration:none}
.konto-kauf h3 a:hover{color:var(--konto-link);text-decoration:underline}
.konto-kauf__meta{color:var(--konto-quiet);font-size:.82rem}
.konto-kauf>.konto-knoepfe{gap:6px;padding:8px 14px 14px}
.konto-kauf .konto-knopf{min-height:36px;padding:0 12px;font-size:.82rem}
/* Community */
.konto-profil{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;padding:14px;border-radius:var(--konto-r-gross);background:var(--konto-sanft)}
.konto-profil .konto-avatar{width:60px;height:60px;margin:0;font-size:1.1rem}
.konto-profil__text{display:grid;gap:2px;flex:1 1 12rem;min-width:0}
.konto-profil__text b{font-size:1.05rem}
.konto-profil__zahlen{display:flex;flex-wrap:wrap;gap:4px 14px;color:var(--konto-quiet);font-size:.84rem}
.konto-profil__zahlen b{color:var(--konto-text);font-size:inherit}
.konto-themen li{display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:7px 0;border-top:1px solid var(--konto-line);font-size:.92rem}
.konto-themen li:first-child{border-top:0}
.konto-themen a{color:var(--konto-text);font-weight:600;text-decoration:none;overflow-wrap:anywhere}
.konto-themen a:hover{color:var(--konto-link);text-decoration:underline}
.konto-themen li.ist-neu a{font-weight:800}
.konto-themen small{flex:none;color:var(--konto-quiet)}
.konto-untertitel{color:var(--konto-quiet);font-size:.76rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
/* Zuletzt */
.konto-zeitleiste li{position:relative;display:grid;grid-template-columns:34px minmax(0,1fr);gap:12px;padding-bottom:14px}
.konto-zeitleiste li::before{content:"";position:absolute;left:16px;top:36px;bottom:0;width:2px;border-radius:2px;background:var(--konto-line)}
.konto-zeitleiste li:last-child{padding-bottom:0}.konto-zeitleiste li:last-child::before{display:none}
.konto-zeitleiste li.ist-neu::before{background:var(--konto-lava)}
.konto-zeitleiste p{font-size:.9rem;font-weight:600;line-height:1.35}
.konto-zeitleiste p a{color:var(--konto-text);text-decoration:none}
.konto-zeitleiste p a:hover{color:var(--konto-link);text-decoration:underline}
.konto-zeitleiste time{color:var(--konto-quiet);font-size:.8rem}
/* Gäste: Versprechen und Anmeldung */
.konto--gast{align-items:start}
.konto-versprechen{display:grid;gap:14px;padding:26px;border-radius:calc(var(--konto-r-gross) + 6px);background:radial-gradient(circle at 90% 10%,color-mix(in srgb,var(--konto-lava,#e4572e) 35%,transparent),transparent 45%),linear-gradient(125deg,var(--konto-dark),color-mix(in srgb,var(--konto-dark) 88%,#4a4f57));color:var(--konto-dark-text)}
.konto-versprechen h2{color:var(--konto-dark-text);font-size:clamp(1.4rem,1.1rem + 1.2vw,1.9rem);line-height:1.2;letter-spacing:-.015em}
.konto-versprechen>p{color:var(--konto-dark-muted)}
.konto-versprechen ul{display:grid;gap:12px;margin-top:6px}
.konto-versprechen li{display:flex;gap:12px;align-items:center}
.konto-versprechen li p{color:var(--konto-dark-muted);font-size:.88rem}
.konto-anmeldung{padding:22px;border:1px solid var(--konto-line);border-radius:calc(var(--konto-r-gross) + 6px);background:var(--konto-surface);box-shadow:var(--konto-schatten)}
.konto-anmeldung .account-login{display:grid;gap:12px}
.konto-anmeldung h2{font-size:1.3rem}
.konto-anmeldung .account-intro{color:var(--konto-muted)}
.konto-anmeldung .account-form button[type=submit]{width:100%;justify-self:stretch}
.konto-anmeldung .account-alt{font-size:.88rem}
.konto .form .basalto-code input:not([type=hidden]):not([type=checkbox]):not([type=radio]){width:100%;min-height:0;padding:0}
@media (prefers-reduced-motion:reduce){.konto *,.konto *::before,.konto *::after{animation:none!important;transition:none!important}}
@media (max-width:39.99rem){.konto-schluessel .konto-knopf [data-label]{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}}
@media (min-width:64rem){
.konto-kacheln{display:grid}
.konto-app{grid-template-columns:15rem minmax(0,1fr);gap:20px}
.konto-nav{position:sticky;top:90px}
.konto-nav ul{grid-template-columns:minmax(0,1fr);gap:4px}
.konto-nav__punkt{border-color:transparent;background:transparent}
.konto-nav__punkt:hover{background:var(--konto-hover)}
.konto-nav__punkt.ist-aktiv{border-color:var(--konto-text);background:var(--konto-text)}
}
@media (min-width:48rem){
.konto--gast{grid-template-columns:minmax(0,1.1fr) minmax(0,1fr)}
.konto--gast.konto--schmal{grid-template-columns:minmax(0,32rem)}
}
CSS;
}
