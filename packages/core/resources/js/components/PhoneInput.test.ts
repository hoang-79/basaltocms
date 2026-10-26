import { afterEach, describe, expect, it } from 'vitest';
import { createApp, h, ref } from 'vue';
import type { PhoneCountry } from './phone';
import PhoneInput from './PhoneInput.vue';

const CH: PhoneCountry = {
    code: 'CH',
    name: 'Schweiz',
    dial: '41',
    min: 9,
    max: 9,
    groups: [2, 3, 2, 2],
    keep_zero: false,
};
const DE: PhoneCountry = {
    code: 'DE',
    name: 'Deutschland',
    dial: '49',
    min: 6,
    max: 13,
    groups: [3, 4, 4],
    keep_zero: false,
};
const countries = [CH, DE];

/** Wie im Profil: `v-model` und `v-model:country` an Refs der Eltern. */
function mount(phone = '', country = 'CH') {
    const model = ref(phone);
    const code = ref(country);
    const root = document.createElement('div');
    document.body.append(root);
    createApp({
        render: () =>
            h(PhoneInput, {
                id: 'telefon',
                countries,
                modelValue: model.value,
                'onUpdate:modelValue': (value: string) => (model.value = value),
                country: code.value,
                'onUpdate:country': (value: string) => (code.value = value),
            }),
    }).mount(root);
    const input = root.querySelector<HTMLInputElement>('input[type="tel"]')!;

    return { root, input, model, code };
}

async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve));
}

async function type(input: HTMLInputElement, value: string): Promise<void> {
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await settle();
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Telefonnummer mit fester Vorwahl', () => {
    it('füllt Feld und Land aus der gespeicherten Nummer', async () => {
        const { input, code, root } = mount('+41 79 123 45 67', 'DE');
        await settle();
        expect(input.value).toBe('79 123 45 67');
        expect(code.value).toBe('CH');
        expect(root.textContent).toContain('+41');
        expect(input.getAttribute('aria-label')).toBe(
            'Telefonnummer ohne Vorwahl +41',
        );
    });

    it('gruppiert beim Tippen, lässt die 0 weg und führt das Modell nach', async () => {
        const { input, model } = mount();
        await type(input, '079123456789');
        expect(input.value).toBe('79 123 45 67');
        expect(model.value).toBe('+41 79 123 45 67');
        await type(input, '');
        expect(model.value).toBe('');
    });

    it('sagt, wie viele Ziffern fehlen, und verbindet den Hinweis mit dem Feld', async () => {
        const { input, root } = mount();
        await type(input, '79 12');
        const hint = root.querySelector('#telefon-digits');
        expect(hint?.textContent?.trim()).toBe(
            'Es fehlen noch 5 Ziffern für Schweiz.',
        );
        expect(input.getAttribute('aria-describedby')).toBe('telefon-digits');
        await type(input, '79 123 45 6');
        expect(root.querySelector('#telefon-digits')?.textContent?.trim()).toBe(
            'Es fehlt noch 1 Ziffer für Schweiz.',
        );
        await type(input, '79 123 45 67');
        expect(root.querySelector('#telefon-digits')).toBeNull();
        expect(input.hasAttribute('aria-describedby')).toBe(false);
    });

    it('stellt mit «+» das Land um — eingefügt oder Zeichen für Zeichen getippt', async () => {
        const { input, model, code } = mount();
        await type(input, '+49 30 1234567');
        expect([code.value, input.value, model.value]).toEqual([
            'DE',
            '301 2345 67',
            '+49 301 2345 67',
        ]);

        const typed = mount();
        await type(typed.input, '+');
        await type(typed.input, '+4');
        expect([
            typed.input.value,
            typed.model.value,
            typed.code.value,
        ]).toEqual(['+4', '', 'CH']);
        await type(typed.input, '+49');
        expect([typed.input.value, typed.code.value]).toEqual(['', 'DE']);
    });

    it('lässt die Schreibmarke hinter derselben Ziffer, wenn Leerzeichen dazukommen', async () => {
        const { input } = mount();
        input.focus();
        // «79123» mit der Marke nach «791»: im gruppierten «79 123» steht sie nach «79 1»
        input.value = '79123';
        input.setSelectionRange(3, 3);
        input.dispatchEvent(new Event('input'));
        await settle();
        expect(input.value).toBe('79 123');
        expect(input.selectionStart).toBe(4);
    });

    it('behält beim neuen Land über die Flagge die Ziffern, gekürzt und gruppiert', async () => {
        const { input, model, code } = mount('+49 301 2345 6789', 'DE');
        await settle();
        code.value = 'CH';
        await settle();
        expect(input.value).toBe('30 123 45 67');
        expect(model.value).toBe('+41 30 123 45 67');
    });
});
