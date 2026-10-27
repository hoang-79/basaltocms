import { enhanceAutosave, enhanceDraft } from './account/speichern';

/**
 * Einstieg (CR-013): jedes Formular mit `data-autosave` speichert selbst; Textfelder mit `data-entwurf` halten einen
 * Entwurf im Browser, bis sie gesendet sind (Kundenkonto, Community, Marktplatz).
 */
document
    .querySelectorAll<HTMLFormElement>('form[data-autosave]')
    .forEach((form) => enhanceAutosave(form));
document
    .querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('[data-entwurf]')
    .forEach((feld) => enhanceDraft(feld));
