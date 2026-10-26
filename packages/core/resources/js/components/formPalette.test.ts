import { describe, expect, it } from 'vitest';
import { fieldSketch, paletteItems, presetField, PRESETS } from './formPalette';

const types = [
    { value: 'text', label: 'Text' },
    { value: 'email', label: 'E-Mail' },
    { value: 'textarea', label: 'Mehrzeilig' },
    { value: 'group', label: 'Gruppe' },
];

describe('paletteItems', () => {
    it('häufige Felder zuerst, nur mit erlaubten Typen, beschriftet in der Sprache der Site', () => {
        const items = paletteItems(types, 'en');
        expect(items[0]?.group).toBe('common');
        expect(items.find((i) => i.preset?.key === 'email')?.label).toBe(
            'Email',
        );
        expect(items.some((i) => i.preset?.type === 'tel')).toBe(false);
        expect(
            items.filter((i) => i.preset === null).map((i) => i.group),
        ).toEqual(['input', 'input', 'input', 'layout']);
        expect(
            paletteItems(types, 'de', false).every((i) => i.preset === null),
        ).toBe(true);
    });
});

describe('presetField', () => {
    it('bringt Beschriftung je Sprache, Pflicht und einen freien Namen', () => {
        const email = PRESETS.find((p) => p.key === 'email');
        if (!email) {
            throw new Error('Vorlage fehlt');
        }
        const field = presetField(
            email,
            'E-Mail',
            [
                { code: 'de', default: true },
                { code: 'fr', default: false },
            ],
            ['email'],
        );
        expect(field.name).toBe('email_2');
        expect(field.required).toBe(true);
        expect(field.labels).toEqual({ '*': 'E-Mail', fr: 'E-mail' });
    });
});

describe('fieldSketch', () => {
    it('zeichnet jeden Typ, Unbekanntes wie Text', () => {
        for (const type of [
            'text',
            'email',
            'radio',
            'checkbox',
            'file',
            'group',
            'modul_typ',
        ]) {
            expect(fieldSketch(type).length).toBeGreaterThan(0);
        }
    });
});

describe('Palette eines Inhaltstyps', () => {
    it('häufige Felder des Typs, Gruppen nach Lava-Feldtypen', () => {
        const items = paletteItems(
            [
                { value: 'text', label: 'Text' },
                { value: 'richtext', label: 'Fliesstext' },
                { value: 'image', label: 'Bild' },
                { value: 'blocks', label: 'Blöcke' },
            ],
            'de',
            true,
            'type',
        );
        expect(items.find((i) => i.preset?.key === 'intro')?.label).toBe(
            'Einleitung',
        );
        expect(
            items.find((i) => i.preset?.key === 'intro')?.preset?.translatable,
        ).toBe(true);
        expect(items.some((i) => i.preset?.type === 'select')).toBe(false);
        expect(items.find((i) => i.key === 'image')?.group).toBe('media');
        expect(items.find((i) => i.key === 'blocks')?.group).toBe('structure');
    });
});
