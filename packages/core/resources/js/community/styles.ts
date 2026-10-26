/**
 * Stil der Editor-Insel — schlicht und aus den Design-Tokens des Themes (`--color-*`, `--radius`), mit Rückfall auf
 * neutrale Werte, damit er in jedem Theme lesbar bleibt (auch dunkel, die Tokens kennen beide Darstellungen). Die Knöpfe
 * tragen zwei Klassen, damit Formularregeln eines Themes («.form button») sie nicht überdecken.
 */
export const EDITOR_CSS = `
.insel-editor{border:1px solid var(--color-border,#80868c);border-radius:var(--radius,6px);background:var(--color-surface,#fff);color:var(--color-text,#23262b);overflow:hidden}
.insel-editor:focus-within{outline:2px solid var(--color-focus,#b53f1d);outline-offset:1px}
.insel-editor__leiste{display:flex;flex-wrap:wrap;gap:2px;padding:4px;border-bottom:1px solid var(--color-line,#e3e6ea);background:var(--color-background,#f6f4f0)}
.insel-editor .insel-editor__knopf{justify-self:auto;min-width:36px;min-height:36px;height:36px;margin:0;padding:0 8px;border:0;border-radius:var(--radius,6px);background:transparent;color:inherit;font:inherit;font-weight:600;line-height:1;box-shadow:none;cursor:pointer}
.insel-editor .insel-editor__knopf:hover{background:var(--color-line,#e3e6ea);color:inherit}
.insel-editor .insel-editor__knopf[aria-pressed="true"]{background:var(--color-accent,#b53f1d);color:var(--color-accent-text,#fff)}
.insel-editor .insel-editor__knopf:focus-visible{outline:2px solid var(--color-focus,#b53f1d);outline-offset:1px}
.insel-editor__text{min-height:140px;padding:10px 12px;outline:none;line-height:1.55}
.insel-editor__text p{margin:0 0 .6em}
.insel-editor__text a{color:var(--color-link,#b53f1d)}
.insel-editor__text blockquote{margin:.4em 0;padding-left:12px;border-left:3px solid var(--color-line,#e3e6ea);color:var(--color-muted,#3b3f46)}
.insel-editor__text code{font-family:var(--font-code,ui-monospace,monospace);font-size:.92em;background:var(--color-background,#f6f4f0);padding:1px 4px;border-radius:4px}
`;

/** TB 37: Zitate, Spoiler, Einbettungen, Erwähnungen, Emojis, Anhänge und Namensvorschläge in der Insel. */
export const EDITOR_EXTRA_CSS = `
.insel-editor{position:relative}
.insel-editor [hidden],.insel-editor__zitate[hidden]{display:none!important}
.insel-editor__text blockquote[data-author]::before{content:attr(data-author) " ›";display:block;font-size:.85em;font-weight:600;color:var(--color-muted,#3b3f46)}
.insel-editor__text pre{background:var(--color-background,#f6f4f0);padding:8px 10px;border-radius:var(--radius,6px);font-family:var(--font-code,ui-monospace,monospace);font-size:.9em;white-space:pre-wrap}
.insel-editor__text .insel-spoiler{border:1px dashed var(--color-border,#80868c);border-radius:var(--radius,6px);padding:6px 10px;margin:.4em 0}
.insel-editor__text .insel-spoiler::before{content:"▸ " attr(data-title);display:block;font-size:.85em;font-weight:600;color:var(--color-muted,#3b3f46)}
.insel-editor__text .insel-embed{border:1px solid var(--color-line,#e3e6ea);border-radius:var(--radius,6px);padding:10px;font-size:.9em;overflow-wrap:anywhere;background:var(--color-background,#f6f4f0)}
.insel-editor__text .insel-mention{color:var(--color-link,#b53f1d);font-weight:600}
.insel-editor__text img{max-width:100%;height:auto;border-radius:var(--radius,6px)}
.insel-editor__text img.ProseMirror-selectednode{outline:2px solid var(--color-focus,#b53f1d)}
.insel-editor__emojis{display:grid;grid-template-columns:repeat(auto-fill,minmax(36px,1fr));gap:2px;padding:6px;border-bottom:1px solid var(--color-line,#e3e6ea);background:var(--color-surface,#fff)}
.insel-editor .insel-editor__emoji{min-height:36px;margin:0;padding:0;border:0;border-radius:var(--radius,6px);background:transparent;font-size:1.2em;line-height:1;cursor:pointer;justify-self:auto;color:inherit}
.insel-editor .insel-editor__emoji:hover{background:var(--color-line,#e3e6ea)}
.insel-editor__status{margin:0;padding:0 12px;font-size:.85em;color:var(--color-muted,#3b3f46)}
.insel-editor__status:empty{display:none}
.insel-editor__status{padding-block:6px}
.insel-editor__anhaenge{list-style:none;margin:0;padding:0 12px;display:flex;flex-wrap:wrap;gap:6px}
.insel-editor__anhaenge:empty{display:none}
.insel-editor__anhaenge{padding-block:6px;border-top:1px solid var(--color-line,#e3e6ea)}
.insel-editor__anhang{font-size:.88em;background:var(--color-background,#f6f4f0);border-radius:999px;padding:2px 4px 2px 10px}
.insel-editor .insel-editor__entfernen,.insel-editor .insel-editor__verwerfen{min-height:28px;margin:0;padding:0 8px;border:0;border-radius:999px;background:transparent;color:inherit;font:inherit;cursor:pointer;justify-self:auto}
.insel-editor .insel-editor__verwerfen{text-decoration:underline}
.insel-editor__namen{position:absolute;left:12px;right:12px;bottom:-4px;transform:translateY(100%);z-index:5;list-style:none;margin:0;padding:4px;background:var(--color-surface,#fff);border:1px solid var(--color-border,#80868c);border-radius:var(--radius,6px);box-shadow:0 8px 24px rgba(0,0,0,.12);max-width:20rem}
.insel-editor__name{padding:6px 10px;border-radius:var(--radius,6px);cursor:pointer}
.insel-editor__name[aria-selected="true"]{background:var(--color-accent,#b53f1d);color:var(--color-accent-text,#fff)}
.insel-editor__name--leer{cursor:default;color:var(--color-muted,#3b3f46)}
.insel-editor__zitate{margin-bottom:6px}
.insel-editor__zitate[hidden]{display:none}
.insel-editor__zitate .insel-editor__knopf-text{min-height:36px;margin:0;padding:0 12px;border:1px solid var(--color-border,#80868c);border-radius:var(--radius,6px);background:var(--color-surface,#fff);color:inherit;font:inherit;cursor:pointer;justify-self:auto}
`;

