<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { t } from '../../i18n';
import { controlClasses } from '../control';
import EmptyState from '../EmptyState.vue';
import StateTag from '../StateTag.vue';
import {
    allows,
    dropIndex,
    locate,
    moveCard,
    neighbour,
    overLimit,
} from './board';
import { tagTone } from './types';
import type { AdminBoardColumn, AdminBoardSection } from './types';

/**
 * Ein Board aus Beschreibung (CR-020) nach der Kanban-Methode: Spalten mit Karten und WIP-Limit. Karten lassen sich
 * ziehen und ablegen oder mit den Pfeilknöpfen der Karte verschieben (Tastatur); das Board zeigt den neuen Stand
 * sofort und schickt `card`, `column` und `position` an das Plugin, das prüft und speichert. Lehnt der Server ab, kommt
 * der Stand vom Server zurück, und die Meldung steht oben auf der Seite. Abgelegt wird überall in der Spalte, auch auf
 * ihrem Kopf. Die Spalten brechen um, statt waagrecht zu rollen.
 */
const props = defineProps<{ board: AdminBoardSection }>();

const columns = ref<AdminBoardColumn[]>(clone(props.board.columns));
watch(
    () => props.board.columns,
    (value) => {
        columns.value = clone(value);
    },
);

const dragging = ref<string | null>(null);
const over = ref<{ column: string; index: number } | null>(null);
const message = ref<string | null>(null);
const drafts = ref<Record<string, string>>({});
const busy = ref(false);

function clone(value: AdminBoardColumn[]): AdminBoardColumn[] {
    return value.map((c) => ({ ...c, cards: [...c.cards] }));
}

function full(column: AdminBoardColumn): string {
    return t('Die Spalte «:column» hat ihr WIP-Limit (:limit) erreicht.', {
        column: column.title,
        limit: column.limit ?? 0,
    });
}

