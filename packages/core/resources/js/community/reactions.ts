/**
 * CR-006 (TB 38): Reaktionen ohne Neuladen. Ohne Skript ist jede Reaktion ein Formular-Knopf (zurück zur Seite); mit
 * Insel geht die Wahl als JSON hin, und Knöpfe und Zählung zeigen gleich den neuen Stand — die eigene Reaktion
 * hervorgehoben.
 */
type Result = { mine: string | null; counts: Record<string, number> };

export function renderSummary(
    counts: Record<string, number>,
    symbols: Record<string, string>,
    labels: Record<string, string>,
    mine: string | null = null,
): HTMLElement[] {
    return Object.keys(symbols)
        .filter((type) => (counts[type] ?? 0) > 0)
        .map((type) => {
            const chip = document.createElement('span');
            chip.className =
                type === mine ? 'forum-reaktion ist-meine' : 'forum-reaktion';
            chip.title = labels[type] ?? type;
            const count = document.createElement('b');
            count.textContent = String(counts[type]);
            chip.append(`${symbols[type]} `, count);

            return chip;
        });
}

export function enhanceReactions(form: HTMLFormElement): void {
    if (form.dataset.inselAktiv === '1') {
        return;
    }
    form.dataset.inselAktiv = '1';
    const buttons = Array.from(
        form.querySelectorAll<HTMLButtonElement>('button[name="reaction"]'),
    );
    const symbols: Record<string, string> = {};
    const labels: Record<string, string> = {};
    for (const button of buttons) {
        symbols[button.value] = button.textContent ?? '';
        labels[button.value] = button.title;
    }
    const summary = form
        .closest('.forum-reaktionen')
        ?.querySelector<HTMLElement>('[data-reaktion-summe]');
    form.addEventListener('submit', async (event) => {
        const submitter = (event as SubmitEvent).submitter;
        if (!(submitter instanceof HTMLButtonElement)) {
            return;
        }
        event.preventDefault();
        const data = new FormData(form);
        data.set('reaction', submitter.value);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: data,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                form.submit();

                return;
            }
            const result = (await response.json()) as Result;
            for (const button of buttons) {
                button.setAttribute(
                    'aria-pressed',
                    button.value === result.mine ? 'true' : 'false',
                );
            }
            summary?.replaceChildren(
                ...renderSummary(result.counts, symbols, labels, result.mine),
            );
            // TB 47: die Auswahl ist eine Blase — nach der Wahl geht sie zu
            form.closest('details')?.removeAttribute('open');
        } catch {
            form.submit();
        }
    });
}
