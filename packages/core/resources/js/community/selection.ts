/**
 * CR-006 (FO-15, TB 39): die Leiste «n ausgewählt» der Moderation. Ohne Skript steht sie immer da und schickt die
 * angekreuzten Themen oder Beiträge (Felder mit `form="…"`); mit Skript erscheint sie erst, wenn etwas angekreuzt ist,
 * und zählt mit.
 */
export function selectionText(template: string, count: number): string {
    return template.replace(':count', String(count));
}

export function enhanceSelection(form: HTMLFormElement): void {
    const boxes = (): HTMLInputElement[] =>
        Array.from(
            document.querySelectorAll<HTMLInputElement>(
                `input[type="checkbox"][form="${CSS.escape(form.id)}"]`,
            ),
        );
    const label = form.querySelector<HTMLElement>('[data-auswahl-zahl]');
    const template = form.dataset.inselText ?? ':count';
    const update = (): void => {
        const count = boxes().filter((box) => box.checked).length;
        form.hidden = count === 0;
        if (label) {
            label.textContent = selectionText(template, count);
        }
    };
    boxes().forEach((box) => box.addEventListener('change', update));
    update();
}
