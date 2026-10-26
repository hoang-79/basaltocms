/**
 * Gestaltung der Insel (gestaltung.md): Leiste in Basalt mit Papier-Schrift (13,82:1), leiser Text in Asche (5,75:1),
 * Knopf Lava dunkel mit Weiss (5,69:1), Link und Fokus in Lava hell (6,23:1). Die Leiste lebt im Shadow DOM — das
 * Theme erreicht sie nicht, sie erreicht das Theme nicht.
 */
export const BAR_CSS = `
:host { all: initial; }
.bar {
    position: fixed; z-index: 2147483646; left: 50%; bottom: 12px; transform: translateX(-50%);
    box-sizing: border-box; max-width: calc(100vw - 24px);
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px;
    padding: 6px 6px 6px 12px; border-radius: 10px;
    background: #23262b; color: #f6f4f0; box-shadow: 0 6px 24px rgba(35, 38, 43, 0.28);
    font: 14px/1.4 "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
}
.hex { flex: none; width: 12px; height: 14px; background: #e4572e; clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%); }
.state { margin: 0; font-weight: 600; white-space: nowrap; }
.locale { text-decoration: none; font-variant-numeric: tabular-nums; }
.status { margin: 0; color: #9aa0a6; min-width: 0; }
.status[data-tone="error"] { color: #f28a69; }
.status[data-tone="saved"] { color: #f6f4f0; }
.actions { display: flex; align-items: center; gap: 4px 12px; margin-left: auto; }
button {
    font: inherit; font-weight: 600; color: #ffffff; background: #b53f1d; border: 0; border-radius: 6px;
    min-height: 32px; padding: 4px 12px; cursor: pointer;
}
button:hover { box-shadow: inset 0 0 0 2px #f28a69; }
button[disabled] { cursor: progress; }
a { color: #f28a69; text-decoration: underline; text-underline-offset: 2px; white-space: nowrap; }
a:hover { color: #f6f4f0; }
button:focus-visible, a:focus-visible { outline: 2px solid #f28a69; outline-offset: 2px; }
@media (max-width: 640px) {
    .bar { left: 0; right: 0; bottom: 0; transform: none; max-width: none; border-radius: 0; padding: 8px 12px; }
    .status { flex-basis: 100%; order: 3; }
}
@media (prefers-reduced-motion: no-preference) {
    button { transition: background-color 120ms ease; }
}
`;

/** Bearbeitbare Felder auf der Seite: gestrichelt, beim Fokus Lava dunkel mit weissem Hof, damit es auf jedem Grund sichtbar bleibt. */
export const FIELD_CSS = `
[data-basalto-field][contenteditable] { outline: 1px dashed rgba(98, 103, 110, 0.85); outline-offset: 3px; cursor: text; }
[data-basalto-field][contenteditable]:hover { outline-color: #b53f1d; }
[data-basalto-field][contenteditable]:focus { outline: 2px solid #b53f1d; outline-offset: 3px; box-shadow: 0 0 0 5px rgba(255, 255, 255, 0.85); }
[data-basalto-field][aria-invalid="true"] { outline: 2px solid #b8232f; }
`;
