import Image from '@tiptap/extension-image';

/**
 * Der Bildknoten des Fliesstexts: `src`, `alt`, `title` aus TipTap, dazu `mediaId` (Mediathek), `align`
 * (links · mitte · rechts) und `caption` — genau die Attribute, die der Server kennt. Gerendert als
 * `<figure>` mit Bild und Beschriftung; beim Einlesen reichen `img[src]` (auch Data-URLs, sie werden
 * nach dem Einfügen in die Mediathek geladen).
 */
export const RichImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            mediaId: {
                default: null,
                parseHTML: (element) => {
                    const raw = element.getAttribute('data-media-id');

                    return raw && /^\d+$/.test(raw) ? Number(raw) : null;
                },
                renderHTML: (attributes) =>
                    attributes.mediaId
                        ? { 'data-media-id': String(attributes.mediaId) }
                        : {},
            },
            align: {
                default: null,
                parseHTML: (element) => {
                    const own =
                        element.getAttribute('data-align') ??
                        element.closest('figure')?.getAttribute('data-align');

                    return own && ['left', 'center', 'right'].includes(own)
                        ? own
                        : null;
                },
                renderHTML: (attributes) =>
                    attributes.align ? { 'data-align': attributes.align } : {},
            },
            caption: {
                default: null,
                parseHTML: (element) => {
                    const own =
                        element.getAttribute('data-caption') ??
                        element
                            .closest('figure')
                            ?.querySelector('figcaption')
                            ?.textContent?.trim();

                    return own || null;
                },
                renderHTML: (attributes) =>
                    attributes.caption
                        ? { 'data-caption': attributes.caption }
                        : {},
            },
        };
    },

    renderHTML({ HTMLAttributes }) {
        const align = HTMLAttributes['data-align'] as string | undefined;
        const caption = HTMLAttributes['data-caption'] as string | undefined;
        const figure: Record<string, string> = {
            'data-image': '',
            class: `rte-figure${align ? ` rte-align-${align}` : ''}`,
        };
        if (align) {
            figure['data-align'] = align;
        }

        return caption
            ? [
                  'figure',
                  figure,
                  ['img', HTMLAttributes],
                  ['figcaption', {}, caption],
              ]
            : ['figure', figure, ['img', HTMLAttributes]];
    },
});