function move(card: string, to: string, position: number): void {
    const from = locate(columns.value, card);
    const target = columns.value.find((c) => c.key === to);
    if (from === null || target === undefined) {
        return;
    }
    if (!allows(columns.value, from.column, to)) {
        message.value = full(target);

        return;
    }
    // Innerhalb derselben Spalte zählt die Stelle ohne die Karte selbst
    const index =
        from.column === to && position > from.index ? position - 1 : position;
    if (from.column === to && index === from.index) {
        return;
    }
    message.value = null;
    const before = columns.value;
    columns.value = moveCard(columns.value, card, to, index);
    busy.value = true;
    router.post(
        props.board.move_url,
        { card, column: to, position: index },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                columns.value = before;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function onDragStart(event: DragEvent, card: string): void {
    dragging.value = card;
    event.dataTransfer?.setData('text/plain', card);
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onDragOver(event: DragEvent, column: string): void {
    if (dragging.value === null) {
        return;
    }
    event.preventDefault();
    const list = event.currentTarget as HTMLElement;
    const middles = Array.from(
        list.querySelectorAll<HTMLElement>('[data-card]'),
    )
        .filter((el) => el.dataset.card !== dragging.value)
        .map((el) => {
            const rect = el.getBoundingClientRect();

            return rect.top + rect.height / 2;
        });
    let index = dropIndex(middles, event.clientY);
    // Die gezogene Karte zählt beim Ablegen in ihrer eigenen Spalte mit
    const from = locate(columns.value, dragging.value);
    if (from !== null && from.column === column && index > from.index) {
        index += 1;
    }
    over.value = { column, index };
}

function onDrop(event: DragEvent, column: string): void {
    event.preventDefault();
    const card = dragging.value ?? event.dataTransfer?.getData('text/plain');
    const index = over.value?.column === column ? over.value.index : 0;
    dragging.value = null;
    over.value = null;
    if (card) {
        move(card, column, index);
    }
}

/** Nur wirklich hinaus — beim Wechsel auf eine Karte in der Spalte feuert `dragleave` auch. */
function onDragLeave(event: DragEvent, column: string): void {
    const section = event.currentTarget as HTMLElement;
    if (
        over.value?.column === column &&
        !section.contains(event.relatedTarget as Node | null)
    ) {
        over.value = null;
    }
}

function onDragEnd(): void {
    dragging.value = null;
    over.value = null;
}

function step(card: string, column: string, direction: -1 | 1): void {
    const to = neighbour(columns.value, column, direction);
    if (to !== null) {
        const target = columns.value.find((c) => c.key === to)!;
        move(card, to, target.cards.length);
    }
}

function shift(card: string, column: string, by: -1 | 1): void {
    const from = locate(columns.value, card);
    if (from !== null) {
        // Nach unten: hinter die nächste Karte (die Stelle zählt mit der Karte selbst)
        move(card, column, by === 1 ? from.index + 2 : from.index - 1);
    }
}

function add(column: string): void {
    const title = (drafts.value[column] ?? '').trim();
    if (props.board.add === null || title === '') {
        return;
    }
    busy.value = true;
    router.post(
        props.board.add.url,
        { column, title },
        {
            preserveScroll: true,
            onSuccess: () => {
                drafts.value[column] = '';
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

const input = controlClasses({ small: true });
const arrow =
    'inline-flex h-6 w-6 items-center justify-center rounded border border-ash-light bg-white text-[12px] text-basalt-light hover:border-ash-dark hover:text-basalt focus:outline-none focus-visible:ring-2 focus-visible:ring-lava-dark disabled:opacity-40';
</script>

<template>
    <section :aria-label="board.title ?? $t('Board')">
        <h2 v-if="board.title" class="mb-2 text-[14px] font-semibold">
            {{ board.title }}
        </h2>
        <p
            v-if="message"
            role="alert"
            class="border-warning bg-warning/10 text-warning mb-2 rounded-md border px-3 py-2 text-[12.5px]"
        >
            {{ message }}
        </p>
        <div
            v-if="columns.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="board.empty.title || $t('Noch nichts da')"
                :text="board.empty.text ?? ''"
                icon="module"
                tone="ash"
            />
        </div>
        <div
            v-else
            class="grid gap-3"
            style="grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr))"
        >
            <section
                v-for="column in columns"
                :key="column.key"
                class="flex min-w-0 flex-col rounded-lg border bg-paper"
                :class="
                    overLimit(column) ? 'border-danger' : 'border-ash-light'
                "
                :aria-labelledby="`spalte-${board.key}-${column.key}`"
                @dragover="onDragOver($event, column.key)"
                @dragleave="onDragLeave($event, column.key)"
                @drop="onDrop($event, column.key)"
            >
                <header
                    class="flex items-baseline justify-between gap-2 border-b border-ash-light px-3 py-2"
                >
                    <h3
                        :id="`spalte-${board.key}-${column.key}`"
                        class="text-[13px] font-semibold break-words"
                    >
                        {{ column.title }}
                    </h3>
                    <span
                        class="font-mono text-[11px] whitespace-nowrap tabular-nums"
                        :class="
                            overLimit(column)
                                ? 'text-danger'
                                : column.limit !== null &&
                                    column.cards.length >= column.limit
                                  ? 'text-warning'
                                  : 'text-basalt-light'
                        "
                        :title="
                            column.limit !== null
                                ? $t('WIP-Limit :limit', {
                                      limit: column.limit,
                                  })
                                : undefined
                        "
                        >{{ column.cards.length
                        }}<template v-if="column.limit !== null"
                            >/{{ column.limit }}</template
                        ></span
                    >
                </header>
                <ol
                    class="flex min-h-16 flex-1 flex-col gap-2 p-2"
                    :class="
                        over?.column === column.key
                            ? 'bg-lava/5 outline-2 -outline-offset-2 outline-lava/40 outline-dashed'
                            : ''
                    "
                >
                    <li
                        v-for="(card, index) in column.cards"
                        :key="card.id"
                        :data-card="String(card.id)"
                        draggable="true"
                        class="group rounded-md border border-ash-light bg-white px-2.5 py-2 shadow-xs"
                        :class="[
                            dragging === String(card.id) ? 'opacity-40' : '',
                            over?.column === column.key && over.index === index
                                ? 'border-t-2 border-t-lava'
                                : '',
                        ]"
                        @dragstart="onDragStart($event, String(card.id))"
                        @dragend="onDragEnd"
                    >
                        <Link
                            v-if="card.url"
                            :href="card.url"
                            class="text-[13px] font-semibold break-words hover:underline"
                            >{{ card.title }}</Link
                        >
                        <b
                            v-else
                            class="text-[13px] font-semibold break-words"
                            >{{ card.title }}</b
                        >
                        <p
                            v-if="card.meta.length > 0"
                            class="text-[11.5px] break-words text-basalt-light"
                        >
                            {{ card.meta.join(' · ') }}
                        </p>
                        <div
                            v-if="
                                card.labels.length > 0 ||
                                card.due ||
                                card.assignee
                            "
                            class="mt-1 flex flex-wrap items-center gap-1"
                        >
                            <StateTag
                                v-for="label in card.labels"
                                :key="label.text"
                                :tone="tagTone(label.tone)"
                                >{{ label.text }}</StateTag
                            >
                            <StateTag
                                v-if="card.due"
                                :tone="
                                    card.overdue && !column.done
                                        ? 'danger'
                                        : 'grey'
                                "
                                >{{
                                    $t('fällig :date', { date: card.due })
                                }}</StateTag
                            >
                            <span
                                v-if="card.assignee"
                                class="ml-auto text-[11px] text-basalt-light"
                                >{{ card.assignee }}</span
                            >
                        </div>
                        <div
                            class="mt-1.5 flex gap-1 opacity-60 group-focus-within:opacity-100 group-hover:opacity-100"
                        >
                            <button
                                type="button"
                                :class="arrow"
                                :disabled="
                                    busy || column.key === columns[0]?.key
                                "
                                :aria-label="
                                    $t(':card nach links verschieben', {
                                        card: card.title,
                                    })
                                "
                                @click="step(String(card.id), column.key, -1)"
                            >
                                ←
                            </button>
                            <button
                                type="button"
                                :class="arrow"
                                :disabled="busy || index === 0"
                                :aria-label="
                                    $t(':card nach oben verschieben', {
                                        card: card.title,
                                    })
                                "
                                @click="shift(String(card.id), column.key, -1)"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                :class="arrow"
                                :disabled="
                                    busy || index === column.cards.length - 1
                                "
                                :aria-label="
                                    $t(':card nach unten verschieben', {
                                        card: card.title,
                                    })
                                "
                                @click="shift(String(card.id), column.key, 1)"
                            >
                                ↓
                            </button>
                            <button
                                type="button"
                                :class="arrow"
                                :disabled="
                                    busy ||
                                    column.key ===
                                        columns[columns.length - 1]?.key
                                "
                                :aria-label="
                                    $t(':card nach rechts verschieben', {
                                        card: card.title,
                                    })
                                "
                                @click="step(String(card.id), column.key, 1)"
                            >
                                →
                            </button>
                        </div>
                    </li>
                </ol>
                <form
                    v-if="board.add"
                    class="flex gap-1.5 border-t border-ash-light p-2"
                    @submit.prevent="add(column.key)"
                >
                    <label
                        class="sr-only"
                        :for="`neu-${board.key}-${column.key}`"
                        >{{ board.add.label }} · {{ column.title }}</label
                    >
                    <input
                        :id="`neu-${board.key}-${column.key}`"
                        v-model="drafts[column.key]"
                        type="text"
                        maxlength="200"
                        :placeholder="`+ ${board.add.label}`"
                        :class="input"
                    />
                </form>
            </section>
        </div>
    </section>
</template>
