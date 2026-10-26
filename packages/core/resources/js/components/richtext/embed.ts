import { Node, mergeAttributes } from '@tiptap/core';
import Youtube from '@tiptap/extension-youtube';
import { t } from '../../i18n';

export type EmbedProvider = 'vimeo' | 'map';

export type EmbedTarget =
    | { kind: 'youtube'; src: string }
    | { kind: 'embed'; provider: EmbedProvider; src: string };

/** Erkennt die Adresse einer Einbettung: YouTube (auch youtu.be), Vimeo, Karte (Google Maps, OpenStreetMap). */
export function parseEmbedUrl(input: string): EmbedTarget | null {
    const url = input.trim();
    if (!/^https?:\/\//i.test(url)) {
        return null;
    }
    let host = '';
    try {
        host = new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return null;
    }
    if (
        host === 'youtube.com' ||
        host === 'youtu.be' ||
        host === 'youtube-nocookie.com' ||
        host === 'm.youtube.com'
    ) {
        return { kind: 'youtube', src: url };
    }
    if (host === 'vimeo.com' || host === 'player.vimeo.com') {
        return { kind: 'embed', provider: 'vimeo', src: url };
    }
    if (
        host === 'google.com' ||
        host === 'maps.google.com' ||
        host.endsWith('.google.com') ||
        host.endsWith('.google.ch') ||
        host === 'openstreetmap.org' ||
        host === 'umap.openstreetmap.fr'
    ) {
        return /maps|openstreetmap|umap/.test(url)
            ? { kind: 'embed', provider: 'map', src: url }
            : null;
    }

    return null;
}

export const embedLabels: Record<EmbedProvider, string> = {
    vimeo: 'Vimeo',
    get map() {
        return t('Karte');
    },
};

function consentHint(): string {
    return t('wird erst nach Zustimmung geladen');
}

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        embed: {
            setEmbed: (attrs: {
                provider: EmbedProvider;
                src: string;
            }) => ReturnType;
        };
    }
}

/**
 * Einbettung ausser YouTube (Vimeo, Karte): im Editor ein Platzhalter mit Anbieter und Adresse —
 * geladen wird im Theme erst nach Zustimmung (M-04).
 */
export const Embed = Node.create({
    name: 'embed',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return {
            provider: { default: 'vimeo' },
            src: { default: null },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'div[data-embed]',
                getAttrs: (element) => ({
                    provider: element.getAttribute('data-embed'),
                    src: element.getAttribute('data-src'),
                }),
            },
            {
                tag: 'iframe[src*="vimeo.com"]',
                getAttrs: (element) => ({
                    provider: 'vimeo',
                    src: element.getAttribute('src'),
                }),
            },
            {
                tag: 'iframe[src*="google.com/maps"]',
                getAttrs: (element) => ({
                    provider: 'map',
                    src: element.getAttribute('src'),
                }),
            },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        const provider = (HTMLAttributes.provider ?? 'vimeo') as EmbedProvider;
        const src = String(HTMLAttributes.src ?? '');

        return [
            'div',
            mergeAttributes({
                'data-embed': provider,
                'data-src': src,
                class: 'rte-embed',
            }),
            `${embedLabels[provider] ?? provider} · ${src} — ${consentHint()}`,
        ];
    },

    addCommands() {
        return {
            setEmbed:
                (attrs) =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name, attrs }),
        };
    },
});

/** YouTube ohne iframe im Editor: ein Platzhalter mit der Adresse; der Knoten bleibt `youtube`{src}. */
export const RichYoutube = Youtube.extend({
    parseHTML() {
        return [
            {
                tag: 'div[data-youtube-video]',
                getAttrs: (element) => ({
                    src: element.getAttribute('data-src'),
                }),
            },
            { tag: 'div[data-youtube-video] iframe' },
            {
                tag: 'iframe[src*="youtube.com"]',
                getAttrs: (element) => ({ src: element.getAttribute('src') }),
            },
            {
                tag: 'iframe[src*="youtu.be"]',
                getAttrs: (element) => ({ src: element.getAttribute('src') }),
            },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        const src = String(HTMLAttributes.src ?? '');

        return [
            'div',
            { 'data-youtube-video': '', 'data-src': src, class: 'rte-embed' },
            `YouTube · ${src} — ${consentHint()}`,
        ];
    },
});
