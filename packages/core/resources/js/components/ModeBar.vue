<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { isCurrent } from '../support';
import type { BackendProps } from '../types';
import type { MenuItemWithCount } from '../types-workflow';
import Hex from './Hex.vue';
import Journey from './Journey.vue';

/**
 * Die Modus-Leiste unter der Kopfzeile: ein Platz, drei Inhalte (E-21, TB 23).
 * Modus «Inhalte»: die Stationen der Redaktion mit Sechseck-Symbol und Zähler.
 * Modus «Einrichtung»: der Fahrplan — Stationen mit Frage, Stand und Kontextkarte, Abzweig «Code».
 * Modus «Verwaltung»: Sites, Benutzer, Rollen, Einstellungen und was Module dazu beitragen.
 * Die Leiste bricht um statt zu scrollen.
 */
const page = usePage<BackendProps>();
const backend = computed(() => page.props.backend);
const current = (href: string): boolean => isCurrent(page.url, href);
/**
 * Die Stationen der Redaktion; «Freigabe» und «Übersetzung» tragen eine Zahl (`count`, nur über 0), die in Bernstein
 * steht — dort wartet etwas auf Sie (Mockup 9). Ein Zähler ohne Aufgabe (`badge`) bleibt ruhig.
 */
const contentMenu = computed<MenuItemWithCount[]>(
    () => backend.value.menus.content,
);
const countOf = (item: MenuItemWithCount): number =>
    item.count ?? item.badge ?? 0;
</script>

<template>
    <nav class="border-b border-ash-light bg-white" aria-label="Modus-Leiste">
        <div
            class="mx-auto flex min-h-[50px] max-w-6xl flex-wrap items-stretch px-3.5"
        >
            <template v-if="backend.mode === 'content'">
                <Link
                    v-for="item in contentMenu"
                    :key="item.route"
                    :href="item.href"
                    class="-mb-px flex min-h-8 items-center gap-[7px] border-b-2 px-[11px] text-[12.5px] whitespace-nowrap"
                    :class="
                        current(item.href)
                            ? 'border-lava font-semibold text-basalt'
                            : 'border-transparent text-basalt-light hover:text-basalt'
                    "
                    :aria-current="current(item.href) ? 'page' : undefined"
                >
                    <Hex
                        :icon="item.icon"
                        :label="item.label"
                        :tone="current(item.href) ? 'basalt' : 'ash'"
                    />
                    {{ item.label }}
                    <span
                        v-if="countOf(item) > 0"
                        class="rounded border bg-paper px-[5px] font-mono text-[10.5px] font-normal"
                        :class="
                            (item.count ?? 0) > 0
                                ? 'border-warning text-warning'
                                : 'border-ash-light text-basalt-light'
                        "
                        :aria-label="
                            $t(':count offen', { count: countOf(item) })
                        "
                        >{{ countOf(item) }}</span
                    >
                </Link>
                <span
                    class="text-ash-dark ml-auto hidden items-center pl-3 text-[10.5px] whitespace-nowrap md:flex"
                    >Basalto {{ backend.version }}</span
                >
            </template>
            <template v-else-if="backend.mode === 'admin'">
                <Link
                    v-for="item in backend.menus.admin"
                    :key="item.route"
                    :href="item.href"
                    class="-mb-px flex min-h-8 items-center gap-[7px] border-b-2 px-[11px] text-[12.5px] whitespace-nowrap"
                    :class="
                        current(item.href)
                            ? 'border-lava font-semibold text-basalt'
                            : 'border-transparent text-basalt-light hover:text-basalt'
                    "
                    :aria-current="current(item.href) ? 'page' : undefined"
                >
                    <Hex
                        :icon="item.icon ?? 'setup'"
                        :label="item.label"
                        :tone="current(item.href) ? 'basalt' : 'ash'"
                    />
                    {{ item.label }}
                </Link>
            </template>
            <Journey
                v-else-if="backend.journey"
                :journey="backend.journey"
                :theme="backend.theme ?? null"
            />
        </div>
    </nav>
</template>
