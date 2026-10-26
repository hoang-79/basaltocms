<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, ref, useId, watch } from 'vue';
import type { TabDef } from '../types';
import { nextTabKey } from './a11y';

/**
 * Echte Reiter, die den Inhalt wechseln (E-26) — keine Sprungmarken. Auf Mobil ein Akkordeon.
 * Ein Reiter mit Validierungsfehlern trägt ein karminrotes Sechseck mit der Zahl; ein Reiter mit `count`
 * eine kleine Marke in Mono auf Papier (wie der Zähler in der Modus-Leiste).
 * Inhalt je Reiter kommt über einen benannten Slot mit dem Schlüssel des Reiters.
 * Der Slot «tools» (z. B. eine Filterzeile) steht auf dem Desktop rechts in der Reiterzeile; auf Mobil
 * wandert er in den Abschnitt des Reiters `toolsFor` (Mockup 28/1: Filter rechts der Reiter).
 * Tastatur nach APG «Tabs»: nur der aktive Reiter liegt in der Tab-Reihenfolge, ← → Pos1 Ende wechseln (WCAG 2.1.1).
 */
const props = defineProps<{
    tabs: TabDef[];
    modelValue?: string;
    toolsFor?: string;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', key: string): void }>();

const isDesktop = useMediaQuery('(min-width: 768px)');

const internal = ref(props.modelValue ?? props.tabs[0]?.key ?? '');
const active = computed(() => props.modelValue ?? internal.value);

const open = ref<string[]>([active.value]);

watch(
    () => props.modelValue,
    (key) => {
        if (key && !open.value.includes(key)) {
            open.value = [...open.value, key];
        }
    },
);

function select(key: string): void {
    internal.value = key;
    emit('update:modelValue', key);
}

const uid = useId();
const tabId = (key: string): string => `${uid}-tab-${key}`;
const tabButtons = ref<HTMLElement[]>([]);

/** Pfeiltasten in der Reiterleiste: wählen und den Fokus mitnehmen. */
function onTabKey(event: KeyboardEvent): void {
    const keys = props.tabs.map((t) => t.key);
    const next = nextTabKey(keys, active.value, event.key);
    if (next === null) {
        return;
    }
    event.preventDefault();
    select(next);
    void nextTick(() => tabButtons.value[keys.indexOf(next)]?.focus());
}

function toggle(key: string): void {
    open.value = open.value.includes(key)
        ? open.value.filter((k) => k !== key)
        : [...open.value, key];
}

function isOpen(key: string): boolean {
    return open.value.includes(key);
}

const countClass =
    'rounded border border-ash-light bg-paper px-[5px] font-mono text-[10.5px] font-normal text-basalt-light';
const errorClass =
    'hex bg-danger inline-flex h-3 w-[13px] items-center justify-center font-mono text-[9px] font-bold text-white';
</script>

<template>
    <div>
        <template v-if="isDesktop">
            <div
                class="flex flex-wrap items-end gap-x-3 border-b border-ash-light"
            >
                <div class="flex flex-wrap" role="tablist" @keydown="onTabKey">
                    <button
                        v-for="t in tabs"
                        :id="tabId(t.key)"
                        ref="tabButtons"
                        :key="t.key"
                        type="button"
                        role="tab"
                        :aria-selected="t.key === active"
                        :aria-controls="`${uid}-panel`"
                        :tabindex="t.key === active ? 0 : -1"
                        class="-mb-px flex items-center gap-1.5 border-b-2 px-[13px] py-2 text-[12.5px] whitespace-nowrap"
                        :class="
                            t.key === active
                                ? 'border-lava font-semibold text-basalt'
                                : 'border-transparent text-basalt-light hover:text-basalt'
                        "
                        @click="select(t.key)"
                    >
                        {{ t.label }}
                        <span
                            v-if="t.count !== undefined"
                            :class="countClass"
                            >{{ t.count }}</span
                        >
                        <span
                            v-if="t.errors"
                            :class="errorClass"
                            :title="
                                $tc(':count Fehler|:count Fehler', t.errors)
                            "
                            >{{ t.errors }}</span
                        >
                    </button>
                </div>
                <div
                    v-if="$slots.tools"
                    class="ml-auto flex min-w-0 items-center py-1"
                >
                    <slot name="tools" />
                </div>
            </div>
            <div
                :id="`${uid}-panel`"
                class="pt-2"
                role="tabpanel"
                :aria-labelledby="tabId(active)"
            >
                <slot :name="active" />
            </div>
        </template>
        <div v-else class="flex flex-col gap-1.5">
            <section
                v-for="t in tabs"
                :key="t.key"
                class="overflow-hidden rounded-lg border bg-white"
                :class="isOpen(t.key) ? 'border-ash' : 'border-ash-light'"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2.5 text-[13px] font-semibold"
                    :class="
                        isOpen(t.key)
                            ? 'border-b border-ash-light shadow-[inset_3px_0_0_var(--color-lava)]'
                            : ''
                    "
                    :aria-expanded="isOpen(t.key)"
                    @click="toggle(t.key)"
                >
                    <span class="flex items-center gap-1.5">
                        {{ t.label }}
                        <span
                            v-if="t.count !== undefined"
                            :class="countClass"
                            >{{ t.count }}</span
                        >
                        <span v-if="t.errors" :class="errorClass">{{
                            t.errors
                        }}</span>
                    </span>
                    <span
                        class="text-ash-dark text-[11px]"
                        aria-hidden="true"
                        >{{ isOpen(t.key) ? '▾' : '▸' }}</span
                    >
                </button>
                <div v-if="isOpen(t.key)" class="px-3 pt-1 pb-3">
                    <div v-if="$slots.tools && t.key === toolsFor" class="mb-2">
                        <slot name="tools" />
                    </div>
                    <slot :name="t.key" />
                </div>
            </section>
        </div>
    </div>
</template>
