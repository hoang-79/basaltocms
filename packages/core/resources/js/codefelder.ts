import { enhanceCodeForm, startResendCountdown } from './account/codefelder';

/** TB 72: Bündel der Code-Eingabe auf der Website — nur auf Seiten mit `data-basalto-code`. */
function start(): void {
    document
        .querySelectorAll<HTMLFormElement>('form[data-basalto-code]')
        .forEach((form) => enhanceCodeForm(form));
    document
        .querySelectorAll<HTMLFormElement>('form[data-basalto-erneut]')
        .forEach((form) => startResendCountdown(form));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
