import { afterEach, describe, expect, it } from 'vitest';
import { createApp, h, ref } from 'vue';
import CodeInput from './CodeInput.vue';

function mount(initial = '') {
    const model = ref(initial);
    const completed: string[] = [];
    const root = document.createElement('div');
    document.body.append(root);
    createApp({
        render: () =>
            h(CodeInput, {
                id: 'code',
                modelValue: model.value,
                'onUpdate:modelValue': (value: string) => (model.value = value),
                onComplete: (value: string) => completed.push(value),
            }),
    }).mount(root);
    const boxes = () =>
        Array.from(root.querySelectorAll<HTMLInputElement>('input'));

    return { root, model, completed, boxes };
}

async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve));
}

async function type(box: HTMLInputElement, value: string): Promise<void> {
    box.value = value;
    box.dispatchEvent(new Event('input'));
    await settle();
}

async function paste(box: HTMLInputElement, text: string): Promise<void> {
    const event = new Event('paste', { cancelable: true }) as ClipboardEvent;
    Object.defineProperty(event, 'clipboardData', {
        value: { getData: () => text },
    });
    box.dispatchEvent(event);
    await settle();
}

async function key(box: HTMLInputElement, name: string): Promise<void> {
    box.dispatchEvent(
        new KeyboardEvent('keydown', { key: name, cancelable: true }),
    );
    await settle();
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Code in Ziffernfeldern', () => {
    it('zeigt sechs Felder, das erste für das Ausfüllen durch den Browser', async () => {
        const { boxes } = mount();
        await settle();
        expect(boxes()).toHaveLength(6);
        expect(boxes()[0].getAttribute('autocomplete')).toBe('one-time-code');
        expect(boxes()[1].getAttribute('autocomplete')).toBe('off');
        expect(boxes()[0].getAttribute('inputmode')).toBe('numeric');
        expect(boxes()[2].getAttribute('aria-label')).toBe('Ziffer 3 von 6');
        expect(document.activeElement).toBe(boxes()[0]);
    });

    it('springt beim Tippen weiter und meldet den vollen Code', async () => {
        const { boxes, model, completed } = mount();
        for (const [i, d] of ['4', '0', '2', '9', '1'].entries()) {
            await type(boxes()[i], d);
        }
        expect(model.value).toBe('40291');
        expect(document.activeElement).toBe(boxes()[5]);
        expect(completed).toEqual([]);
        await type(boxes()[5], '7');
        expect(completed).toEqual(['402917']);
    });

    it('verteilt einen eingefügten Code auf alle Felder, auch mit Leerzeichen, und meldet ihn', async () => {
        const { boxes, model, completed } = mount();
        await paste(boxes()[3], ' 123 456 ');
        expect(model.value).toBe('123456');
        expect(boxes().map((b) => b.value)).toEqual([
            '1',
            '2',
            '3',
            '4',
            '5',
            '6',
        ]);
        expect(completed).toEqual(['123456']);
    });

    it('nimmt den ganzen Code, den der Browser ins erste Feld schreibt', async () => {
        const { boxes, completed } = mount();
        await type(boxes()[0], '987654');
        expect(completed).toEqual(['987654']);
    });

    it('ignoriert Buchstaben, Rücktaste löscht und springt zurück', async () => {
        const { boxes, model } = mount('12');
        await settle();
        await type(boxes()[2], 'x');
        expect(model.value).toBe('12');
        await key(boxes()[2], 'Backspace');
        expect(model.value).toBe('1');
        expect(document.activeElement).toBe(boxes()[1]);
    });
});
