<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { postHtml, requestError, withBase } from '../http';

/**
 * Live-Vorschau als kleines Fenster (TB 30, geführtes Blockformular): zeigt die Blöcke mit dem echten Theme, gut
 * 0,3 Sekunden nach jeder Eingabe neu. Verschieben am Kopf, Grösse an der Ecke, Breit/Schmal, einklappen; Lage und
 * Grösse merkt sich der Browser. Das Feld mit dem Fokus leuchtet in der Vorschau auf; ein Klick auf eine markierte
 * Stelle meldet ihr Feld (`pick`), damit das Formular dorthin springt. Gespeichert wird nichts.
 */
const props = defineProps<{
    /** POST-Adresse, die HTML liefert */
    url: string;
    /** was an die Adresse geht — jede Änderung zeichnet neu */
    payload: unknown;
    /** Feldmarke mit Fokus («content.0.data.title») oder der Block («content.0.») */
    focus?: string | null;
    /** beim ersten Zeichnen zum ersten Block statt an den Seitenanfang (Bausteine haben keine eigene Seite) */
    startAtBlocks?: boolean;
}>();
const emit = defineEmits<{
    (e: 'pick', path: string): void;
    (e: 'close'): void;
}>();

const STORE = 'basalto.vorschau-fenster';
const HEAD = 34;
type Box = { x: number; y: number; w: number; h: number };

function initialBox(): Box {
    const w = Math.min(480, window.innerWidth - 32);
    const h = Math.min(340, window.innerHeight - 96);
    const fallback = {
        x: window.innerWidth - w - 16,
        y: window.innerHeight - h - 16,
        w,
        h,
    };
    try {
        const saved = JSON.parse(
            window.localStorage.getItem(STORE) ?? 'null',
        ) as Box | null;

        return saved ? clamp(saved) : fallback;
    } catch {
        return fallback;
    }
}

function clamp(b: Box): Box {
    const w = Math.max(280, Math.min(b.w, window.innerWidth - 16));
    const h = Math.max(180, Math.min(b.h, window.innerHeight - 16));

    return {
        w,
        h,
        x: Math.max(8, Math.min(b.x, window.innerWidth - w - 8)),
        y: Math.max(8, Math.min(b.y, window.innerHeight - HEAD - 8)),
    };
}

const box = ref<Box>(initialBox());
const collapsed = ref(false);
const device = ref<'desktop' | 'mobile'>('desktop');
const html = ref('');
const state = ref<'loading' | 'idle' | 'error'>('loading');
const message = ref('');
const frame = ref<HTMLIFrameElement | null>(null);
let serial = 0;
let scrollY = 0;
let first = true;

const deviceWidth = computed(() => (device.value === 'desktop' ? 1280 : 390));
const scale = computed(() =>
    Math.min(1, (box.value.w - 2) / deviceWidth.value),
);

function remember(): void {
    window.localStorage.setItem(STORE, JSON.stringify(box.value));
}

async function load(): Promise<void> {
    const mine = ++serial;
    state.value = 'loading';
    try {
        const result = await postHtml(props.url, props.payload);
        if (mine === serial) {
            scrollY = frame.value?.contentWindow?.scrollY ?? scrollY;
            html.value = withBase(result);
            state.value = 'idle';
        }
    } catch (e) {
        if (mine === serial) {
            state.value = 'error';
            message.value = requestError(e);
        }
    }
}

const reload = useDebounceFn(load, 300);
watch(
    () => JSON.stringify(props.payload),
    () => void reload(),
    { immediate: true },
);

/** Nach dem Laden: Marken klickbar, Links tot (die Vorschau navigiert nicht), Fokus zeigen, Lage behalten. */
function onLoad(): void {
    const doc = frame.value?.contentDocument;
    if (!doc) {
        return;
    }
    const style = doc.createElement('style');
    style.textContent =
        '[data-basalto-field]{cursor:pointer}[data-basalto-field]:hover{outline:1px dashed #e4572e;outline-offset:3px}' +
        '[data-basalto-fokus]{outline:2px solid #e4572e!important;outline-offset:3px;border-radius:2px}';
    doc.head?.appendChild(style);
    doc.addEventListener(
        'click',
        (event) => {
            const target = event.target as Element | null;
            const mark = target?.closest('[data-basalto-field]');
            if (mark || target?.closest('a, button, form')) {
                event.preventDefault();
            }
            const path = mark?.getAttribute('data-basalto-field');
            if (path) {
                emit('pick', path);
            }
        },
        true,
    );
    const firstBlock = doc.querySelector('[data-basalto-field^="content."]');
    if (first && props.startAtBlocks && firstBlock) {
        firstBlock.scrollIntoView({ block: 'start' });
    } else {
        frame.value?.contentWindow?.scrollTo(0, scrollY);
    }
    first = false;
    showFocus(false);
}

function showFocus(scroll = true): void {
    const doc = frame.value?.contentDocument;
    if (!doc) {
        return;
    }
    doc.querySelectorAll('[data-basalto-fokus]').forEach((el) =>
        el.removeAttribute('data-basalto-fokus'),
    );
    const focus = props.focus ?? '';
    if (focus === '') {
        return;
    }
    const exact = doc.querySelector(
        `[data-basalto-field="${CSS.escape(focus)}"]`,
    );
    exact?.setAttribute('data-basalto-fokus', '');
    const block = focus.replace(/^(content\.\d+\.).*$/, '$1');
    const target =
        exact ??
        doc.querySelector(`[data-basalto-field^="${CSS.escape(block)}"]`);
    if (scroll && target) {
        target.scrollIntoView({ block: 'center' });
    }
}

