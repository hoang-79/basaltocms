import { enhanceCode } from './community/code';
import { enhanceReactions } from './community/reactions';
import { enhanceSelection } from './community/selection';
import { enhanceSimilar } from './community/similar';
import {
    EDITOR_CSS,
    EDITOR_EXTRA_CSS,
    EDITOR_TOOLS_CSS,
} from './community/styles';

/**
 * CR-006 (FO-K4, TB 36): Inseln der Community im Frontend. Die Seiten sind serverseitig gerendert und gehen ohne Skript;
 * dieses kleine Bündel hängt sich an Markup mit `data-insel` und macht es bequemer: Editor, Reaktionen und die Leiste
 * «n ausgewählt» der Moderation (TB 39) und «Ähnliche Themen» beim Eröffnen. TB 49: Codeblöcke mit Hervorhebung und
 * «Kopieren»; Editor und Grammatiken kommen als eigene Teile nur dorthin, wo sie gebraucht werden.
 */
function enhanceForms(root: ParentNode): void {
    root.querySelectorAll<HTMLFormElement>(
        'form[data-insel="reaktion"]',
    ).forEach((form) => enhanceReactions(form));
    root.querySelectorAll<HTMLFormElement>(
        'form[data-insel="auswahl"]',
    ).forEach((form) => enhanceSelection(form));
    void enhanceCode(root);
}

function start(): void {
    enhanceForms(document);
    // CR-008: Beiträge, die die Live-Aktualisierung nachlädt, bekommen dieselben Inseln
    document.addEventListener('basalto:inhalt', (event) => {
        const root = (event as CustomEvent<{ root?: ParentNode }>).detail?.root;
        if (root) {
            enhanceForms(root);
        }
    });
    document
        .querySelectorAll<HTMLInputElement>('input[data-insel="aehnlich"]')
        .forEach((input) => enhanceSimilar(input));
    const areas = document.querySelectorAll<HTMLTextAreaElement>(
        'textarea[data-insel="editor"]',
    );
    if (areas.length === 0) {
        return;
    }
    if (!document.getElementById('insel-editor-stil')) {
        const style = document.createElement('style');
        style.id = 'insel-editor-stil';
        style.textContent = EDITOR_CSS + EDITOR_EXTRA_CSS + EDITOR_TOOLS_CSS;
        document.head.append(style);
    }
    void import('./community/editor').then(({ enhanceEditor }) =>
        areas.forEach((area) => enhanceEditor(area)),
    );
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
