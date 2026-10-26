import { enhanceSearchPage, enhanceSuggest } from './community/search';

/** TB 71: Bündel der Live-Suche im Forum — klein und nur dort, wo ein Suchfeld `data-suche` trägt. */
function start(): void {
    document
        .querySelectorAll<HTMLInputElement>('input[data-suche="vorschlag"]')
        .forEach((input) => enhanceSuggest(input));
    document
        .querySelectorAll<HTMLFormElement>('form[data-suche="seite"]')
        .forEach((form) => enhanceSearchPage(form));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