watch(
    () => props.focus,
    () => showFocus(),
);

/* ── Verschieben und Grösse ändern (Zeiger festhalten) ── */
let drag: { mode: 'move' | 'size'; x: number; y: number; start: Box } | null =
    null;

function start(mode: 'move' | 'size', event: PointerEvent): void {
    if (event.button !== 0 || (event.target as Element).closest('button')) {
        return;
    }
    event.preventDefault();
    drag = {
        mode,
        x: event.clientX,
        y: event.clientY,
        start: { ...box.value },
    };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function move(event: PointerEvent): void {
    if (!drag) {
        return;
    }
    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;
    box.value = clamp(
        drag.mode === 'move'
            ? { ...drag.start, x: drag.start.x + dx, y: drag.start.y + dy }
            : { ...drag.start, w: drag.start.w + dx, h: drag.start.h + dy },
    );
}

function end(): void {
    if (drag) {
        drag = null;
        remember();
    }
}

function onResize(): void {
    box.value = clamp(box.value);
}
window.addEventListener('resize', onResize);

/* Liegt das Fenster unten, bekommt die Seite unten so viel Platz, dass sich alles (z. B. «Speichern») darüber rollen lässt */
const bodyPadding = document.body.style.paddingBottom;
watch(
    [box, collapsed],
    () => {
        const atBottom = box.value.y + box.value.h > window.innerHeight - 48;
        document.body.style.paddingBottom =
            atBottom && !collapsed.value
                ? `${window.innerHeight - box.value.y + 16}px`
                : bodyPadding;
    },
    { immediate: true, deep: true },
);
onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    document.body.style.paddingBottom = bodyPadding;
});
</script>

<template>
    <section
        class="fixed z-40 flex flex-col overflow-hidden rounded-lg border border-ash bg-white shadow-xl"
        :style="{
            left: `${box.x}px`,
            top: `${box.y}px`,
            width: `${box.w}px`,
            height: collapsed ? `${HEAD}px` : `${box.h}px`,
        }"
        :aria-label="$t('Live-Vorschau')"
    >
        <header
            class="flex shrink-0 cursor-move items-center gap-1.5 border-b border-ash-light bg-paper px-2.5 select-none"
            :style="{ height: `${HEAD}px` }"
            @pointerdown="start('move', $event)"
            @pointermove="move"
            @pointerup="end"
            @pointercancel="end"
        >
            <span
                class="size-2 rounded-full"
                :class="
                    state === 'loading'
                        ? 'animate-pulse bg-ash'
                        : state === 'error'
                          ? 'bg-danger'
                          : 'bg-success'
                "
                aria-hidden="true"
            />
            <strong class="text-[12px] font-semibold">{{
                $t('Live-Vorschau')
            }}</strong>
            <span class="sr-only" role="status">{{
                state === 'loading' ? $t('wird gezeichnet …') : ''
            }}</span>
            <span class="ml-auto flex items-center gap-0.5">
                <button
                    v-for="d in ['desktop', 'mobile'] as const"
                    :key="d"
                    type="button"
                    class="rounded px-1.5 py-0.5 text-[11px]"
                    :class="
                        device === d
                            ? 'bg-basalt text-paper'
                            : 'text-basalt-light hover:text-basalt'
                    "
                    :aria-pressed="device === d"
                    @click="device = d"
                >
                    {{ d === 'desktop' ? $t('Breit') : $t('Schmal') }}
                </button>
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 text-[13px] text-basalt-light hover:text-basalt"
                    :aria-label="
                        collapsed ? $t('Aufklappen') : $t('Einklappen')
                    "
                    :aria-expanded="!collapsed"
                    @click="collapsed = !collapsed"
                >
                    {{ collapsed ? '▴' : '▾' }}
                </button>
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 text-[15px] leading-none text-basalt-light hover:text-basalt"
                    :aria-label="$t('Vorschau schliessen')"
                    @click="emit('close')"
                >
                    ×
                </button>
            </span>
        </header>
        <div v-show="!collapsed" class="relative min-h-0 flex-1 bg-white">
            <p
                v-if="state === 'error'"
                class="text-danger p-3 text-[12px] font-semibold"
                role="alert"
            >
                {{ message }}
            </p>
            <iframe
                v-else
                ref="frame"
                :srcdoc="html"
                :title="$t('Live-Vorschau')"
                sandbox="allow-same-origin"
                class="block origin-top-left border-0 bg-white"
                :style="{
                    width: `${deviceWidth}px`,
                    height: `${(box.h - HEAD) / scale}px`,
                    transform: `scale(${scale})`,
                    marginLeft:
                        device === 'mobile' && box.w > deviceWidth
                            ? `${(box.w - deviceWidth) / 2}px`
                            : undefined,
                }"
                @load="onLoad"
            />
            <span
                class="absolute right-0 bottom-0 size-4 cursor-nwse-resize"
                :title="$t('Grösse ändern')"
                aria-hidden="true"
                @pointerdown="start('size', $event)"
                @pointermove="move"
                @pointerup="end"
                @pointercancel="end"
            >
                <svg viewBox="0 0 16 16" class="size-4 text-ash">
                    <path
                        d="M14 6 6 14M14 10l-4 4"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                    />
                </svg>
            </span>
        </div>
    </section>
</template>
