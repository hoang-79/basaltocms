import { Extension } from '@tiptap/core';
import type { Node as PmNode } from '@tiptap/pm/model';
import { Plugin, PluginKey, TextSelection } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

export type SearchHit = { from: number; to: number };

export type SearchStorage = {
    term: string;
    hits: SearchHit[];
    index: number;
};

declare module '@tiptap/core' {
    interface Storage {
        searchReplace: SearchStorage;
    }

    interface Commands<ReturnType> {
        searchReplace: {
            setSearchTerm: (term: string) => ReturnType;
            nextMatch: () => ReturnType;
            replaceMatch: (replacement: string) => ReturnType;
            replaceAllMatches: (replacement: string) => ReturnType;
            clearSearch: () => ReturnType;
        };
    }
}

/** Alle Treffer des Suchbegriffs im Dokument (Gross-/Kleinschreibung egal), je Textblock mit festen Positionen. */
export function findHits(doc: PmNode, term: string): SearchHit[] {
    const hits: SearchHit[] = [];
    const needle = term.toLowerCase();
    if (needle === '') {
        return hits;
    }
    doc.descendants((node, pos) => {
        if (!node.isTextblock) {
            return true;
        }
        const text = node
            .textBetween(0, node.content.size, undefined, '\u0000')
            .toLowerCase();
        let index = text.indexOf(needle);
        while (index !== -1) {
            hits.push({
                from: pos + 1 + index,
                to: pos + 1 + index + needle.length,
            });
            index = text.indexOf(needle, index + needle.length);
        }

        return false;
    });

    return hits;
}

const key = new PluginKey('searchReplace');

/**
 * Suchen und Ersetzen (M-04): Treffer werden markiert, der aktuelle hervorgehoben; «Weiter» springt,
 * «Ersetzen» tauscht den aktuellen, «Alle» jeden Treffer.
 */
export const SearchReplace = Extension.create<object, SearchStorage>({
    name: 'searchReplace',

    addStorage() {
        return { term: '', hits: [], index: 0 };
    },

    addCommands() {
        return {
            setSearchTerm:
                (term) =>
                ({ tr, dispatch }) => {
                    this.storage.term = term;
                    this.storage.hits = findHits(tr.doc, term);
                    this.storage.index = 0;
                    if (dispatch) {
                        dispatch(tr.setMeta(key, true));
                    }

                    return true;
                },
            nextMatch:
                () =>
                ({ tr, dispatch }) => {
                    const hits = this.storage.hits;
                    if (hits.length === 0) {
                        return false;
                    }
                    this.storage.index = (this.storage.index + 1) % hits.length;
                    const hit = hits[this.storage.index];
                    if (dispatch && hit) {
                        dispatch(
                            tr
                                .setSelection(
                                    TextSelection.create(
                                        tr.doc,
                                        hit.from,
                                        hit.to,
                                    ),
                                )
                                .setMeta(key, true)
                                .scrollIntoView(),
                        );
                    }

                    return true;
                },
            replaceMatch:
                (replacement) =>
                ({ tr, dispatch }) => {
                    const hit = this.storage.hits[this.storage.index];
                    if (!hit) {
                        return false;
                    }
                    tr.insertText(replacement, hit.from, hit.to);
                    this.storage.hits = findHits(tr.doc, this.storage.term);
                    if (this.storage.index >= this.storage.hits.length) {
                        this.storage.index = 0;
                    }
                    if (dispatch) {
                        dispatch(tr.setMeta(key, true));
                    }

                    return true;
                },
            replaceAllMatches:
                (replacement) =>
                ({ tr, dispatch }) => {
                    const hits = [...this.storage.hits].reverse();
                    if (hits.length === 0) {
                        return false;
                    }
                    hits.forEach((hit) =>
                        tr.insertText(replacement, hit.from, hit.to),
                    );
                    this.storage.hits = findHits(tr.doc, this.storage.term);
                    this.storage.index = 0;
                    if (dispatch) {
                        dispatch(tr.setMeta(key, true));
                    }

                    return true;
                },
            clearSearch:
                () =>
                ({ tr, dispatch }) => {
                    this.storage.term = '';
                    this.storage.hits = [];
                    this.storage.index = 0;
                    if (dispatch) {
                        dispatch(tr.setMeta(key, true));
                    }

                    return true;
                },
        };
    },

    addProseMirrorPlugins() {
        const storage = this.storage;

        return [
            new Plugin({
                key,
                state: {
                    init: () => DecorationSet.empty,
                    apply: (tr, old) => {
                        if (!tr.docChanged && !tr.getMeta(key)) {
                            return old;
                        }
                        if (tr.docChanged && !tr.getMeta(key)) {
                            storage.hits = findHits(tr.doc, storage.term);
                            if (storage.index >= storage.hits.length) {
                                storage.index = 0;
                            }
                        }
                        if (storage.hits.length === 0) {
                            return DecorationSet.empty;
                        }

                        return DecorationSet.create(
                            tr.doc,
                            storage.hits.map((hit, i) =>
                                Decoration.inline(hit.from, hit.to, {
                                    class:
                                        i === storage.index
                                            ? 'rte-hit rte-hit-current'
                                            : 'rte-hit',
                                }),
                            ),
                        );
                    },
                },
                props: {
                    decorations(state) {
                        return this.getState(state);
                    },
                },
            }),
        ];
    },
});
