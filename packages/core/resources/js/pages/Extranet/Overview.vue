<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '../../components/EmptyState.vue';
import PageHeader from '../../components/PageHeader.vue';
import type { OverviewProps } from '../../types-extranet';

/**
 * Die Übersicht des Hersteller-Extranets «Heute zu tun» (CR-004 P-10): je Aufgabe eine Kachel mit grosser Zahl,
 * die auf die passend gefilterte Liste führt. Offene Aufgaben betont im Bernstein-Ton der Zähler, erledigte ruhig
 * in Grau. Ohne Bereiche (Rolle ohne Rechte im Extranet) ein Leerzustand. «Weiter, wo Sie aufgehört haben» führt
 * zur zuletzt besuchten Seite.
 */
defineProps<OverviewProps>();
</script>

<template>
    <Head :title="$t('Heute zu tun')" />
    <PageHeader
        :title="$t('Heute zu tun')"
        :crumb="$t('Extranet › Übersicht')"
        :lead="$t('Guten Tag, :name.', { name })"
    >
        <template v-if="last_url" #actions>
            <Link
                :href="last_url"
                class="text-[12.5px] font-semibold text-lava-dark underline hover:text-basalt"
                >{{ $t('Weiter, wo Sie aufgehört haben') }} →</Link
            >
        </template>
    </PageHeader>

    <div
        v-if="todos.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="$t('Hier gibt es nichts zu tun')"
            :text="
                $t(
                    'Ihre Rolle hat im Extranet noch keine Bereiche. Die Inhaber stellen das unter «Team» ein.',
                )
            "
            icon="check"
            tone="ash"
        />
    </div>

    <ul v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="todo in todos" :key="todo.key" class="min-w-0">
            <Link
                :href="todo.href"
                class="group flex h-full flex-col gap-1 rounded-lg border bg-white px-4 py-3.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lava-dark"
                :class="
                    todo.count > 0
                        ? 'border-warning/50 hover:bg-warning/5'
                        : 'border-ash-light hover:bg-paper'
                "
            >
                <span
                    class="font-mono text-[32px] leading-none font-bold"
                    :class="todo.count > 0 ? 'text-warning' : 'text-ash-dark'"
                    >{{ todo.count }}</span
                >
                <span
                    class="text-[13.5px] font-semibold break-words"
                    :class="
                        todo.count > 0 ? 'text-basalt' : 'text-basalt-light'
                    "
                    >{{ todo.label }}</span
                >
                <span
                    class="mt-auto pt-1 text-[11.5px]"
                    :class="
                        todo.count > 0
                            ? 'text-lava-dark underline group-hover:text-basalt'
                            : 'text-basalt-light'
                    "
                    >{{
                        todo.count > 0
                            ? $t('Zur Liste') + ' →'
                            : $t('nichts offen')
                    }}</span
                >
            </Link>
        </li>
    </ul>
</template>
