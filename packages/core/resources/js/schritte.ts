import { enhanceStepForm } from './account/schritte';

/** CR-016, TB 86: Bündel für Formulare in Schritten im Dialog — nur auf Seiten mit `form[data-schritte]`. */
function start(): void {
    document
        .querySelectorAll<HTMLFormElement>('form[data-schritte]')
        .forEach((form) => enhanceStepForm(form));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
