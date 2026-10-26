<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/Button.vue';
import EmptyState from '../../components/EmptyState.vue';
import Hex from '../../components/Hex.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import Table from '../../components/Table.vue';
import type { BackendProps, ContentTypeRow, ThemeFinding } from '../../types';

/**
 * Station E «type» im Modus Einrichtung (Bereich 17/1): Typen aus dem Theme (sichtbar, gesperrt) und Typen dieser
 * Site (Formular · Code) in zwei Gruppen; ein Typ ohne Definition trägt das Bernstein-Sechseck, seine Inhalte bleiben.
 * Ein Theme-Fehler ersetzt die Liste durch die Befunde des Compilers (Datei:Zeile:Spalte, Schritt, Vorschlag).
 */
const props = defineProps<{
    types: ContentTypeRow[];
    theme_error: ThemeFinding[] | null;
    can_edit: boolean;
    urls: { create: string };
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

/** Theme-Typen und solche ohne Definition — der Hinweis spricht vom Theme, dort fehlt die Definition. */
const themeTypes = computed(() =>
    props.types.filter((t) => t.source !== 'site'),
);
const siteTypes = computed(() =>
    props.types.filter((t) => t.source === 'site'),
);

function routeList(row: ContentTypeRow): string[] {
    return Object.entries(row.routes).map(([locale, route]) =>
        locale === '*' ? route : `${route} (${locale.toUpperCase()})`,
    );
}

const mark =
    'rounded border border-ash-light bg-paper px-[5px] py-px font-mono text-[10px] text-basalt-light';
const opClass = 'text-[11px] text-lava-dark underline hover:text-basalt';
</script>

<template>
    <Head :title="$t('Typen')" />
    <PageHeader
        :title="$t('Typen')"
        :crumb="$t(':site › Einrichtung › E type', { site: siteName })"
        :lead="
            $t(
                'Welche Inhalte gibt es? Typen aus dem Theme (Station E in theme.lava) und Typen dieser Site — beide landen im selben Editor.',
            )
        "
    >
        <template v-if="can_edit && !theme_error" #actions>
            <Button :href="urls.create">{{ $t('Neuer Typ') }}</Button>
        </template>
    </PageHeader>

    <Notice
        v-if="theme_error"
        tone="danger"
        :title="$t('Das Theme kompiliert nicht')"
    >
        <p class="mb-1.5">
            {{
                $t(
                    'Bis der Fehler behoben ist, gibt es keine Typen — die Befunde des Compilers:',
                )
            }}
        </p>
        <ul class="space-y-1.5">
            <li v-for="(f, i) in theme_error" :key="i">
                <span class="font-mono text-[11.5px]"
                    >[{{ f.severity.charAt(0).toUpperCase() }}] {{ f.file }}:{{
                        f.line
                    }}:{{ f.column }}</span
                >
                <span v-if="f.step">
                    · {{ $t('Schritt :step', { step: f.step }) }}</span
                >
                · {{ f.message }}
                <b v-if="f.suggestion">
                    {{
                        $t('— meinten Sie «:suggestion»?', {
                            suggestion: f.suggestion,
                        })
                    }}</b
                >
            </li>
        </ul>
    </Notice>

    <template v-else>
        <template
            v-for="group in [
                {
                    key: 'theme',
                    title: $t('Aus dem Theme'),
                    sub: $t('theme.lava, Station E — hier nur ansehen'),
                    rows: themeTypes,
                },
                {
                    key: 'site',
                    title: $t('Dieser Site'),
                    sub: $t(
                        'site/types.lava — Formular und Code, Basalto schreibt den Code aus dem Formular',
                    ),
                    rows: siteTypes,
                },
            ]"
            :key="group.key"
        >
            <h2
                class="mt-4 mb-1.5 flex flex-wrap items-baseline gap-x-2 text-sm font-semibold first:mt-0"
            >
                {{ group.title }}
                <span class="text-[11.5px] font-normal text-basalt-light">{{
                    group.sub
                }}</span>
            </h2>
            <div
                v-if="group.rows.length === 0"
                class="rounded-lg border border-ash-light bg-white"
            >
                <EmptyState
                    v-if="group.key === 'site'"
                    :title="$t('Noch kein Typ dieser Site')"
                    :text="
                        $t(
                            'Ein Typ aus dem Backend wird als Lava in site/types.lava geschrieben und steht neben den Theme-Typen.',
                        )
                    "
                    icon="type"
                    tone="ash"
                >
                    <Button v-if="can_edit" :href="urls.create">{{
                        $t('Neuer Typ')
                    }}</Button>
                </EmptyState>
                <EmptyState
                    v-else
                    :title="$t('Das Theme definiert keine Typen')"
                    :text="
                        $t(
                            'Station E in theme.lava ist leer — die Site kann eigene Typen anlegen.',
                        )
                    "
                    icon="type"
                    tone="ash"
                />
            </div>
            <Table v-else>
                <template #head>
                    <tr>
                        <th class="px-3 py-2">{{ $t('Typ') }}</th>
                        <th class="px-3 py-2 text-right">{{ $t('Felder') }}</th>
                        <th class="px-3 py-2 text-right">
                            {{ $t('Inhalte') }}
                        </th>
                        <th class="px-3 py-2">{{ $t('Route · Template') }}</th>
                        <th class="px-3 py-2">{{ $t('Operationen') }}</th>
                    </tr>
                </template>
                <tr
                    v-for="t in group.rows"
                    :key="t.name"
                    class="align-top"
                    :class="t.source === 'missing' ? 'bg-warning/5' : ''"
                >
                    <td class="px-3 py-2.5">
                        <div class="flex items-start gap-2">
                            <Hex
                                icon="type"
                                :tone="
                                    t.source === 'missing'
                                        ? 'warning'
                                        : t.is_page_type
                                          ? 'basalt'
                                          : 'ash'
                                "
                                :title="
                                    t.source === 'missing'
                                        ? $t('nicht mehr definiert')
                                        : t.kind === 'global'
                                          ? $t('Einzelinhalt')
                                          : $t('Inhaltstyp')
                                "
                            />
                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5"
                                >
                                    <Link
                                        :href="t.edit_url"
                                        class="text-[13.5px] font-semibold break-words hover:underline"
                                        >{{ t.label }}</Link
                                    >
                                    <span
                                        class="font-mono text-[11.5px] text-basalt-light"
                                        >{{ t.name }}</span
                                    >
                                    <span
                                        v-if="t.source === 'theme'"
                                        :class="mark"
                                        >{{ $t('Theme · gesperrt') }}</span
                                    >
                                    <span
                                        v-else-if="t.source === 'site'"
                                        :class="mark"
                                        >Site</span
                                    >
                                    <span v-if="t.is_page_type" :class="mark">{{
                                        $t('Seitentyp')
                                    }}</span>
                                    <span
                                        v-if="t.kind === 'global'"
                                        :class="mark"
                                        >global</span
                                    >
                                </div>
                                <p
                                    v-if="t.source === 'missing'"
                                    class="text-warning text-[11.5px]"
                                >
                                    {{
                                        $tc(
                                            'im Theme nicht mehr definiert — :count Inhalt bleibt erhalten|im Theme nicht mehr definiert — :count Inhalte bleiben erhalten',
                                            t.entries,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td
                        :data-label="$t('Felder')"
                        class="px-3 py-2.5 text-right font-mono text-xs"
                    >
                        {{ t.source === 'missing' ? '—' : t.fields }}
                    </td>
                    <td
                        :data-label="$t('Inhalte')"
                        class="px-3 py-2.5 text-right font-mono text-xs"
                    >
                        {{ t.entries }}
                    </td>
                    <td
                        :data-label="$t('Route · Template')"
                        class="px-3 py-2.5 text-[12px] text-basalt-light"
                    >
                        <span
                            v-if="t.kind === 'global'"
                            class="text-ash-dark text-xs"
                            >{{ $t('Einzelinhalt — ohne Adresse') }}</span
                        >
                        <template v-else>
                            <span
                                v-for="r in routeList(t)"
                                :key="r"
                                class="block font-mono break-all"
                                >{{ r }}</span
                            >
                            <span
                                v-if="routeList(t).length === 0"
                                class="font-mono"
                                >{{
                                    t.source === 'missing'
                                        ? '—'
                                        : `/${t.name}/{slug}`
                                }}</span
                            >
                            <span v-if="t.template" class="block"
                                >{{ $t('Template') }}
                                <span class="font-mono">{{
                                    t.template
                                }}</span></span
                            >
                        </template>
                    </td>
                    <td
                        :data-label="$t('Operationen')"
                        class="px-3 py-2.5 whitespace-normal"
                    >
                        <span
                            class="inline-flex flex-wrap items-center gap-x-2.5 gap-y-1"
                        >
                            <Link
                                v-if="t.source !== 'missing'"
                                :href="t.edit_url"
                                :class="opClass"
                                >{{
                                    t.source === 'theme'
                                        ? $t('Ansehen')
                                        : $t('Bearbeiten')
                                }}</Link
                            >
                            <Link
                                v-if="t.list_url"
                                :href="t.list_url"
                                :class="opClass"
                                >{{ $t('Inhalte') }}</Link
                            >
                        </span>
                    </td>
                </tr>
                <template #foot>
                    {{ $tc(':count Typ|:count Typen', group.rows.length) }} ·
                    {{
                        group.key === 'theme'
                            ? $t(
                                  'Ändern im Theme-Editor (theme.lava, Station E); Seitentypen leben im Seitenbaum.',
                              )
                            : $t(
                                  'Formular und Code sind zwei Ansichten derselben Quelle.',
                              )
                    }}
                </template>
            </Table>
        </template>
    </template>
</template>
