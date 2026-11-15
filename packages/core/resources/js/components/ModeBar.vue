<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { BackendProps } from '../types';
import Journey from './Journey.vue';
import MenuBar from './MenuBar.vue';

/**
 * Die Modus-Leiste unter der Kopfzeile: ein Platz, drei Inhalte (E-21, TB 23).
 * Modus «Inhalte» und «Verwaltung»: angeheftete Einträge und «Alle» mit allen Einträgen nach Gruppen (TB 120); Zahlen
 * in Bernstein heissen, dort wartet etwas (Mockup 9), ein Zähler ohne Aufgabe bleibt ruhig.
 * Modus «Einrichtung»: der Fahrplan — Stationen mit Frage, Stand und Kontextkarte, Abzweig «Code».
 */
const page = usePage<BackendProps>();
const backend = computed(() => page.props.backend);
</script>

<template>
    <nav class="border-b border-ash-light bg-white" aria-label="Modus-Leiste">
        <div class="mx-auto flex min-h-[50px] max-w-6xl items-stretch px-3.5">
            <MenuBar
                v-if="backend.mode === 'content'"
                mode="content"
                :items="backend.menus.content"
                :groups="backend.menu_groups.content"
                :pins-url="backend.pins_url"
                :version="backend.version"
            />
            <MenuBar
                v-else-if="backend.mode === 'admin'"
                mode="admin"
                :items="backend.menus.admin"
                :groups="backend.menu_groups.admin"
                :pins-url="backend.pins_url"
            />
            <Journey
                v-else-if="backend.journey"
                :journey="backend.journey"
                :theme="backend.theme ?? null"
            />
        </div>
    </nav>
</template>
