<script setup lang="ts">
import { defineAsyncComponent, type Component } from 'vue';

/**
 * Ein Erweiterungspunkt in einer Seite des Kerns (TB 13 Scheibe 3): zeigt die Vue-Teile, die Module unter
 * `modules/<modul>/resources/js/slots/<name>.vue` mitbringen — mit dem Kontext der Stelle (Feld, Wert, Sprache …).
 * Ein Teil meldet einen übernommenen Wert mit `apply`; was damit geschieht, entscheidet die Seite (meist: ins Feld,
 * das Autosave speichert). Ob ein Teil sich zeigt, liest er aus `backend.extensions.<modul>` (Backend::share()).
 */
const props = defineProps<{
    name: string;
    context: Record<string, unknown>;
}>();
const emit = defineEmits<{ (e: 'apply', value: unknown): void }>();

const files = import.meta.glob<{ default: Component }>(
    '../../../../../modules/*/resources/js/slots/*.vue',
);

const parts: Component[] = Object.entries(files)
    .filter(([path]) => path.endsWith(`/slots/${props.name}.vue`))
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([, loader]) => defineAsyncComponent(loader));
</script>

<template>
    <div v-if="parts.length > 0" class="contents">
        <component
            :is="part"
            v-for="(part, i) in parts"
            :key="i"
            :context="context"
            @apply="emit('apply', $event)"
        />
    </div>
</template>