/**
 * TB 49: Werkzeugleiste wie WoltLab — Symbole, Trenner, geteilte Knöpfe mit Pfeil, Menüs mit Formularen, Raster für
 * Tabellen, Reiter unter dem Text, Vorschau und die Ecke «… schreibt». Alles umbricht statt zu scrollen.
 */
export const EDITOR_TOOLS_CSS = `
.insel-editor{overflow:visible}
.insel-editor__leiste{position:relative;align-items:center;gap:1px;padding:4px 6px;border-radius:var(--radius,6px) var(--radius,6px) 0 0}
.insel-editor>:last-child{border-bottom-left-radius:var(--radius,6px);border-bottom-right-radius:var(--radius,6px)}
.insel-editor .insel-editor__knopf{display:inline-flex;align-items:center;justify-content:center;gap:2px;min-width:34px;padding:0 7px;color:var(--color-muted,#3b3f46)}
.insel-editor .insel-editor__knopf:hover{color:var(--color-text,#23262b)}
.insel-editor .insel-editor__knopf:disabled{opacity:.4;cursor:default;background:transparent}
.insel-editor .insel-editor__knopf[aria-expanded="true"],.insel-editor .insel-editor__knopf.ist-aktiv{background:color-mix(in srgb,var(--color-accent,#b53f1d) 14%,transparent);color:var(--color-text,#23262b)}
.insel-icon{display:block;flex:none}
.insel-editor__trenner{width:1px;height:22px;margin:0 4px;background:var(--color-line,#e3e6ea)}
.insel-editor__gruppe{display:inline-flex;align-items:center}
.insel-editor__gruppe--geteilt .insel-editor__knopf:first-child{border-top-right-radius:0;border-bottom-right-radius:0}
.insel-editor .insel-editor__pfeil{min-width:20px;padding:0 3px;border-top-left-radius:0;border-bottom-left-radius:0}
.insel-editor .insel-editor__knopf--menue{padding:0 4px 0 7px}
.insel-menue{position:absolute;z-index:20;min-width:12rem;max-width:min(26rem,calc(100% - 8px));padding:6px;border:1px solid var(--color-border,#80868c);border-radius:calc(var(--radius,6px) + 2px);background:var(--color-surface,#fff);color:var(--color-text,#23262b);box-shadow:0 14px 32px -12px rgb(0 0 0 / 35%)}
.insel-menue__liste{display:grid;gap:1px}
.insel-menue__liste--quer{display:flex;flex-wrap:wrap}
.insel-editor .insel-menue__eintrag{display:flex;align-items:center;gap:8px;min-height:36px;margin:0;padding:0 10px;border:0;border-radius:var(--radius,6px);background:transparent;color:inherit;font:inherit;font-size:.92em;text-align:left;cursor:pointer;justify-self:stretch}
.insel-editor .insel-menue__liste--quer .insel-menue__eintrag{padding:0 8px}
.insel-editor .insel-menue__liste--quer .insel-menue__text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.insel-editor .insel-menue__eintrag:hover,.insel-editor .insel-menue__eintrag:focus-visible{background:var(--color-line,#e3e6ea);outline:none}
.insel-editor .insel-menue__eintrag[aria-checked="true"]{background:color-mix(in srgb,var(--color-accent,#b53f1d) 14%,transparent);font-weight:700}
.insel-editor .insel-menue__eintrag:disabled{opacity:.4;cursor:default}
.insel-menue__mehr{display:grid;gap:6px}
.insel-menue__zeile{display:flex;flex-wrap:wrap;align-items:center;gap:4px}
.insel-menue__titel{margin:2px 4px 0;color:var(--color-muted,#3b3f46);font-size:.78em;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.insel-menue__farben .insel-menue__eintrag{width:34px;padding:0;justify-content:center}
.insel-menue__farben .insel-menue__eintrag .insel-icon{width:16px;height:16px}
.insel-menue__farben .insel-menue__eintrag:not(:last-child)::before{content:"";width:18px;height:18px;border-radius:50%;background:var(--farbe);box-shadow:inset 0 0 0 1px rgb(0 0 0 / 15%)}
.insel-menue__farben .insel-menue__eintrag:not(:last-child) .insel-icon{display:none}
.insel-menue__farben .insel-menue__eintrag:nth-child(1){--farbe:#f7d867}.insel-menue__farben .insel-menue__eintrag:nth-child(2){--farbe:#9fe0a8}.insel-menue__farben .insel-menue__eintrag:nth-child(3){--farbe:#9cc8f5}.insel-menue__farben .insel-menue__eintrag:nth-child(4){--farbe:#f5a3a3}.insel-menue__farben .insel-menue__eintrag:nth-child(5){--farbe:#cdb4f2}
.insel-editor .insel-menue__auswahl,.insel-editor .insel-menue__eingabe{width:100%;min-height:36px;margin:0;padding:4px 8px;border:1px solid var(--color-border,#80868c);border-radius:var(--radius,6px);background:var(--color-surface,#fff);color:inherit;font:inherit;font-size:.92em}
.insel-editor .insel-menue__auswahl{width:auto;min-width:9.5rem}
.insel-menue__formular{display:grid;gap:10px;min-width:min(18rem,calc(100vw - 48px));padding:4px}
.insel-menue__feld{display:grid;gap:4px;font-size:.88em}
.insel-menue__feld label{font-weight:600}
.insel-menue__feld small{color:var(--color-muted,#3b3f46)}
.insel-menue__fehler{margin:0;color:var(--color-error,#a3271c);font-size:.85em}
.insel-menue__fehler:empty{display:none}
.insel-menue__aktionen{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:6px}
.insel-editor .insel-menue__knopf{display:inline-flex;align-items:center;gap:6px;min-height:34px;margin:0;padding:0 12px;border:1px solid var(--color-border,#80868c);border-radius:var(--radius,6px);background:var(--color-surface,#fff);color:inherit;font:inherit;font-size:.9em;font-weight:600;cursor:pointer;justify-self:auto}
.insel-editor .insel-menue__knopf--haupt{border-color:var(--color-accent,#b53f1d);background:var(--color-accent,#b53f1d);color:var(--color-accent-text,#fff)}
.insel-raster{display:grid;gap:6px;justify-items:center}
.insel-raster__felder{display:grid;gap:3px;padding:4px;border-radius:var(--radius,6px);outline:none}
.insel-raster__felder:focus-visible{outline:2px solid var(--color-focus,#b53f1d)}
.insel-raster__feld{width:16px;height:16px;border:1px solid var(--color-line,#e3e6ea);border-radius:3px;background:var(--color-background,#f6f4f0);cursor:pointer}
.insel-raster__feld.ist-gewaehlt{border-color:var(--color-accent,#b53f1d);background:color-mix(in srgb,var(--color-accent,#b53f1d) 25%,var(--color-surface,#fff))}
.insel-raster__groesse{margin:0;font-size:.85em;font-variant-numeric:tabular-nums;color:var(--color-muted,#3b3f46)}
.insel-emojis{display:grid;gap:2px}
.insel-editor .insel-emojis__emoji{min-width:34px;min-height:34px;margin:0;padding:0;border:0;border-radius:var(--radius,6px);background:transparent;font-size:1.2em;line-height:1;cursor:pointer;justify-self:auto;color:inherit}
.insel-editor .insel-emojis__emoji:hover,.insel-editor .insel-emojis__emoji:focus-visible{background:var(--color-line,#e3e6ea);outline:none}
.insel-reiter{display:flex;flex-wrap:wrap;border-top:1px solid var(--color-line,#e3e6ea);background:var(--color-background,#f6f4f0)}
.insel-editor .insel-reiter__knopf{display:inline-flex;align-items:center;gap:6px;min-height:40px;margin:0;padding:0 14px;border:0;border-right:1px solid var(--color-line,#e3e6ea);border-radius:0;background:transparent;color:var(--color-muted,#3b3f46);font:inherit;font-size:.9em;font-weight:600;cursor:pointer;justify-self:auto}
.insel-editor .insel-reiter__knopf:hover{color:var(--color-text,#23262b)}
.insel-editor .insel-reiter__knopf[aria-selected="true"]{background:var(--color-surface,#fff);color:var(--color-text,#23262b);box-shadow:inset 0 -2px 0 var(--color-accent,#b53f1d)}
.insel-editor .insel-reiter__knopf:focus-visible{outline:2px solid var(--color-focus,#b53f1d);outline-offset:-2px}
.insel-editor .insel-reiter__knopf--ende{margin-left:auto;border-right:0;border-left:1px solid var(--color-line,#e3e6ea)}
.insel-reiter__tafel{padding:12px;border-top:1px solid var(--color-line,#e3e6ea)}
.insel-reiter__tafel:empty{display:none}
.insel-reiter__anhaenge{display:grid;gap:8px;justify-items:start}
.insel-reiter__hinweis{margin:0;color:var(--color-muted,#3b3f46);font-size:.85em}
.insel-reiter__felder{display:grid;gap:10px}
.insel-reiter__felder>details>summary{display:none}
.insel-reiter__felder>details{margin:0;padding:0;border:0}
.insel-reiter__vorschau{min-height:3rem;line-height:1.6}
.insel-editor__ecke{position:absolute;right:10px;z-index:3;margin:0;transform:translateY(-130%);pointer-events:none}
.insel-editor__text h2,.insel-editor__text h3,.insel-editor__text h4{margin:.6em 0 .3em;line-height:1.25}
.insel-editor__text h2{font-size:1.35em}.insel-editor__text h3{font-size:1.18em}.insel-editor__text h4{font-size:1.05em}
.insel-editor__text pre{position:relative;padding-top:28px;white-space:pre}
.insel-editor__text pre::before{content:attr(data-kopf);position:absolute;top:6px;left:10px;font-family:inherit;font-size:.78em;font-weight:700;color:var(--color-muted,#3b3f46)}
.insel-editor__text pre code{background:none;padding:0}
.insel-editor__text table{width:100%;margin:.5em 0;border-collapse:collapse;table-layout:fixed}
.insel-editor__text td,.insel-editor__text th{min-width:3em;padding:6px 8px;border:1px solid var(--color-border,#80868c);vertical-align:top;overflow-wrap:anywhere}
.insel-editor__text th{background:var(--color-background,#f6f4f0);font-weight:700;text-align:left}
.insel-editor__text td>p,.insel-editor__text th>p{margin:0}
.insel-editor__text .selectedCell{background:color-mix(in srgb,var(--color-accent,#b53f1d) 12%,transparent)}
.insel-editor__text mark{padding:0 2px;border-radius:3px;color:#23262b}
.insel-markiert--gelb{background:#f7d867}.insel-markiert--gruen{background:#9fe0a8}.insel-markiert--blau{background:#9cc8f5}.insel-markiert--rot{background:#f5a3a3}.insel-markiert--violett{background:#cdb4f2}
@media (max-width:39.99rem){.insel-editor .insel-reiter__knopf{flex:1 1 auto;justify-content:center;padding:0 10px}.insel-editor .insel-reiter__knopf span{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}.insel-editor .insel-reiter__knopf--ende{margin-left:0}}
.insel-editor__name--gruppe{display:flex;align-items:center;justify-content:space-between;gap:8px}
.insel-editor__gruppe{font-size:.75em;font-weight:700;opacity:.75}
.insel-editor__text .insel-mention--gruppe{padding:0 4px;border-radius:4px;background:color-mix(in srgb,var(--color-link,#b53f1d) 12%,transparent)}
`;
