<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

/**
 * Die Gestalt des Forums (Neugestaltung TB 47, «Lava-Linie») aus den Design-Tokens des Themes (`--color-*`,
 * `--space-*`, `--radius`) mit neutralen Rückfällen: Sechsecke für Profilbilder, Status und Kompass, Beiträge an
 * einer Linie, Kacheln für die Moderation. Erfolg, Warnung und Fehler mischen ihren Farbton mit der Textfarbe des
 * Themes — so stimmen die Kontraste hell wie dunkel, ohne eigene Regeln für den Dunkelmodus. Die Breite setzt das
 * Forum selbst (`--forum-width`, Vorgabe 76rem), weil es als Block der Seite steht. Mobil zuerst: Zeilen stapeln
 * statt Spalten, nichts scrollt waagrecht; Bewegung nur, wenn das System sie nicht abgeschaltet hat.
 */
final class Styles
{
    public static function tag(): string
    {
        return '<style id="forum-stil">'.self::CSS.'</style>';
    }

    private const CSS = <<<'CSS'
.forum{container:forum / inline-size;--forum-text:var(--color-text,#23262b);--forum-bg:var(--color-background,#f6f4f0);--forum-surface:var(--color-surface,#fff);--forum-muted:var(--color-muted,#3b3f46);--forum-quiet:var(--color-quiet,var(--color-muted,#62676e));--forum-line:var(--color-line,#e3e6ea);--forum-accent:var(--color-accent,#b53f1d);--forum-accent-text:var(--color-accent-text,#fff);--forum-link:var(--color-link,var(--color-accent,#b53f1d));--forum-lava:var(--color-marker,var(--color-lava,#e4572e));--forum-focus:var(--color-focus,var(--color-accent,#b53f1d));--forum-ok:color-mix(in srgb,#2a744a 62%,var(--forum-text));--forum-ok-bg:color-mix(in srgb,#2a744a 12%,var(--forum-surface));--forum-warn:color-mix(in srgb,#8f5c00 55%,var(--forum-text));--forum-warn-bg:color-mix(in srgb,#d9a606 15%,var(--forum-surface));--forum-err:color-mix(in srgb,#b8232f 58%,var(--forum-text));--forum-err-bg:color-mix(in srgb,#b8232f 10%,var(--forum-surface));--forum-hover:color-mix(in srgb,var(--forum-text) 5%,var(--forum-surface));--forum-sanft:color-mix(in srgb,var(--forum-text) 4%,var(--forum-bg));--forum-r:calc(var(--radius,6px) + 4px);--forum-r-gross:calc(var(--radius,6px) * 2 + 4px);--forum-schatten:0 1px 2px rgb(0 0 0 / 5%),0 12px 32px -20px rgb(0 0 0 / 30%);--forum-hex:polygon(25% 5%,75% 5%,100% 50%,75% 95%,25% 95%,0 50%);--forum-tempo:180ms cubic-bezier(.2,.7,.3,1);display:grid;gap:var(--space-4,16px);box-sizing:border-box;width:min(100% - 2 * var(--space-4,16px),var(--forum-width,76rem));margin:var(--space-4,16px) auto var(--space-6,48px);color:var(--forum-text);line-height:1.5;-webkit-tap-highlight-color:transparent}
.forum *,.forum *::before,.forum *::after{box-sizing:border-box}
.forum a{color:var(--forum-link);text-underline-offset:.15em}
.forum :focus-visible{outline:2px solid var(--forum-focus);outline-offset:2px}
.forum h2,.forum h3{color:var(--forum-text)}
.forum-menue,.forum-melden,.forum-klappe,.forum-reagieren-menue,.forum-blockiert,.forum-zusatz,.forum-kennzahlen,.forum-posts,.forum-list,.forum-faelle,.forum-nachrichten{margin:0}
.forum .forum-post>*,.forum .forum-post>*+*{margin:0}
.visually-hidden{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.forum-icon{display:inline-block;width:20px;height:20px;flex:none;vertical-align:middle}
.forum-icon--klein{width:16px;height:16px;vertical-align:-3px}
/* Knöpfe: Lava (Haupt), Fläche (Neben), leise (Werkzeug) */
.forum-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:0 16px;border:0;border-radius:var(--forum-r);background:var(--forum-accent);color:var(--forum-accent-text)!important;font:inherit;font-size:.92em;font-weight:600;line-height:1.2;text-decoration:none;cursor:pointer;transition:background var(--forum-tempo),transform var(--forum-tempo),box-shadow var(--forum-tempo);box-shadow:0 1px 0 rgb(0 0 0 / 8%),0 6px 16px -10px var(--forum-accent);touch-action:manipulation}
.forum-button:hover{background:color-mix(in srgb,var(--forum-accent) 84%,var(--forum-text))}
.forum-button:active,.forum .forum-aktion:active,.forum-kachel:active{transform:scale(.97)}
.forum .forum-aktion{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;margin:0;padding:0 14px;border:1px solid var(--forum-line);border-radius:var(--forum-r);background:var(--forum-surface);color:var(--forum-text);font:inherit;font-size:.88em;font-weight:600;line-height:1.2;text-decoration:none;cursor:pointer;justify-self:auto;white-space:nowrap;transition:border-color var(--forum-tempo),background var(--forum-tempo),color var(--forum-tempo),transform var(--forum-tempo);touch-action:manipulation}
.forum .forum-aktion:hover{border-color:color-mix(in srgb,var(--forum-text) 35%,var(--forum-line));background:var(--forum-hover)}
.forum .forum-aktion[hidden]{display:none}
.forum .forum-aktion[aria-pressed="true"]{background:var(--forum-text);border-color:var(--forum-text);color:var(--forum-surface)}
.forum .forum-aktion[aria-pressed="true"] .forum-icon{color:var(--forum-lava)}
.forum .forum-aktion--icon{width:40px;padding:0}
.forum .forum-aktion--leise{border-color:transparent;background:transparent;color:var(--forum-quiet)}
.forum .forum-aktion--leise:hover{border-color:transparent;background:var(--forum-hover);color:var(--forum-text)}
.forum .forum-aktion--gefahr{color:var(--forum-err);border-color:color-mix(in srgb,var(--forum-err) 35%,var(--forum-line))}
.forum .forum-aktion--gefahr:hover{background:var(--forum-err-bg);border-color:var(--forum-err)}
.forum .forum-aktion--gut{background:#2a744a;border-color:#2a744a;color:#fff}
.forum .forum-aktion--gut:hover{background:#215c3b;border-color:#215c3b}
.forum-knopfform{display:inline-flex;margin:0}
.forum-glocke__zahl{display:inline-grid;place-items:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:var(--forum-accent);color:var(--forum-accent-text);font-size:.7rem;font-weight:700;font-style:normal;line-height:1;font-variant-numeric:tabular-nums}
.forum-live[hidden],.forum-glocke__zahl[hidden]{display:none}
/* Sechseck: Profilbild, Status, Kompass */
.forum-avatar{display:inline-grid;place-items:center;flex:none;width:40px;height:40px;clip-path:var(--forum-hex);object-fit:cover;background:linear-gradient(140deg,#5a616b,#2c3036);color:#fff;font-weight:700;font-size:.82rem;letter-spacing:.02em;line-height:1;text-transform:uppercase;vertical-align:middle}
img.forum-avatar{background:var(--forum-line)}
.forum-avatar--f1{background:linear-gradient(140deg,#d0643c,#8f2f14)}.forum-avatar--f2{background:linear-gradient(140deg,#4f8c70,#24503e)}.forum-avatar--f3{background:linear-gradient(140deg,#4f7fb8,#24477a)}.forum-avatar--f4{background:linear-gradient(140deg,#9363b0,#55306d)}.forum-avatar--f5{background:linear-gradient(140deg,#b98336,#704a17)}.forum-avatar--f6{background:linear-gradient(140deg,#6b7883,#38434c)}
.forum-avatar--klein{width:28px;height:28px;font-size:.62rem;letter-spacing:0}
.forum-avatar--gross{width:56px;height:56px;font-size:1.02rem}
.forum-avatar--profil{width:112px;height:112px;font-size:1.9rem}
.forum-avatar--mehr{background:var(--forum-line);color:var(--forum-muted);font-size:.6rem}
.forum-portrait{position:relative;display:inline-grid;flex:none}
.forum-portrait__online{position:absolute;right:1px;bottom:3px;width:12px;height:12px;border-radius:50%;background:#2a744a;box-shadow:0 0 0 2px var(--forum-surface)}
.forum-stapel{display:inline-flex;align-items:center;vertical-align:middle}
.forum-stapel__bild{display:inline-grid;margin-left:-7px;padding:2px;clip-path:var(--forum-hex);background:var(--forum-surface)}
.forum-stapel__bild:first-child{margin-left:0}
/* Chips (Abzeichen) und Präfixe */
.forum-badge{display:inline-flex;align-items:center;gap:5px;min-height:24px;padding:1px 9px;border:1px solid var(--forum-line);border-radius:999px;background:var(--forum-surface);color:var(--forum-muted);font-size:.74rem;font-weight:600;line-height:1.3;white-space:nowrap;vertical-align:middle;text-decoration:none}
.forum a.forum-badge{color:var(--forum-muted)}
.forum-badge .forum-icon{width:13px;height:13px}
.forum-badge--neu,.forum-badge--team{background:var(--forum-accent);border-color:transparent;color:var(--forum-accent-text)}
.forum-badge--erledigt,.forum a.forum-badge--erledigt,.forum a.forum-badge--erledigt:visited{background:var(--forum-ok-bg);border-color:transparent;color:var(--forum-ok)}
.forum-badge--wartet{background:var(--forum-warn-bg);border-color:transparent;color:var(--forum-warn)}
.forum-badge--gemeldet{background:var(--forum-err-bg);border-color:transparent;color:var(--forum-err)}
.forum-badge--empfohlen{background:color-mix(in srgb,#d9a606 14%,var(--forum-surface));border-color:transparent;color:var(--forum-warn)}
.forum-badge--dunkel{background:var(--forum-text);border-color:transparent;color:var(--forum-surface)}
.forum-badge--dunkel .forum-icon{color:var(--forum-lava)}
.forum-praefix{--praefix:var(--forum-quiet);display:inline-flex;align-items:center;min-height:22px;padding:1px 8px;margin-right:4px;border-radius:6px;background:color-mix(in srgb,var(--praefix) 14%,var(--forum-surface));color:color-mix(in srgb,var(--praefix) 50%,var(--forum-text));font-size:.72rem;font-weight:700;letter-spacing:.01em;line-height:1.3;white-space:nowrap;vertical-align:middle}
.forum-praefix--lava{--praefix:#e4572e}.forum-praefix--green{--praefix:#2f9a5d}.forum-praefix--blue{--praefix:#3478c6}.forum-praefix--yellow{--praefix:#d9a606}.forum-praefix--red{--praefix:#d0343f}
.forum-rang{--rang:var(--forum-quiet);display:inline-flex;align-items:center;gap:5px;min-height:24px;margin:0;padding:1px 9px;border:1px solid var(--forum-line);border-radius:999px;background:var(--forum-surface);color:var(--forum-muted);font-size:.74rem;font-weight:600;line-height:1.3;white-space:nowrap}
.forum-rang::before{content:"";width:8px;height:8px;clip-path:var(--forum-hex);background:var(--rang)}
.forum-rang.hat-zeichen::before{display:none}
.forum-rang__zeichen{font-size:1.05em;line-height:1}
.forum-rang--lava{--rang:#e4572e}.forum-rang--green{--rang:#2f9a5d}.forum-rang--blue{--rang:#3478c6}.forum-rang--yellow{--rang:#d9a606}.forum-rang--red{--rang:#d0343f}.forum-rang--grey{--rang:#80868c}
.forum-sprache{display:inline-flex;font-size:1em;line-height:1}
.forum-schlagwoerter{display:flex;flex-wrap:wrap;gap:4px 10px;margin:0;font-size:.86em}
.forum-schlagwort{color:var(--forum-link);font-weight:600;text-decoration:none}
.forum-schlagwort:hover{text-decoration:underline}
/* Navigator */
.forum-nav{display:grid;gap:6px;padding:6px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface);box-shadow:var(--forum-schatten)}
.forum-nav__wege{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr);gap:2px;margin:0;padding:0;list-style:none}
.forum-nav__weg{position:relative;display:grid;justify-items:center;gap:3px;min-height:52px;padding:7px 2px 6px;border-radius:var(--forum-r);color:var(--forum-muted)!important;font-size:.6rem;letter-spacing:-.01em;hyphens:auto;font-weight:600;line-height:1.15;text-align:center;text-decoration:none;transition:background var(--forum-tempo),color var(--forum-tempo)}
.forum-nav__weg:hover{background:var(--forum-hover);color:var(--forum-text)!important}
.forum-nav__weg[aria-current]{background:var(--forum-text);color:var(--forum-surface)!important}
.forum-nav__weg[aria-current] .forum-icon{color:var(--forum-lava)}
.forum-nav__zahl{position:absolute;top:2px;right:calc(50% - 26px);min-width:18px;height:18px;padding:0 5px;font-size:.62rem;box-shadow:0 0 0 2px var(--forum-surface)}
.forum-nav__konto{display:flex;align-items:center;gap:4px;padding-top:6px;border-top:1px solid var(--forum-line)}
.forum-nav__suche{position:relative;display:flex;align-items:center;flex:1 1 8rem;min-width:0;margin:0}
.forum-nav__suche .forum-icon{position:absolute;left:12px;color:var(--forum-quiet);pointer-events:none}
.forum .forum-nav__suche input{width:100%;min-width:0;height:42px;margin:0;padding:0 12px 0 38px;border:1px solid transparent;border-radius:var(--forum-r);background:var(--forum-sanft);color:var(--forum-text);font:inherit;font-size:.9em}
.forum .forum-nav__suche input:focus{border-color:var(--forum-focus);background:var(--forum-surface);outline:none}
.forum-nav__knopf{position:relative;display:inline-grid;place-items:center;flex:none;width:42px;height:42px;border-radius:var(--forum-r);color:var(--forum-muted)!important;text-decoration:none;transition:background var(--forum-tempo)}
.forum-nav__knopf:hover{background:var(--forum-hover);color:var(--forum-text)!important}
.forum-nav__knopf[aria-current]{background:var(--forum-text);color:var(--forum-surface)!important}
.forum-nav__knopf .forum-glocke__zahl{position:absolute;top:1px;right:-2px;min-width:18px;height:18px;padding:0 5px;font-size:.64rem;box-shadow:0 0 0 2px var(--forum-surface)}
.forum-nav__ich{display:inline-flex;align-items:center;gap:8px;flex:none;min-height:42px;padding:0 6px;border-radius:var(--forum-r);color:var(--forum-text)!important;font-size:.88em;font-weight:600;text-decoration:none}
.forum-nav__ich:hover{background:var(--forum-hover)}
.forum-nav__ich span:not(.forum-avatar){position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.forum-nav__anmelden{flex:none;min-height:42px}
.forum-werkzeuge{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end}
/* Menüs (Popover ohne Skript) */
.forum-menue{position:relative;display:inline-block}
.forum-menue>summary{list-style:none;display:inline-flex;align-items:center;justify-content:center;gap:7px;min-width:40px;min-height:40px;padding:0 12px;border:1px solid var(--forum-line);border-radius:var(--forum-r);background:var(--forum-surface);color:var(--forum-text);font-size:.88em;font-weight:600;cursor:pointer;user-select:none;transition:background var(--forum-tempo),border-color var(--forum-tempo)}
.forum-menue>summary:hover{background:var(--forum-hover);border-color:color-mix(in srgb,var(--forum-text) 35%,var(--forum-line))}
.forum-menue>summary::-webkit-details-marker{display:none}
.forum-menue[open]>summary{background:var(--forum-text);border-color:var(--forum-text);color:var(--forum-surface)}
.forum-menue--leise>summary{border-color:transparent;background:transparent;color:var(--forum-quiet);padding:0 10px}
.forum-menue--icon>summary{padding:0;width:40px}
.forum-menue__liste{position:absolute;right:0;top:calc(100% + 6px);z-index:20;display:grid;gap:2px;width:max-content;min-width:15rem;max-width:min(22rem,calc(100vw - 2rem));padding:6px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface);box-shadow:0 18px 48px -18px rgb(0 0 0 / 40%);animation:forum-auf .16s ease-out}
.forum-menue--links .forum-menue__liste{left:0;right:auto}
.forum-menue--oben .forum-menue__liste{top:auto;bottom:calc(100% + 6px)}
@keyframes forum-auf{from{opacity:0;transform:translateY(-4px) scale(.98)}to{opacity:1;transform:none}}
.forum-menue__liste>a,.forum-menue__liste .forum-aktion,.forum-menue__liste>details>summary{display:flex!important;align-items:center;justify-content:flex-start!important;gap:10px;width:100%;min-height:40px;padding:0 10px!important;border:0!important;border-radius:var(--forum-r)!important;background:transparent!important;color:var(--forum-text)!important;font-size:.88rem!important;font-weight:500!important;text-align:left;text-decoration:none;white-space:normal}
.forum-menue__liste>a:hover,.forum-menue__liste .forum-aktion:hover,.forum-menue__liste>details>summary:hover{background:var(--forum-hover)!important}
.forum-menue__liste .forum-aktion[aria-pressed="true"]{font-weight:700!important}
.forum-menue__liste .forum-aktion[aria-pressed="true"] .forum-icon{color:var(--forum-lava)}
.forum-menue__liste .forum-aktion--gefahr,.forum-menue__liste .forum-aktion--gefahr .forum-icon,.forum-menue__liste summary.forum-aktion--gefahr{color:var(--forum-err)!important}
.forum-menue__liste .forum-knopfform{display:flex;width:100%}
.forum-menue__liste>a[aria-current]{font-weight:700;color:var(--forum-link)!important}
.forum-menue__liste label{display:grid;gap:4px;padding:6px 8px;font-size:.85em}
.forum-menue__liste hr{width:100%;height:1px;margin:4px 0;border:0;background:var(--forum-line)}
.forum-menue__liste>details>summary{list-style:none;cursor:pointer}
.forum-menue__liste>details>summary::-webkit-details-marker{display:none}
.forum-menue__liste>details[open]{padding-bottom:6px;border-radius:var(--forum-r);background:var(--forum-sanft)}
.forum-menue__liste>details .forum-moderation__form{padding:4px 10px 6px}
.forum-menue__titel{margin:4px 10px 2px;color:var(--forum-quiet);font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
/* Kopf (Held) einer Seite */
.forum-held{position:relative;display:grid;gap:16px;padding:18px 14px 16px 18px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:linear-gradient(var(--forum-lava),var(--forum-accent)) 0 0/4px 100% no-repeat,var(--forum-surface);box-shadow:var(--forum-schatten)}
/* Der Lava-Streifen links ist eine Hintergrundebene — er folgt den runden Ecken, ohne dass der Kopf seine Menüs abschneidet (TB 51) */
.forum-held__kopf{display:grid;grid-template-columns:auto minmax(0,1fr);gap:14px;align-items:center}
.forum-held__zeichen{display:grid;place-items:center;width:52px;height:52px;clip-path:var(--forum-hex);background:var(--forum-text);color:var(--forum-lava)}
.forum-held__zeichen .forum-icon{width:24px;height:24px}
.forum-held__text{display:grid;gap:8px;min-width:0}
.forum-held__lead{margin:0;color:var(--forum-muted);font-size:1.02em;line-height:1.5}
.forum-held__chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.forum-held__meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;margin:0;color:var(--forum-quiet);font-size:.9em}
.forum-held__meta b,.forum-held__meta strong{color:var(--forum-text)}
.forum-held__leiste{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 18px;padding-top:14px;border-top:1px solid var(--forum-line)}
.forum-held__knoepfe{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.forum-held__zahlen{display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px}
.forum-held--thema .forum-held__text{gap:12px}
.forum-kennzahlen{display:flex;flex-wrap:wrap;align-items:center;gap:10px 22px;margin:0}
.forum-kennzahlen>div{display:flex;flex-direction:column-reverse;line-height:1.15}
.forum-kennzahlen dt{color:var(--forum-quiet);font-size:.74rem}
.forum-kennzahlen dd{margin:0;color:var(--forum-text);font-size:1.3rem;font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:-.01em}
.forum-kennzahl--neu dd{color:var(--forum-link)}
.forum-kennzahl--stapel dd{display:flex;align-items:center;gap:6px;font-size:1.3rem}
.forum-sprung{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:0 12px;border-radius:999px;background:var(--forum-ok-bg);color:var(--forum-ok)!important;font-size:.86em;font-weight:700;text-decoration:none;transition:filter var(--forum-tempo)}
.forum-sprung:hover{filter:brightness(.97)}
.forum-sprung--neu{background:color-mix(in srgb,var(--forum-lava) 12%,var(--forum-surface));color:var(--forum-link)!important}
.forum-sprung--team{background:var(--forum-sanft);color:var(--forum-text)!important}
.forum-held__mod{display:none}
/* Abschnitte, Karten, Listen */
.forum-section{border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface);overflow:hidden}
.forum-section>h2{display:flex;align-items:center;gap:8px;margin:0;padding:14px 18px;font-size:1rem;border-bottom:1px solid var(--forum-line)}
.forum-section>p.forum-section__text{margin:0;padding:10px 18px 0;color:var(--forum-muted);font-size:.92em}
.forum-section--ankuendigung{border-color:color-mix(in srgb,var(--forum-lava) 45%,var(--forum-line));box-shadow:0 0 0 3px color-mix(in srgb,var(--forum-lava) 10%,transparent)}
.forum-section--ankuendigung>h2{background:color-mix(in srgb,var(--forum-lava) 8%,var(--forum-surface));color:var(--forum-text)}
.forum-section--ankuendigung>h2 .forum-icon{color:var(--forum-lava)}
.forum-list{list-style:none;margin:0;padding:0}
.forum-empty{margin:0;padding:22px 18px;color:var(--forum-quiet);text-align:center}
.forum-leer{display:grid;justify-items:center;gap:12px;padding:34px 18px;color:var(--forum-quiet);text-align:center}
.forum-leer p{margin:0;max-width:34rem}
.forum-leer .forum-sechseck{width:60px;height:60px}
.forum-leer .forum-sechseck .forum-icon{width:24px;height:24px}
.forum-note{margin:0;padding:14px 18px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-sanft);color:var(--forum-text)}
.forum-note a{font-weight:600}
.forum-note--sperre{border-color:color-mix(in srgb,var(--forum-err) 40%,var(--forum-line));background:var(--forum-err-bg);color:var(--forum-err)}
.forum-lead{margin:0;color:var(--forum-muted);font-size:1.05em}
.forum-karte{display:grid;gap:12px;padding:16px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface)}
.forum-karte>h2,.forum-kasten>h2{display:flex;align-items:center;gap:8px;margin:0;font-size:.92rem;letter-spacing:.005em}
.forum-karte>h2 .forum-icon,.forum-kasten>h2 .forum-icon{width:18px;height:18px;color:var(--forum-lava)}
.forum-karte>h2 small,.forum-kasten>h2 small{margin-left:auto;color:var(--forum-quiet);font-size:.78rem;font-weight:500}
/* Zeilen: Foren und Themen */
.forum-row{position:relative;display:grid;grid-template-columns:auto minmax(0,1fr);gap:6px 14px;align-items:start;padding:14px 16px;border-top:1px solid var(--forum-line);transition:background var(--forum-tempo)}
.forum-list>.forum-row:first-child{border-top:0}
.forum-row:hover{background:color-mix(in srgb,var(--forum-text) 2.5%,var(--forum-surface))}
.forum-row--neu::before{content:"";position:absolute;left:0;top:14px;bottom:14px;width:3px;border-radius:0 3px 3px 0;background:var(--forum-lava)}
.forum-row__main{display:grid;gap:4px;min-width:0}
.forum-row__main h3{display:flex;flex-wrap:wrap;align-items:center;gap:4px 6px;margin:0;font-size:1rem;line-height:1.35}
.forum-row__main h3 a{color:var(--forum-text);font-weight:600;text-decoration:none;overflow-wrap:anywhere}
.forum-row__main h3 a:hover{color:var(--forum-link);text-decoration:underline}
.forum-row--neu .forum-row__main h3 a{font-weight:800}
.forum-row__main p{margin:0;color:var(--forum-quiet);font-size:.88em}
.forum-row__text{color:var(--forum-muted)!important}
.forum-row__unter{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px}
.forum-row__unter a{color:var(--forum-quiet)}
.forum-row--wartet{background:repeating-linear-gradient(-45deg,var(--forum-surface) 0 12px,color-mix(in srgb,var(--forum-warn-bg) 70%,var(--forum-surface)) 12px 24px)}
.forum-row--verweis .forum-row__main h3 a{font-weight:400;color:var(--forum-muted)}
.forum-row--link .forum-sechseck{background:none}
.forum-sechseck{position:relative;display:grid;place-items:center;width:44px;height:44px;color:var(--forum-quiet)}
.forum-sechseck::before,.forum-sechseck::after{content:"";position:absolute;inset:0;clip-path:var(--forum-hex);background:var(--forum-line)}
.forum-sechseck::after{inset:2px;background:var(--forum-surface)}
.forum-sechseck .forum-icon{position:relative;z-index:1;width:19px;height:19px}
.forum-sechseck--neu{color:#fff}
.forum-sechseck--forum{color:var(--forum-link)}
.forum-sechseck--klein{width:36px;height:36px}
.forum-sechseck--fehler{color:var(--forum-err)}.forum-sechseck--fehler::after{background:var(--forum-err-bg)}.forum-sechseck--fehler::before{background:color-mix(in srgb,var(--forum-err) 50%,var(--forum-line))}
.forum-sechseck--neu::before,.forum-sechseck--neu::after{background:var(--forum-lava)}
.forum-sechseck--ok{color:var(--forum-ok)}.forum-sechseck--ok::before{background:var(--forum-ok)}
.forum-sechseck--wartet{color:var(--forum-warn)}.forum-sechseck--wartet::after{background:var(--forum-warn-bg)}.forum-sechseck--wartet::before{background:color-mix(in srgb,var(--forum-warn) 50%,var(--forum-line))}
.forum-sechseck--dunkel{color:var(--forum-lava)}.forum-sechseck--dunkel::before,.forum-sechseck--dunkel::after{background:var(--forum-text)}
.forum-sechseck b{position:absolute;right:-7px;top:-5px;z-index:2;display:grid;place-items:center;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:var(--forum-text);color:var(--forum-surface);font-size:.66rem;box-shadow:0 0 0 2px var(--forum-surface)}
.forum-row__zahlen,.forum-row__last,.forum-row__letzte,.forum-row__kennzahlen{grid-column:2}
.forum-row__zahlen{display:flex;flex-wrap:wrap;align-items:center;gap:4px 12px;color:var(--forum-quiet);font-size:.82em}
.forum-row__zahlen b{color:var(--forum-text);font-variant-numeric:tabular-nums}
.forum-hitze{display:none;width:100%;height:4px;border-radius:2px;background:var(--forum-line);overflow:hidden}
.forum-hitze i{display:block;height:100%;border-radius:2px;background:linear-gradient(90deg,color-mix(in srgb,var(--forum-lava) 45%,var(--forum-surface)),var(--forum-lava))}
.forum-row__reaktion small{color:var(--forum-quiet)}
.forum-row__last{display:flex;align-items:center;gap:10px;min-width:0;color:var(--forum-quiet);font-size:.82em}
.forum-row__last>span{display:grid;min-width:0}
.forum-row__last b{overflow:hidden;color:var(--forum-text);font-weight:600;white-space:nowrap;text-overflow:ellipsis}
.forum-row__waehlen{display:none;align-items:center;align-self:center}
.forum .forum-auswahl{width:22px;height:22px;margin:0;accent-color:var(--forum-accent);cursor:pointer}
.forum-auswahl-label{display:none;align-items:center}
.forum-row__kennzahlen{display:flex;gap:18px;margin:0}
.forum-row__kennzahlen>div{display:flex;flex-direction:column-reverse;line-height:1.2}
.forum-row__kennzahlen dt{color:var(--forum-quiet);font-size:.72rem}
.forum-row__kennzahlen dd{margin:0;font-weight:700;font-variant-numeric:tabular-nums}
.forum-subs{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0 0!important}
.forum-subs a{display:inline-flex;align-items:center;gap:5px;min-height:28px;padding:0 10px;border-radius:999px;background:var(--forum-sanft);color:var(--forum-muted)!important;font-size:.92em;font-weight:600;text-decoration:none}
.forum-subs a:hover{background:var(--forum-hover);color:var(--forum-text)!important}
.forum-row__letzte{display:grid;gap:8px;margin:0;padding:0;list-style:none;min-width:0;font-size:.86em}
.forum-row__letzte li{display:flex;gap:10px;align-items:center;min-width:0}
.forum-row__letzte li>div{display:grid;min-width:0}
.forum-row__letzte a{overflow:hidden;color:var(--forum-text);font-weight:600;text-decoration:none;white-space:nowrap;text-overflow:ellipsis}
.forum-row__letzte a:hover{color:var(--forum-link);text-decoration:underline}
.forum-row__letzte span:not(.forum-avatar):not(.forum-praefix):not(.forum-portrait){color:var(--forum-quiet);font-size:.92em}
.forum-row__leer{color:var(--forum-quiet)}
/* Kategorien der Übersicht */
.forum-kategorie{display:grid;gap:10px}
.forum-kategorie__titel{display:flex;align-items:center;gap:10px;margin:6px 2px 0;font-size:1.08rem;letter-spacing:-.005em}
.forum-kategorie__titel::before{content:"";width:12px;height:11px;clip-path:var(--forum-hex);background:var(--forum-lava)}
.forum-kategorie__titel small{margin-left:auto;color:var(--forum-quiet);font-size:.78rem;font-weight:500}
/* Puls der Community */
.forum-puls{display:grid;gap:16px;padding:18px;border-radius:var(--forum-r-gross);background:var(--color-dark,#23262b);color:var(--color-dark-text,#f6f4f0);box-shadow:var(--forum-schatten);position:relative;overflow:hidden}
.forum-puls::after{content:"";position:absolute;right:-40px;top:-50px;width:220px;height:200px;clip-path:var(--forum-hex);background:radial-gradient(circle at 30% 30%,color-mix(in srgb,#e4572e 55%,transparent),transparent 70%);opacity:.55;pointer-events:none}
.forum-puls>*{position:relative;z-index:1}
.forum-puls__gruss{margin:0;font-size:1.15rem;font-weight:700;letter-spacing:-.01em}
.forum-puls__gruss small{display:block;margin-top:2px;color:var(--color-dark-muted,#b5bac0);font-size:.86rem;font-weight:400;letter-spacing:0}
.forum-puls .forum-kennzahlen dt{color:var(--color-dark-muted,#b5bac0)}
.forum-puls .forum-kennzahlen dd{color:var(--color-dark-text,#f6f4f0)}
.forum-puls .forum-stapel__bild{background:var(--color-dark,#23262b)}
.forum-puls__online{display:inline-flex;align-items:center;gap:6px}
.forum-puls__online::before{content:"";width:9px;height:9px;border-radius:50%;background:#5fc28c;box-shadow:0 0 0 0 rgb(95 194 140 / 60%);animation:forum-puls 2.4s ease-out infinite}
@keyframes forum-puls{0%{box-shadow:0 0 0 0 rgb(95 194 140 / 55%)}70%{box-shadow:0 0 0 9px rgb(95 194 140 / 0%)}100%{box-shadow:0 0 0 0 rgb(95 194 140 / 0%)}}
.forum-puls__knoepfe{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.forum-puls .forum-aktion,.forum-puls .forum-menue>summary{background:transparent;border-color:color-mix(in srgb,var(--color-dark-text,#f6f4f0) 22%,transparent);color:var(--color-dark-text,#f6f4f0)}
.forum-puls .forum-aktion:hover,.forum-puls .forum-menue>summary:hover{background:color-mix(in srgb,var(--color-dark-text,#f6f4f0) 10%,transparent)}
.forum-puls .forum-menue__liste{color:var(--forum-text)}
.forum-puls .forum-button{background:var(--forum-lava);color:#15171a!important;box-shadow:none}
.forum-puls .forum-button:hover{background:color-mix(in srgb,var(--forum-lava) 85%,#fff)}
.forum-puls a.forum-puls__neu{display:inline-flex;align-items:center;gap:8px;min-height:36px;padding:0 12px;border-radius:999px;background:color-mix(in srgb,#e4572e 22%,transparent);color:#ffd9cb;font-size:.86em;font-weight:700;text-decoration:none}
/* Spalten mit Seitenleiste */
.forum-spalten{display:grid;gap:var(--space-5,24px)}
.forum-spalten__haupt{display:grid;gap:var(--space-4,16px);min-width:0;align-content:start}
.forum-spalten__seite{display:grid;gap:var(--space-4,16px);align-content:start}
.forum-kasten{display:grid;gap:10px;padding:16px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface)}
.forum-kasten__zahlen{display:grid;gap:4px;margin:0;font-size:.9em}
.forum-kasten__zahlen div{display:flex;justify-content:space-between;gap:8px}
.forum-kasten__zahlen dd{margin:0;font-weight:700}
.forum-kasten__namen{display:flex;flex-wrap:wrap;gap:8px;list-style:none;margin:0;padding:0;font-size:.88em}
.forum-kasten__namen li{display:inline-flex;align-items:center;gap:6px}
.forum-kasten__namen .ist-team{font-weight:700}
.forum-kasten__themen{display:grid;gap:12px;list-style:none;margin:0;padding:0;font-size:.9em}
.forum-kasten__themen li{display:grid;gap:2px;min-width:0}
.forum-kasten__themen a{color:var(--forum-text);font-weight:600;text-decoration:none;overflow-wrap:anywhere}
.forum-kasten__themen a:hover{color:var(--forum-link);text-decoration:underline}
.forum-kasten__themen span:not(.forum-avatar):not(.forum-praefix){color:var(--forum-quiet);font-size:.85em}
.forum-kasten__themen li.mit-bild{grid-template-columns:auto minmax(0,1fr);gap:10px;align-items:start}
.forum-kasten__themen li.mit-bild>div{display:grid;gap:2px;min-width:0}
.forum-kasten__leer{margin:0;color:var(--forum-quiet);font-size:.9em}
.forum--kasten{margin:0;width:auto}
/* Band unter der Übersicht */
.forum-band{display:grid;gap:18px;padding:20px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface)}
.forum-band h2{display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:.95rem}
.forum-band h2 .forum-icon{width:18px;height:18px;color:var(--forum-lava)}
.forum-band p{margin:0 0 4px;color:var(--forum-muted);font-size:.9em}
.forum-band__namen a{color:var(--forum-text);text-decoration:none}
.forum-band__namen a:hover{color:var(--forum-link);text-decoration:underline}
.forum-band__namen a.ist-team{font-weight:700}
/* Themenliste: Kopf mit Segmenten, Sortierung, Filter, Auswahl */
.forum-themen{overflow:visible}
.forum-themen>.forum-list>.forum-row:last-child{border-radius:0 0 var(--forum-r-gross) var(--forum-r-gross)}
.forum-themen__kopf{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:10px;border-bottom:1px solid var(--forum-line);border-radius:var(--forum-r-gross) var(--forum-r-gross) 0 0;background:var(--forum-sanft)}
.forum-themen__werkzeuge{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.forum-segmente{display:flex;flex-wrap:wrap;gap:2px;margin:0;padding:3px;border:1px solid var(--forum-line);border-radius:calc(var(--forum-r) + 2px);background:var(--forum-surface)}
.forum-segmente a{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 12px;border-radius:var(--forum-r);color:var(--forum-muted)!important;font-size:.86em;font-weight:600;text-decoration:none;transition:background var(--forum-tempo),color var(--forum-tempo)}
.forum-segmente a:hover{background:var(--forum-hover);color:var(--forum-text)!important}
.forum-segmente a[aria-current]{background:var(--forum-text);color:var(--forum-surface)!important}
.forum-segmente a[aria-current] .forum-glocke__zahl{background:var(--forum-lava);color:#15171a}
.forum-praefixwahl{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--forum-line)}
.forum-praefixwahl a{display:inline-flex;align-items:center;min-height:30px;padding:0 4px;border-radius:8px;text-decoration:none;color:var(--forum-muted);font-size:.86em;font-weight:600}
.forum-praefixwahl a[aria-current]{box-shadow:0 0 0 2px var(--forum-text)}
.forum-praefixwahl a .forum-praefix{margin:0}
.forum-filter__knopf.ist-aktiv{box-shadow:inset 0 0 0 2px var(--forum-lava)}
.forum-filter__gruppe{display:flex;flex-wrap:wrap;gap:4px 10px;margin:0;padding:6px 8px;font-size:.88em}
.forum-filter__gruppe b{flex-basis:100%;color:var(--forum-quiet);font-size:.72rem;letter-spacing:.06em;text-transform:uppercase}
.forum-filter__gruppe a{display:inline-flex;align-items:center;gap:4px;min-height:32px;padding:0 8px;border-radius:8px;color:var(--forum-text);text-decoration:none}
.forum-filter__gruppe a:hover{background:var(--forum-hover)}
.forum-filter__gruppe a[aria-current]{background:var(--forum-text);color:var(--forum-surface)}
.forum-filter ul{display:flex;flex-wrap:wrap;gap:2px;list-style:none;margin:0;padding:3px;border:1px solid var(--forum-line);border-radius:calc(var(--forum-r) + 2px);background:var(--forum-surface);width:fit-content;max-width:100%}
.forum-filter li a{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 14px;border-radius:var(--forum-r);color:var(--forum-muted);font-size:.88em;font-weight:600;text-decoration:none}
.forum-filter li a:hover{background:var(--forum-hover);color:var(--forum-text)}
.forum-filter li a[aria-current]{background:var(--forum-text);color:var(--forum-surface)}
.forum-filter li a[aria-current] .forum-glocke__zahl{background:var(--forum-lava);color:#15171a}
.forum-schalter{display:inline-flex;align-items:center;gap:10px;min-height:40px;padding:0 12px;border:1px solid var(--forum-line);border-radius:var(--forum-r);background:var(--forum-surface);color:var(--forum-text);font-size:.88em;font-weight:600;cursor:pointer;user-select:none}
.forum-schalter input{position:absolute;opacity:0;width:1px;height:1px}
.forum-schalter__bahn{position:relative;flex:none;width:38px;height:22px;border-radius:999px;background:var(--forum-line);transition:background var(--forum-tempo)}
.forum-schalter__bahn::after{content:"";position:absolute;left:3px;top:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgb(0 0 0 / 30%);transition:transform var(--forum-tempo)}
.forum-schalter:has(input:checked) .forum-schalter__bahn{background:var(--forum-accent)}
.forum-schalter:has(input:checked) .forum-schalter__bahn::after{transform:translateX(16px)}
.forum-schalter:has(input:focus-visible){outline:2px solid var(--forum-focus);outline-offset:2px}
.forum-schalter--leise{border:0;background:transparent;padding:0}
.forum:has(#forum-themen-waehlen:checked) .forum-row__waehlen,.forum:has(#forum-beitraege-waehlen:checked) .forum-auswahl-label{display:inline-flex}
.forum:has(#forum-themen-waehlen:checked) .forum-row:has(.forum-auswahl:checked){background:color-mix(in srgb,var(--forum-lava) 8%,var(--forum-surface))}
.forum:has(#forum-beitraege-waehlen:checked) .forum-post:has(.forum-auswahl:checked){box-shadow:0 0 0 3px color-mix(in srgb,var(--forum-lava) 45%,transparent)}
/* Leiste «n ausgewählt» */
.forum-auswahlleiste{position:sticky;bottom:12px;z-index:15;display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px 12px;padding:12px 14px;border-radius:var(--forum-r-gross);background:var(--color-dark,#23262b);color:var(--color-dark-text,#f6f4f0);box-shadow:0 22px 50px -18px rgb(0 0 0 / 55%);font-size:.88em;animation:forum-hoch .22s ease-out}
@keyframes forum-hoch{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.forum-auswahlleiste[hidden]{display:none!important}
.forum:not(:has(#forum-themen-waehlen:checked)) .forum-themen~.forum-auswahlleiste,.forum:not(:has(#forum-beitraege-waehlen:checked)) .forum-posts~.forum-auswahlleiste{display:none!important}
.forum-auswahlleiste label{display:grid;gap:3px;color:var(--color-dark-muted,#b5bac0);font-size:.86em}
.forum .forum-auswahlleiste select,.forum .forum-auswahlleiste input[type=text]{min-height:38px;border:1px solid color-mix(in srgb,var(--color-dark-text,#f6f4f0) 25%,transparent);border-radius:var(--forum-r);background:color-mix(in srgb,var(--color-dark-text,#f6f4f0) 8%,transparent);color:var(--color-dark-text,#f6f4f0);font:inherit;padding:0 10px}
.forum .forum-auswahlleiste select option{color:#23262b}
.forum-auswahlleiste .community-check{display:flex;flex-direction:row;align-items:center;gap:6px;color:var(--color-dark-text,#f6f4f0)}
.forum-auswahlleiste__zahl{display:flex;align-items:center;gap:8px;margin:0;flex-basis:100%;font-size:1.05em;font-weight:700}
.forum-auswahlleiste__zahl::before{content:"";width:12px;height:11px;clip-path:var(--forum-hex);background:var(--forum-lava)}
.forum-auswahlleiste .forum-button{background:var(--forum-lava);color:#15171a!important;box-shadow:none}
/* Thema: Raster aus Beiträgen und Seite */
.forum-thema{display:grid;gap:var(--space-5,24px)}
.forum-thema__haupt{display:grid;gap:var(--space-4,16px);min-width:0;align-content:start}
.forum-thema__seite{display:grid;gap:var(--space-4,16px);align-content:start}
/* Die Lava-Linie */
.forum-posts{position:relative;display:grid;gap:0;margin:0;padding:0;list-style:none}
.forum-eintrag{position:relative;display:grid;grid-template-columns:44px minmax(0,1fr);gap:10px;padding-bottom:18px;scroll-margin-top:90px}
.forum-eintrag::before{content:"";position:absolute;left:21px;top:46px;bottom:-2px;width:2px;border-radius:2px;background:var(--forum-line)}
.forum-eintrag:last-child::before{display:none}
.forum-eintrag.ist-neu::before{background:linear-gradient(var(--forum-lava),color-mix(in srgb,var(--forum-lava) 50%,var(--forum-line)))}
.forum-eintrag__spur{display:grid;justify-items:center;align-content:start;gap:6px;padding-top:6px}
.forum-eintrag__spur .forum-avatar--gross{width:40px;height:40px;font-size:.8rem}
.forum-eintrag__neu{position:relative;z-index:1;padding:2px 3px;border-radius:4px;background:var(--forum-bg);color:var(--forum-link);font-size:.62rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.forum-eintrag--frisch>.forum-post{animation:forum-frisch 1.6s ease-out}
@keyframes forum-frisch{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--forum-lava) 55%,transparent);transform:translateY(8px);opacity:.4}25%{transform:none;opacity:1}100%{box-shadow:0 0 0 12px transparent}}
.forum-post{position:relative;display:grid;min-width:0;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface);scroll-margin-top:90px;transition:box-shadow var(--forum-tempo),border-color var(--forum-tempo)}
.forum-post:hover{box-shadow:var(--forum-schatten)}
.forum-post:target,.forum-post.ist-fokus{border-color:var(--forum-lava);box-shadow:0 0 0 3px color-mix(in srgb,var(--forum-lava) 22%,transparent)}
.forum-post--neu{border-color:color-mix(in srgb,var(--forum-lava) 45%,var(--forum-line))}
.forum-post--eigene{background:color-mix(in srgb,var(--forum-lava) 4%,var(--forum-surface))}
.forum-post--offiziell{border-color:color-mix(in srgb,var(--forum-accent) 55%,var(--forum-line))}
.forum-post--beste{border-color:color-mix(in srgb,var(--forum-ok) 60%,var(--forum-line));box-shadow:0 0 0 3px color-mix(in srgb,var(--forum-ok) 14%,transparent)}
.forum-post--wartet{border-style:dashed;border-color:color-mix(in srgb,var(--forum-warn) 55%,var(--forum-line));background:repeating-linear-gradient(-45deg,var(--forum-surface) 0 12px,color-mix(in srgb,var(--forum-warn-bg) 55%,var(--forum-surface)) 12px 24px)}
.forum-post__band{display:flex;align-items:center;gap:8px;margin:0;padding:8px 16px;border-radius:calc(var(--forum-r-gross) - 1px) calc(var(--forum-r-gross) - 1px) 0 0;font-size:.82em;font-weight:700}
.forum-post__band--beste{background:var(--forum-ok-bg);color:var(--forum-ok)}
.forum-post__band--offiziell{background:color-mix(in srgb,var(--forum-accent) 10%,var(--forum-surface));color:var(--forum-link)}
.forum-post__head{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px;margin:0;padding:12px 16px 0;font-size:.9em}
.forum-post__name{margin:0;font-weight:700}
.forum-post__name a{color:var(--forum-text);text-decoration:none}
.forum-post__name a:hover{color:var(--forum-link);text-decoration:underline}
.forum-post__beitraege{display:none;color:var(--forum-quiet);font-size:.92em}
.forum-post__zeit{display:inline-flex;align-items:center;gap:8px;width:100%;color:var(--forum-quiet);font-size:.92em}
.forum-post__zeit a{color:var(--forum-quiet);text-decoration:none;font-variant-numeric:tabular-nums}
.forum-post__zeit a:hover{color:var(--forum-link)}
.forum-post__body{display:grid;gap:10px;min-width:0;padding:8px 16px 4px;overflow-wrap:anywhere}
.forum-post__text{font-size:1rem;line-height:1.65}
.forum-post__text>:first-child{margin-top:0}.forum-post__text>:last-child{margin-bottom:0}
.forum-post__text p{margin:.35em 0 .75em}
.forum-post__text blockquote{margin:.5em 0;padding-left:12px;border-left:3px solid var(--forum-line);color:var(--forum-muted)}
.forum-post__text li>p{margin:.15em 0}
.forum-post__text pre,.forum-post__text code{font-family:var(--font-code,ui-monospace,monospace);font-size:.9em}
.forum-post__text :not(pre)>code{padding:1px 6px;border-radius:6px;background:var(--forum-sanft)}
.forum-post__text .forum-quote{margin:.6em 0;padding:10px 14px;border-left:3px solid var(--forum-lava);border-radius:0 var(--forum-r) var(--forum-r) 0;background:var(--forum-sanft);color:inherit}
.forum-quote__kopf{margin:0 0 4px;font-size:.85em;font-weight:700}
.forum-spoiler{margin:.6em 0;padding:8px 14px;border:1px dashed color-mix(in srgb,var(--forum-text) 30%,var(--forum-line));border-radius:var(--forum-r)}
.forum-spoiler summary{cursor:pointer;font-weight:600}
/* TB 49: Formate im Beitrag — Überschriften, Ausrichtung, Markierstift, Tabellen, Code mit Kopf und Zeilennummern */
.forum-post__text .forum-ueberschrift{margin:.9em 0 .35em;line-height:1.25;letter-spacing:-.01em}
.forum-post__text h2.forum-ueberschrift{font-size:1.35em}.forum-post__text h3.forum-ueberschrift{font-size:1.18em}.forum-post__text h4.forum-ueberschrift{font-size:1.05em}
.forum-text--mitte{text-align:center}.forum-text--rechts{text-align:right}.forum-text--block{text-align:justify;hyphens:auto}
.forum .forum-markiert{padding:0 2px;border-radius:3px;color:#23262b}
.forum-markiert--gelb{background:#f7d867}.forum-markiert--gruen{background:#9fe0a8}.forum-markiert--blau{background:#9cc8f5}.forum-markiert--rot{background:#f5a3a3}.forum-markiert--violett{background:#cdb4f2}
.forum-post__text sub,.forum-post__text sup{font-size:.75em;line-height:0}
.forum-tabelle{margin:.7em 0;border:1px solid var(--forum-line);border-radius:var(--forum-r);overflow:hidden}
.forum-tabelle table{width:100%;margin:0;border-collapse:collapse;table-layout:fixed;font-size:.95em}
.forum-tabelle th,.forum-tabelle td{height:2.6em;padding:8px 10px;border:0;border-bottom:1px solid var(--forum-line);border-right:1px solid var(--forum-line);vertical-align:top;text-align:left;overflow-wrap:anywhere}
.forum-tabelle tr>:last-child{border-right:0}.forum-tabelle tr:last-child>*{border-bottom:0}
.forum-tabelle th{background:var(--forum-sanft);font-weight:700}
.forum-tabelle p{margin:0}
.forum-code{--hl-kw:color-mix(in srgb,#b53f1d 78%,var(--forum-text));--hl-str:color-mix(in srgb,#1f7a45 78%,var(--forum-text));--hl-num:color-mix(in srgb,#9a5b00 75%,var(--forum-text));--hl-fn:color-mix(in srgb,#2557a7 78%,var(--forum-text));--hl-typ:color-mix(in srgb,#7b3fa7 78%,var(--forum-text));margin:.7em 0;border:1px solid var(--forum-line);border-radius:var(--forum-r);overflow:hidden;background:color-mix(in srgb,var(--forum-text) 3%,var(--forum-surface))}
.forum-code__kopf{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;min-height:36px;margin:0;padding:4px 6px 4px 12px;border-bottom:1px solid var(--forum-line);background:var(--forum-sanft);font-size:.8em}
.forum-code__titel{font-family:var(--font-code,ui-monospace,monospace);font-weight:700;overflow-wrap:anywhere}
.forum-code__sprache{padding:1px 8px;border-radius:999px;background:var(--forum-surface);color:var(--forum-muted);font-weight:600}
.forum .forum-code__kopieren{margin:0 0 0 auto;min-height:28px;padding:0 10px;border:1px solid var(--forum-line);border-radius:999px;background:var(--forum-surface);color:var(--forum-text);font:inherit;font-weight:600;cursor:pointer;justify-self:auto}
.forum .forum-code__kopieren:hover{border-color:var(--forum-lava)}
.forum-code__rahmen{display:flex;overflow-x:auto}
.forum-code__zeilen{flex:none;padding:12px 10px 12px 12px;border-right:1px solid var(--forum-line);color:var(--forum-quiet);font-family:var(--font-code,ui-monospace,monospace);font-size:.9em;line-height:1.6;text-align:right;white-space:pre;user-select:none}
.forum-post__text .forum-code pre,.forum-code pre{flex:1;min-width:0;margin:0;padding:12px 14px;background:none;font-size:.9em;line-height:1.6;white-space:pre}
.forum-post__text .forum-code pre code,.forum-code pre code{padding:0;background:none;font-size:1em}
.forum-code .hljs-keyword,.forum-code .hljs-selector-tag,.forum-code .hljs-literal,.forum-code .hljs-section,.forum-code .hljs-doctag,.forum-code .hljs-meta .hljs-keyword{color:var(--hl-kw);font-weight:600}
.forum-code .hljs-string,.forum-code .hljs-regexp,.forum-code .hljs-addition,.forum-code .hljs-template-tag{color:var(--hl-str)}
.forum-code .hljs-number,.forum-code .hljs-attr,.forum-code .hljs-attribute,.forum-code .hljs-variable,.forum-code .hljs-template-variable,.forum-code .hljs-symbol,.forum-code .hljs-bullet{color:var(--hl-num)}
.forum-code .hljs-title,.forum-code .hljs-title.function_,.forum-code .hljs-name,.forum-code .hljs-selector-id,.forum-code .hljs-selector-class{color:var(--hl-fn)}
.forum-code .hljs-type,.forum-code .hljs-title.class_,.forum-code .hljs-built_in,.forum-code .hljs-meta,.forum-code .hljs-tag{color:var(--hl-typ)}
.forum-code .hljs-comment,.forum-code .hljs-quote{color:var(--forum-quiet);font-style:italic}
.forum-code .hljs-deletion{background:color-mix(in srgb,#b8232f 14%,transparent)}.forum-code .hljs-addition{background:color-mix(in srgb,#2a744a 14%,transparent)}
.forum-code .hljs-emphasis{font-style:italic}.forum-code .hljs-strong{font-weight:700}
/* TB 49: Wer schreibt gerade — an der Lava-Linie und in der Ecke des Editors */
.forum-schreibt{display:grid;grid-template-columns:44px minmax(0,1fr);gap:10px;align-items:center;margin:-4px 0 16px}
.forum-schreibt:has(>.insel-schreibt[hidden]){display:none}
.forum-schreibt__spur{display:grid;place-items:center;width:30px;height:28px;margin:0 auto;clip-path:var(--forum-hex);background:color-mix(in srgb,var(--forum-lava) 14%,var(--forum-surface));color:var(--forum-lava)}
.forum-schreibt__spur svg{width:15px;height:15px}
.insel-schreibt{display:inline-flex;align-items:center;gap:8px;justify-self:start;margin:0;padding:5px 12px;border:1px solid color-mix(in srgb,var(--forum-lava,#e4572e) 35%,var(--forum-line,#e3e6ea));border-radius:999px;background:var(--forum-surface,#fff);color:var(--forum-muted,#3b3f46);font-size:.86em;font-weight:600;line-height:1.3}
.insel-schreibt[hidden]{display:none}
.insel-schreibt--ecke{padding:3px 10px;font-size:.78em;box-shadow:0 6px 16px -10px rgb(0 0 0 / 40%)}
.insel-schreibt__punkte{display:inline-flex;gap:3px}
.insel-schreibt__punkte i{width:5px;height:5px;border-radius:50%;background:var(--forum-lava,#e4572e);animation:forum-tippen 1.2s infinite ease-in-out}
.insel-schreibt__punkte i:nth-child(2){animation-delay:.15s}.insel-schreibt__punkte i:nth-child(3){animation-delay:.3s}
@keyframes forum-tippen{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
.forum-mention{color:var(--forum-link);font-weight:600}
/* TB 52: Pinnwand im Profil */
.community-pinnwand{display:grid;gap:12px;margin:14px 0 0;padding:0;list-style:none}
.community-pinnwand__eintrag{display:grid;grid-template-columns:40px minmax(0,1fr);gap:10px;padding:12px 14px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface)}
.community-pinnwand__eintrag:target{border-color:var(--forum-lava);box-shadow:0 0 0 3px color-mix(in srgb,var(--forum-lava) 20%,transparent)}
.community-pinnwand__inhalt{display:grid;gap:6px;min-width:0;overflow-wrap:anywhere}
.community-pinnwand__kopf{display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px;margin:0;font-size:.9em;color:var(--forum-quiet)}
.community-pinnwand__kopf b a{color:var(--forum-text);text-decoration:none}.community-pinnwand__kopf b a:hover{color:var(--forum-link);text-decoration:underline}
.forum-mention--gruppe{padding:0 4px;border-radius:4px;background:color-mix(in srgb,var(--forum-link) 12%,transparent)}
.forum mark{padding:0 2px;border-radius:3px;background:#f5d76e;color:#23262b}
.forum-bild{display:block;margin:.6em 0}
.forum-bild img{max-width:100%;height:auto;border-radius:var(--forum-r)}
.forum-anhaenge{display:grid;gap:6px;font-size:.9em}
.forum-anhaenge p{margin:0;color:var(--forum-quiet);font-size:.82em;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
.forum-anhaenge ul{display:flex;flex-wrap:wrap;gap:8px;list-style:none;margin:0;padding:0}
.forum-anhaenge img{display:block;border:1px solid var(--forum-line);border-radius:var(--forum-r)}
.forum-post__bearbeitet{margin:0;color:var(--forum-quiet);font-size:.8em}
.forum-post__signature{padding-top:8px;border-top:1px dashed var(--forum-line);color:var(--forum-quiet);font-size:.84em;white-space:pre-line}
.forum-felder{display:grid;gap:4px 16px;margin:0;padding:10px 14px;border-radius:var(--forum-r);background:var(--forum-sanft);font-size:.92em}
.forum-felder div{display:flex;flex-wrap:wrap;gap:4px 12px}
.forum-felder dt{min-width:10rem;font-weight:600}
.forum-felder dd{margin:0;overflow-wrap:anywhere}
.forum-post__fuss{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:6px 10px 10px 14px}
.forum-post__werkzeuge{display:flex;flex-wrap:wrap;align-items:center;gap:2px;margin-left:auto}
.forum-post__werkzeuge .forum-aktion,.forum-post__werkzeuge .forum-menue>summary{min-height:38px}
.forum-post__werkzeuge .forum-aktion span{display:none}
/* Reaktionen: Zählung als Chips, Auswahl im Popover */
.forum-reaktionen{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.forum-reaktionen__summe{display:flex;flex-wrap:wrap;gap:6px;margin:0}
.forum-reaktionen__summe:empty{display:none}
.forum-reaktion{display:inline-flex;align-items:center;gap:5px;min-height:32px;padding:0 10px;border:1px solid var(--forum-line);border-radius:999px;background:var(--forum-surface);font-size:.86em;line-height:1}
.forum-reaktion b{color:var(--forum-muted);font-variant-numeric:tabular-nums}
.forum-reaktion.ist-meine{border-color:var(--forum-lava);background:color-mix(in srgb,var(--forum-lava) 10%,var(--forum-surface))}
.forum-reaktion.ist-meine b{color:var(--forum-text)}
.forum-reagieren-menue{position:relative}
.forum-reagieren-menue>summary{list-style:none;display:inline-grid;place-items:center;width:34px;height:34px;border:1px dashed color-mix(in srgb,var(--forum-text) 25%,var(--forum-line));border-radius:999px;color:var(--forum-quiet);cursor:pointer;transition:background var(--forum-tempo),color var(--forum-tempo)}
.forum-reagieren-menue>summary::-webkit-details-marker{display:none}
.forum-reagieren-menue>summary .forum-icon{width:18px;height:18px}
.forum-reagieren-menue>summary:hover,.forum-reagieren-menue[open]>summary{border-style:solid;background:var(--forum-hover);color:var(--forum-text)}
.forum-reagieren{position:absolute;left:0;bottom:calc(100% + 8px);z-index:20;display:flex;gap:2px;margin:0;padding:6px;border:1px solid var(--forum-line);border-radius:999px;background:var(--forum-surface);box-shadow:0 18px 40px -16px rgb(0 0 0 / 45%);animation:forum-blase .18s cubic-bezier(.2,.9,.3,1.3)}
@keyframes forum-blase{from{opacity:0;transform:translateY(6px) scale(.9)}to{opacity:1;transform:none}}
.forum .forum-reaktion-knopf{display:grid;place-items:center;width:42px;height:42px;margin:0;padding:0;border:0;border-radius:50%;background:transparent;font-size:1.35rem;line-height:1;cursor:pointer;transition:transform .15s cubic-bezier(.2,.9,.3,1.4),background var(--forum-tempo)}
.forum .forum-reaktion-knopf:hover{transform:translateY(-3px) scale(1.18);background:var(--forum-hover)}
.forum .forum-reaktion-knopf[aria-pressed="true"]{background:color-mix(in srgb,var(--forum-lava) 16%,var(--forum-surface));box-shadow:inset 0 0 0 2px var(--forum-lava)}
/* Moderation am Beitrag */
.forum-post__moderation{display:grid;gap:10px;margin:0;padding:12px 16px;border-radius:calc(var(--forum-r-gross) - 1px) calc(var(--forum-r-gross) - 1px) 0 0;background:var(--forum-warn-bg);color:var(--forum-warn);font-size:.88em}
.forum-post__moderation--meldung{background:var(--forum-err-bg);color:var(--forum-err)}
.forum-post__moderation .forum-post__aktionen{margin:0}
.forum-post__wartet,.forum-post__meldungen p{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0;font-weight:700}
.forum-post__meldungen{display:grid;gap:6px}
.forum-post__meldungen ul{margin:0;padding-left:18px;color:var(--forum-text);font-weight:400}
.forum-post__aktionen{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0}
.forum-post__aktionen .forum-aktion{min-height:36px;font-size:.84em}
.forum-moderation__form{display:flex;flex-wrap:wrap;gap:8px 10px;align-items:flex-end;margin:0}
.forum-moderation__form label{display:grid;gap:3px;color:var(--forum-muted);font-size:.86em}
.forum .forum-moderation__form .community-check,.forum .forum-auswahlleiste .community-check{display:flex;flex-direction:row;align-items:center;gap:6px}
.forum-moderation__form fieldset{display:grid;gap:2px;flex-basis:100%;margin:0;padding:0;border:0}
.forum-moderation__form textarea,.forum-moderation__form select,.forum-moderation__form input[type=text]{width:100%;box-sizing:border-box}
.forum-moderation__form label:has(select),.forum-moderation__form label:has(input[type=text]){flex:1 1 12rem}
.forum-moderation__form textarea{flex-basis:100%}
.forum-moderation__form p{flex-basis:100%;margin:0;font-size:.86em}
.forum-melden{display:block}
.forum-melden>summary{list-style:none;cursor:pointer}
.forum-melden>summary::-webkit-details-marker{display:none}
.forum-post__aktionen>.forum-melden>summary{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 12px;border:1px solid var(--forum-line);border-radius:var(--forum-r);background:var(--forum-surface);color:var(--forum-text);font-size:.84em;font-weight:600}
.forum-post__aktionen>.forum-melden[open]{flex-basis:100%;display:grid;gap:8px;padding:8px;border:1px solid var(--forum-line);border-radius:var(--forum-r);background:var(--forum-surface);color:var(--forum-text)}
/* Seite des Themas: Moderation, Beteiligte, Kompass */
.forum-moderation{border-color:color-mix(in srgb,var(--forum-text) 22%,var(--forum-line));scroll-margin-top:90px}
.forum-moderation>h2 .forum-icon{color:var(--forum-lava)}
.forum-kacheln{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
.forum-kacheln form{display:grid;margin:0}
.forum .forum-kachel{display:flex;align-items:center;gap:9px;width:100%;min-height:52px;margin:0;padding:8px 10px;border:1px solid var(--forum-line);border-radius:var(--forum-r);background:var(--forum-surface);color:var(--forum-muted);font:inherit;font-size:.8rem;font-weight:600;line-height:1.2;text-align:left;cursor:pointer;transition:background var(--forum-tempo),border-color var(--forum-tempo),color var(--forum-tempo),transform var(--forum-tempo)}
.forum .forum-kachel .forum-icon{width:18px;height:18px}
.forum-kachel>span{display:grid;gap:1px;min-width:0;hyphens:auto;overflow-wrap:break-word}
.forum .forum-kachel:hover{border-color:color-mix(in srgb,var(--forum-text) 35%,var(--forum-line));color:var(--forum-text);background:var(--forum-hover)}
.forum .forum-kachel.ist-aktiv{background:var(--forum-text);border-color:var(--forum-text);color:var(--forum-surface)}
.forum .forum-kachel.ist-aktiv .forum-icon{color:var(--forum-lava)}
.forum-kachel small{font-size:.7rem;font-weight:500;opacity:.75}
.forum .forum-kachel--gut{background:#2a744a;border-color:#2a744a;color:#fff}
.forum-klappen{display:grid;gap:4px}
.forum-klappe{border-radius:var(--forum-r);background:var(--forum-sanft)}
.forum-klappe>summary{list-style:none;display:flex;align-items:center;gap:10px;min-height:42px;padding:0 12px;border-radius:var(--forum-r);color:var(--forum-text);font-size:.86em;font-weight:600;cursor:pointer}
.forum-klappe>summary::-webkit-details-marker{display:none}
.forum-klappe>summary:hover{background:var(--forum-hover)}
.forum-klappe>summary::after{content:"";width:7px;height:7px;margin-left:auto;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg) translate(-2px,-2px);transition:transform var(--forum-tempo);opacity:.6}
.forum-klappe[open]>summary::after{transform:rotate(225deg) translate(-2px,-2px)}
.forum-klappe>.forum-moderation__form{padding:4px 12px 12px}
.forum-klappe--gefahr>summary{color:var(--forum-err)}
.forum-personen{display:grid;gap:8px;margin:0;padding:0;list-style:none;font-size:.88em}
.forum-personen li{display:flex;align-items:center;gap:10px;min-width:0}
.forum-personen a{overflow:hidden;color:var(--forum-text);font-weight:600;text-decoration:none;white-space:nowrap;text-overflow:ellipsis}
.forum-personen a:hover{color:var(--forum-link);text-decoration:underline}
.forum-personen small{margin-left:auto;color:var(--forum-quiet);font-variant-numeric:tabular-nums;white-space:nowrap}
.forum-kompass{position:sticky;top:16px}
.forum-waben{display:grid;grid-template-columns:repeat(8,27px);grid-auto-rows:30px;gap:2px 1px;margin:0;padding:0 0 16px;list-style:none}
.forum-waben li{display:grid;width:27px}
.forum-waben li:nth-child(2n){transform:translateY(15px)}
.forum-waben a{--wabe:var(--forum-line);position:relative;display:grid;place-items:center;width:35px;height:31px;margin-left:-4px;color:var(--forum-muted);font-size:.7rem;font-weight:700;text-decoration:none;font-variant-numeric:tabular-nums;transition:transform var(--forum-tempo)}
.forum-waben a::before{content:"";position:absolute;inset:0;clip-path:var(--forum-hex);background:var(--wabe);transition:background var(--forum-tempo)}
.forum-waben a span{position:relative}
.forum-waben a:hover{transform:scale(1.12)}
.forum-waben .ton-1{--wabe:color-mix(in srgb,#d0643c 28%,var(--forum-surface))}.forum-waben .ton-2{--wabe:color-mix(in srgb,#4f8c70 28%,var(--forum-surface))}.forum-waben .ton-3{--wabe:color-mix(in srgb,#4f7fb8 28%,var(--forum-surface))}.forum-waben .ton-4{--wabe:color-mix(in srgb,#9363b0 28%,var(--forum-surface))}.forum-waben .ton-5{--wabe:color-mix(in srgb,#b98336 28%,var(--forum-surface))}.forum-waben .ton-6{--wabe:color-mix(in srgb,#6b7883 28%,var(--forum-surface))}
.forum-waben a{color:var(--forum-text)}
.forum-waben .ist-neu{--wabe:var(--forum-lava);color:#fff}
.forum-waben .ist-beste{--wabe:#2a744a;color:#fff}
.forum-waben .ist-offiziell{--wabe:var(--forum-text);color:var(--forum-lava)}
.forum-waben .ist-wartend{--wabe:repeating-linear-gradient(-45deg,#e6b45c 0 4px,#fbf1dc 4px 8px);color:#4a3000}
.forum-waben a[aria-current]{z-index:1;transform:scale(1.22);filter:drop-shadow(0 3px 5px rgb(0 0 0 / 35%));font-weight:800}
.forum-waben .ist-frisch::before{animation:forum-wabe 1.4s ease-out 2}
@keyframes forum-wabe{50%{filter:brightness(1.35)}}
.forum-legende{display:flex;flex-wrap:wrap;gap:4px 12px;margin:0;color:var(--forum-quiet);font-size:.74rem}
.forum-legende span{display:inline-flex;align-items:center;gap:5px}
.forum-legende i{width:10px;height:9px;clip-path:var(--forum-hex)}
.forum-spruenge{display:grid;gap:6px}
.forum-spruenge a{display:flex;align-items:center;gap:8px;min-height:40px;padding:0 12px;border-radius:var(--forum-r);background:var(--forum-sanft);color:var(--forum-text);font-size:.86em;font-weight:600;text-decoration:none;transition:background var(--forum-tempo)}
.forum-spruenge a:hover{background:var(--forum-hover)}
.forum-spruenge a b{margin-left:auto;color:var(--forum-link)}
.forum-tasten{display:none;margin:0;color:var(--forum-quiet);font-size:.74rem}
.forum-legende .ist-neu{background:var(--forum-lava)}.forum-legende .ist-beste{background:#2a744a}.forum-legende .ist-wartend{background:#e6b45c}.forum-legende .ist-offiziell{background:var(--forum-text)}
.forum-tasten kbd{display:inline-grid;place-items:center;min-width:20px;height:20px;padding:0 5px;margin:0 2px;border:1px solid var(--forum-line);border-bottom-width:2px;border-radius:5px;background:var(--forum-surface);color:var(--forum-text);font:600 .7rem/1 var(--font-code,ui-monospace,monospace)}
.forum-kompass[data-kompass-aktiv] .forum-tasten{display:block}
/* Antworten */
.forum-antwort{display:grid;grid-template-columns:44px minmax(0,1fr);gap:10px}
.forum-antwort>.forum-eintrag__spur .forum-avatar--gross{width:40px;height:40px}
.forum-antwort--gast{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:18px;border:1px dashed color-mix(in srgb,var(--forum-text) 25%,var(--forum-line));border-radius:var(--forum-r-gross);background:var(--forum-surface)}
.forum-antwort--gast p{margin:0;font-weight:600}
.forum-form{display:grid;gap:14px;min-width:0;padding:18px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface)}
.forum .forum-form{max-width:none}
.forum-form h2{display:flex;align-items:center;gap:8px;margin:0;font-size:1.02rem}
.forum-form input[type=text],.forum-form textarea{width:100%;box-sizing:border-box}
.forum-form .form-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0}
.forum-zusatz{padding:10px 14px;border:1px dashed color-mix(in srgb,var(--forum-text) 25%,var(--forum-line));border-radius:var(--forum-r)}
.forum-zusatz summary{cursor:pointer;font-weight:600}
.forum-zusatz[open]{display:grid;gap:12px}
.forum-aehnlich{padding:10px 14px;border-left:3px solid var(--forum-lava);border-radius:0 var(--forum-r) var(--forum-r) 0;background:var(--forum-sanft);font-size:.9em}
.forum-aehnlich[hidden]{display:none!important}
.forum-aehnlich p{margin:0 0 4px;font-weight:600}
.forum-aehnlich ul{margin:0;padding-left:18px}
/* Live: Hinweis auf Neues */
.forum-live{position:sticky;bottom:14px;z-index:12;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 14px;margin:0;padding:10px 10px 10px 18px;border-radius:var(--forum-r-gross);background:var(--color-dark,#23262b);color:var(--color-dark-text,#f6f4f0);box-shadow:0 22px 50px -18px rgb(0 0 0 / 55%);font-weight:600;animation:forum-hoch .25s ease-out}
.forum-live>span{display:inline-flex;align-items:center;gap:10px}
.forum-live>span::before{content:"";flex:none;width:9px;height:9px;border-radius:50%;background:var(--forum-lava);box-shadow:0 0 0 4px rgb(228 87 46 / 30%);animation:forum-puls-lava 1.8s ease-out infinite}
@keyframes forum-puls-lava{0%{box-shadow:0 0 0 0 rgb(228 87 46 / 55%)}70%{box-shadow:0 0 0 9px rgb(228 87 46 / 0%)}100%{box-shadow:0 0 0 0 rgb(228 87 46 / 0%)}}
.forum-live .forum-button{min-height:36px;background:var(--forum-lava);color:#15171a!important;box-shadow:none}
/* Seitenwahl */
.forum-pages ul{display:flex;flex-wrap:wrap;gap:4px;list-style:none;margin:0;padding:0}
.forum-pages a,.forum-pages span{display:inline-grid;place-items:center;min-width:40px;height:40px;padding:0 10px;border:1px solid var(--forum-line);border-radius:var(--forum-r);background:var(--forum-surface);color:var(--forum-text);font-weight:600;text-decoration:none;font-variant-numeric:tabular-nums}
.forum-pages a:hover{border-color:color-mix(in srgb,var(--forum-text) 35%,var(--forum-line))}
.forum-pages [aria-current]{background:var(--forum-text);border-color:var(--forum-text);color:var(--forum-surface)}
.forum-pages__gap{display:grid;place-items:center;min-width:24px;color:var(--forum-quiet)}
/* Umfrage */
.forum-umfrage{display:grid;gap:8px;margin:0;padding:14px 16px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-sanft)}
.forum-umfrage h3{display:flex;align-items:center;gap:8px;margin:0;font-size:1rem}
.forum-umfrage__info{margin:0;color:var(--forum-quiet);font-size:.84em}
.forum-umfrage__wahl{display:flex;gap:10px;align-items:center;min-height:40px;padding:0 10px;border-radius:var(--forum-r);background:var(--forum-surface);cursor:pointer}
.forum-umfrage fieldset{display:grid;gap:6px;margin:0;padding:0;border:0}
.forum-umfrage__ergebnis{display:grid;gap:10px;margin:0;padding:0;list-style:none}
.forum-umfrage__ergebnis li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 10px;align-items:center}
.forum-umfrage__balken{grid-column:1/-1;height:10px;border-radius:999px;background:var(--forum-line);overflow:hidden}
.forum-umfrage__balken span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--forum-accent),var(--forum-lava));transform-origin:left;animation:forum-balken .8s cubic-bezier(.2,.7,.3,1)}
@keyframes forum-balken{from{transform:scaleX(0)}to{transform:none}}
.forum-umfrage__zahl{grid-row:1;grid-column:2;color:var(--forum-quiet);font-size:.82em;font-variant-numeric:tabular-nums}
.forum-umfrage .ist-gewaehlt .forum-umfrage__text{font-weight:700}
/* Verlauf, Fälle, Nachrichten */
.forum-fassungen{display:grid;gap:12px;margin:0;padding:0;list-style:none}
.forum-fassung{padding:14px 18px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface)}
.forum-fassung__kopf{margin:0 0 6px;color:var(--forum-quiet);font-size:.88em}
.forum-fassung__titel{margin:0 0 6px;font-weight:600}
.forum-faelle{display:grid;margin:0;padding:0;list-style:none}
.forum-fall{display:grid;gap:6px;padding:16px 18px;border-top:1px solid var(--forum-line)}
.forum-fall:first-child{border-top:0}
.forum-fall:has(>.forum-sechseck,>.forum-avatar){grid-template-columns:auto minmax(0,1fr);column-gap:14px}
.forum-fall:has(>.forum-sechseck,>.forum-avatar)>*{grid-column:2;min-width:0}
.forum-fall>.forum-sechseck,.forum-fall>.forum-avatar{grid-column:1!important;grid-row:1 / span 4;width:40px;height:40px}
.forum-fall p,.forum-fall blockquote{margin:0}
.forum-fall__kopf{color:var(--forum-quiet);font-size:.88em}
.forum-fall__kopf b,.forum-fall__kopf a{color:var(--forum-text);font-weight:700}
.forum-fall__kopf a{color:var(--forum-link)}
.forum-fall blockquote{padding:8px 12px;border-left:3px solid var(--forum-line);border-radius:0 var(--forum-r) var(--forum-r) 0;background:var(--forum-sanft);color:var(--forum-muted);overflow-wrap:anywhere}
.forum-fall .forum-post__aktionen{margin-top:6px}
.forum-nachrichten{display:grid;margin:0;padding:0;list-style:none}
.forum-nachricht{position:relative;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:2px 14px;align-items:center;padding:14px 18px;border-top:1px solid var(--forum-line);transition:background var(--forum-tempo)}
.forum-nachricht:first-child{border-top:0}
.forum-nachricht:hover{background:color-mix(in srgb,var(--forum-text) 2.5%,var(--forum-surface))}
.forum-nachricht>.forum-sechseck,.forum-nachricht>.forum-stapel{grid-row:1 / span 2}
.forum-nachricht>a{grid-column:2;color:var(--forum-text);font-weight:600;text-decoration:none;overflow-wrap:anywhere}
.forum-nachricht>a:hover{color:var(--forum-link);text-decoration:underline}
.forum-nachricht>p{grid-column:2;margin:0;color:var(--forum-quiet);font-size:.88em}
.forum-nachricht>span:last-child{grid-column:3;grid-row:1 / span 2;color:var(--forum-quiet);font-size:.8em;text-align:right;white-space:nowrap}
.forum-nachricht--neu::before{content:"";position:absolute;left:0;top:14px;bottom:14px;width:3px;border-radius:0 3px 3px 0;background:var(--forum-lava)}
.forum-nachricht--neu>a{font-weight:800}
.forum-status{margin:0;padding:12px 16px;border:1px solid color-mix(in srgb,var(--forum-ok) 40%,var(--forum-line));border-radius:var(--forum-r-gross);background:var(--forum-ok-bg);color:var(--forum-ok);font-weight:600}
.community-hinweis{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;padding:12px 16px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-sanft)}
.community-hinweis p{margin:0}
.community-hinweis--warnung{border-color:color-mix(in srgb,var(--forum-err) 40%,var(--forum-line));background:var(--forum-err-bg);color:var(--forum-err)}
.community-hinweis--erfolg{border-color:color-mix(in srgb,var(--forum-ok) 40%,var(--forum-line));background:var(--forum-ok-bg);color:var(--forum-ok)}
.community-hinweis__aus{margin:0}
.forum .community-hinweis__aus button{display:grid;place-items:center;min-width:36px;min-height:36px;margin:0;padding:0;border:0;border-radius:var(--forum-r);background:transparent;color:inherit;font-size:1.2em;cursor:pointer;justify-self:auto}
.forum .community-hinweis__aus button:hover{background:color-mix(in srgb,currentColor 10%,transparent)}
.forum-blockiert{padding:10px 14px;border:1px dashed var(--forum-line);border-radius:var(--forum-r-gross);color:var(--forum-quiet)}
.forum-blockiert summary{cursor:pointer;font-size:.9em}
.forum-blockiert[open] .forum-post{margin-top:10px}
/* Profil */
.community-profil{border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface);overflow:hidden;box-shadow:var(--forum-schatten)}
.community-profil__cover img{display:block;width:100%;height:auto;aspect-ratio:15/4;object-fit:cover}
.community-profil__cover--leer{height:110px;background:radial-gradient(circle at 85% 20%,color-mix(in srgb,#e4572e 60%,transparent),transparent 45%),linear-gradient(110deg,var(--color-dark,#23262b) 40%,color-mix(in srgb,var(--color-dark,#23262b) 70%,#e4572e))}
.community-profil__kopf{display:grid;gap:14px;padding:0 18px 18px}
.community-profil__kopf>.forum-avatar,.community-profil__kopf>.forum-portrait{margin-top:-52px}
.community-profil__kopf>.forum-avatar{filter:drop-shadow(0 0 0 var(--forum-surface)) drop-shadow(0 6px 14px rgb(0 0 0 / 25%))}
.community-profil__name h2{margin:0;font-size:1.5rem;letter-spacing:-.01em}
.community-profil__name p{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:6px 0 0;color:var(--forum-quiet);font-size:.9em}
.community-profil__zahlen{display:flex;flex-wrap:wrap;gap:12px 28px;margin:0}
.community-profil__zahlen div{display:flex;flex-direction:column-reverse;line-height:1.2}
.community-profil__zahlen dt{color:var(--forum-quiet);font-size:.76rem}
.community-profil__zahlen dd{margin:0;font-size:1.2rem;font-weight:700;font-variant-numeric:tabular-nums}
.community-reiter__inhalt,.community-reiter>p,.community-reiter>.forum-felder{padding:16px 18px}
.community-reiter h3{margin:16px 18px 0;font-size:1rem}
.community-ueber{margin-bottom:12px;white-space:normal}
.community-karten{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));margin:0;padding:14px;list-style:none}
.community-karte{display:flex;gap:12px;align-items:center;min-width:0;padding:12px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface);transition:box-shadow var(--forum-tempo),transform var(--forum-tempo)}
.community-karte:hover{box-shadow:var(--forum-schatten);transform:translateY(-1px)}
.community-karte div{min-width:0}
.community-karte a{color:var(--forum-text);text-decoration:none}
.community-karte a:hover{color:var(--forum-link)}
.community-karte p{display:flex;flex-wrap:wrap;gap:4px 6px;align-items:center;margin:4px 0 0;color:var(--forum-quiet);font-size:.84em;overflow-wrap:anywhere}
.community-trophaeen{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));margin:0;padding:16px 18px;list-style:none}
.community-trophaeen li{display:flex;gap:12px;align-items:flex-start;padding:12px;border-radius:var(--forum-r-gross);background:var(--forum-sanft)}
.community-trophaeen__icon{display:grid;place-items:center;flex:none;width:44px;height:44px;clip-path:var(--forum-hex);background:var(--forum-surface);font-size:1.4em;line-height:1}
.community-trophaeen p{margin:2px 0 0;color:var(--forum-quiet);font-size:.88em}
/* Suche */
.forum-suche{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:8px;border:1px solid var(--forum-line);border-radius:var(--forum-r-gross);background:var(--forum-surface);box-shadow:var(--forum-schatten)}
.forum .forum-suche input[type=search]{flex:1 1 14rem;min-width:0;min-height:44px;margin:0;padding:0 14px;border:0;border-radius:var(--forum-r);background:var(--forum-sanft);color:var(--forum-text);font:inherit;font-size:1.02em}
.forum-suche .forum-button{min-height:44px}
.forum-fassung .forum-post__text{font-size:.95rem}
@container forum (min-width:72rem){.forum-nav__ich span:not(.forum-avatar){position:static;width:auto;height:auto;overflow:visible;clip:auto}}
@media (prefers-reduced-motion:reduce){.forum *,.forum *::before,.forum *::after{animation:none!important;transition:none!important}}
@media (min-width:40rem){
.forum-nav__wege{display:flex;flex-wrap:wrap;gap:2px}
.forum-nav__weg{display:inline-flex;align-items:center;gap:7px;min-height:42px;padding:0 12px;font-size:.88rem}
.forum-nav__zahl{position:static;box-shadow:none;margin-left:2px}

.forum-held{padding:24px 24px 20px 28px}
.forum-held__zeichen{width:60px;height:60px}
.forum-post__werkzeuge .forum-aktion span{display:inline}
.forum-post__zeit{width:auto;margin-left:auto}
.forum-post__beitraege{display:inline}
.forum-eintrag,.forum-antwort{grid-template-columns:60px minmax(0,1fr);gap:14px;padding-bottom:20px}
.forum-schreibt{grid-template-columns:60px minmax(0,1fr);gap:14px}
.forum-antwort{padding-bottom:0}
.forum-eintrag::before{left:29px;top:62px}
.forum-eintrag__spur .forum-avatar--gross,.forum-antwort>.forum-eintrag__spur .forum-avatar--gross{width:56px;height:56px;font-size:1rem}
.forum-post::after{content:"";position:absolute;left:-7px;top:22px;width:12px;height:12px;border:solid var(--forum-line);border-width:0 0 1px 1px;background:var(--forum-surface);transform:rotate(45deg)}
.forum-post--neu::after{border-color:color-mix(in srgb,var(--forum-lava) 45%,var(--forum-line))}
.forum-post--offiziell::after{border-color:color-mix(in srgb,var(--forum-accent) 55%,var(--forum-line))}
.forum-post--beste::after{border-color:color-mix(in srgb,var(--forum-ok) 60%,var(--forum-line))}
.forum-post--wartet::after{border-style:dashed;border-color:color-mix(in srgb,var(--forum-warn) 55%,var(--forum-line))}
.forum-post:target::after,.forum-post.ist-fokus::after{border-color:var(--forum-lava)}
.forum-post:has(>.forum-post__band--beste)::after{background:var(--forum-ok-bg)}
.forum-post:has(>.forum-post__band--offiziell)::after{background:color-mix(in srgb,var(--forum-accent) 10%,var(--forum-surface))}
.forum-post:has(>.forum-post__moderation)::after{background:var(--forum-warn-bg)}
.forum-post:has(>.forum-post__moderation--meldung)::after{background:var(--forum-err-bg)}
.forum-puls{grid-template-columns:minmax(0,1fr) auto;align-items:center;padding:22px 24px}
.forum-puls .forum-kennzahlen{grid-column:1}
.forum-puls__knoepfe{grid-column:2;grid-row:1 / span 2;justify-content:flex-end}
}
@media (min-width:48rem){
.forum-nav{grid-template-columns:minmax(0,1fr) auto;align-items:center}
.forum-nav__konto{padding-top:0;border-top:0}
.forum-nav__suche{flex:0 1 15rem}
.forum-row{grid-template-columns:auto minmax(0,1fr) 9.5rem 12rem;align-items:center;gap:6px 16px;padding:14px 18px}
.forum-row__zahlen,.forum-row__last,.forum-row__letzte,.forum-row__kennzahlen{grid-column:auto}
.forum-row__zahlen{display:grid;gap:6px;font-size:.84em}
.forum-hitze{display:block}
.forum-row--forum{grid-template-columns:auto minmax(0,1fr) auto minmax(14rem,20rem)}
.forum-list--foren>.forum-row--link{grid-template-columns:auto minmax(0,1fr)}
.forum:has(#forum-themen-waehlen:checked) .forum-row--thema{grid-template-columns:auto auto minmax(0,1fr) 9.5rem 12rem}
.community-profil__kopf{grid-template-columns:auto minmax(0,1fr) auto;align-items:end}
.community-profil__zahlen{grid-column:1/-1}
.community-profil__kopf>.forum-post__aktionen{grid-column:3;grid-row:1;align-self:end}
.forum-band{grid-template-columns:1fr 1fr}
}
@media (min-width:64rem){
.forum-spalten{grid-template-columns:minmax(0,1fr) 19rem}
.forum-thema{grid-template-columns:minmax(0,1fr) 19rem;align-items:start}
.forum-thema__seite{align-self:stretch;grid-template-rows:auto}
.forum-thema__seite .forum-kompass{align-self:start}
}
@media (max-width:63.99rem){
.forum-kompass{display:none}
.forum-held__mod{display:inline-flex}
}
CSS;
}
