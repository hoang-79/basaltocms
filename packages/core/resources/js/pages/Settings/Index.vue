<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    announceError,
    announceSaved,
    announceSaving,
} from '../../components/autosave';
import { useMediaQuery } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import {
    changeText,
    parseSettingInput,
    valueText,
} from '../../components/admin';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import { controlClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Notice from '../../components/Notice.vue';
import SettingHistory from '../../components/SettingHistory.vue';
import PageHeader from '../../components/PageHeader.vue';
import Toggle from '../../components/Toggle.vue';
import type { BackendProps } from '../../types';
import type {
    SettingGroup,
    SettingHistoryRow,
    SettingItem,
    SettingsPageProps,
    SettingValue,
} from '../../types-admin';

/**
 * Die Einstellungen (K-07, Mockup 18/3): Gruppen als Filterzeile, je Wert eine flache Karte mit Eingabe,
 * «geändert von … am … · alt → neu» und Scope; rechts der Verlauf des gewählten Werts mit «Wiederherstellen».
 */
const props = defineProps<SettingsPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const flash = computed(() => page.props.backend.flash?.status ?? null);
const isWide = useMediaQuery('(min-width: 1024px)');

const groups = computed<SettingGroup[]>(() => props.groups ?? []);
const activeKey = ref(props.group ?? groups.value[0]?.key ?? '');
watch(
    () => props.group,
    (g) => {
        if (g) {
            activeKey.value = g;
        }
    },
);
const active = computed(
    () =>
        groups.value.find((g) => g.key === activeKey.value) ??
        groups.value[0] ??
        null,
);
const items = computed<SettingItem[]>(() => active.value?.items ?? []);

function chooseGroup(key: string): void {
    activeKey.value = key;
    historyKey.value = null;
    if (props.urls?.index) {
        router.get(
            props.urls.index,
            { group: key },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }
}

/* ── Entwürfe je Wert ── */

const drafts = reactive<Record<string, string | boolean>>({});
const errors = reactive<Record<string, string>>({});
const saving = ref<string | null>(null);

function draftOf(item: SettingItem): string | boolean {
    if (item.key in drafts) {
        return drafts[item.key] as string | boolean;
    }
    if (item.type === 'bool') {
        return item.value === true;
    }

    return item.value === null ? '' : String(item.value);
}

function isDirty(item: SettingItem): boolean {
    if (item.type === 'link' || !(item.key in drafts)) {
        return false;
    }

    return parseSettingInput(item.type, draftOf(item)) !== item.value;
}

/* Autosave je Wert: Schalter sofort, Zahlen nach einer kurzen Pause — kein «Speichern» mehr */
const timers: Record<string, ReturnType<typeof setTimeout>> = {};
const savedKey = ref<string | null>(null);
let savedTimer: ReturnType<typeof setTimeout> | undefined;

function setDraft(item: SettingItem, value: string | boolean): void {
    drafts[item.key] = value;
    delete errors[item.key];
    clearTimeout(timers[item.key]);
    timers[item.key] = setTimeout(
        () => save(item),
        item.type === 'bool' ? 0 : 700,
    );
}

function save(item: SettingItem): void {
    if (!isDirty(item)) {
        delete drafts[item.key];

        return;
    }
    if (item.type === 'int' && String(draftOf(item)).trim() === '') {
        return;
    }
    saving.value = item.key;
    delete errors[item.key];
    announceSaving();
    router.put(
        props.urls.update,
        { key: item.key, value: parseSettingInput(item.type, draftOf(item)) },
        {
            preserveScroll: true,
            preserveState: true,
            async: true,
            only: ['groups', 'errors'],
            onSuccess: () => {
                delete drafts[item.key];
                announceSaved(`«${item.label}»`);
                savedKey.value = item.key;
                clearTimeout(savedTimer);
                savedTimer = setTimeout(() => (savedKey.value = null), 2600);
            },
            onError: (e) => {
                errors[item.key] = e.value ?? Object.values(e)[0] ?? '';
                announceError(errors[item.key] ?? '');
            },
            onFinish: () => {
                saving.value = null;
            },
        },
    );
}

/* ── Verlauf ── */

const historyKey = ref<string | null>(null);
const historyItem = computed<SettingItem | null>(() => {
    const all = items.value;

    return (
        all.find((i) => i.key === historyKey.value) ??
        (isWide.value ? (all[0] ?? null) : null)
    );
});

function showHistory(item: SettingItem): void {
    historyKey.value =
        historyKey.value === item.key && !isWide.value ? null : item.key;
}

function lastChange(item: SettingItem): SettingHistoryRow | null {
    const history = item.history ?? [];

    return history.find((h) => h.current) ?? history[0] ?? null;
}

function restore(row: SettingHistoryRow): void {
    router.post(
        row.restore_url,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function shown(value: SettingValue | unknown, item: SettingItem): string {
    return valueText(value, item.unit);
}

const input = controlClasses({ mono: true });
</script>

<template>
    <Head :title="$t('Einstellungen')" />
    <PageHeader
        :title="$t('Einstellungen')"
        :crumb="$t(':site › Verwaltung', { site: siteName })"
        :lead="
            $t(
                'Keine festen Werte im Code — jede Änderung hat einen Verlauf und lässt sich zurücknehmen.',
            )
        "
    />

    <Notice v-if="status && status !== flash" tone="success" class="mb-3.5">{{
        status
    }}</Notice>
    <Notice v-if="can_edit === false" tone="grey" class="mb-3.5">
        {{
            $t(
                'Nur lesen: Einstellungen ändern dürfen Betreiber und Administrator.',
            )
        }}
    </Notice>

    <nav
        v-if="groups.length > 0"
        class="mb-3.5 flex flex-wrap gap-x-[18px] border-b border-ash-light"
        :aria-label="$t('Gruppen')"
    >
        <button
            v-for="g in groups"
            :key="g.key"
            type="button"
            class="-mb-px flex items-baseline gap-1.5 border-b-2 pb-[7px] text-[13px]"
            :class="
                g.key === active?.key
                    ? 'border-lava font-semibold text-basalt'
                    : 'border-transparent text-basalt-light hover:text-basalt'
            "
            :aria-current="g.key === active?.key ? 'true' : undefined"
            @click="chooseGroup(g.key)"
        >
            {{ g.label }}
            <span class="font-mono text-[11.5px] font-normal">{{
                g.items.length
            }}</span>
        </button>
    </nav>

    <div
        v-if="items.length === 0"
        class="rounded-lg border border-ash-light bg-white"
    >
        <EmptyState
            :title="$t('Keine Einstellung')"
            :text="$t('In dieser Gruppe gibt es noch keinen Wert.')"
            icon="settings"
            tone="ash"
        />
    </div>

    <div
        v-else
        class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
    >
        <div class="flex min-w-0 flex-col gap-3">
            <template v-for="item in items" :key="item.key">
                <Card
                    :title="item.label"
                    :class="
                        historyItem?.key === item.key
                            ? 'shadow-[inset_3px_0_0_var(--color-lava)]'
                            : ''
                    "
                >
                    <template v-if="item.help" #sub>{{ item.help }}</template>

                    <form
                        class="flex flex-wrap items-center gap-2"
                        novalidate
                        @submit.prevent="save(item)"
                    >
                        <template v-if="item.type === 'bool'">
                            <Toggle
                                :model-value="draftOf(item) === true"
                                :disabled="!can_edit"
                                :label="item.label"
                                @update:model-value="setDraft(item, $event)"
                            />
                            <span class="text-[12.5px]">{{
                                draftOf(item) === true ? $t('ein') : $t('aus')
                            }}</span>
                        </template>
                        <template v-else-if="item.type === 'int'">
                            <label
                                class="sr-only"
                                :for="`setting-${item.key}`"
                                >{{ item.label }}</label
                            >
                            <input
                                :id="`setting-${item.key}`"
                                type="number"
                                inputmode="numeric"
                                step="1"
                                :min="item.min ?? undefined"
                                :max="item.max ?? undefined"
                                :value="draftOf(item)"
                                :disabled="!can_edit"
                                :class="[
                                    input,
                                    'w-28!',
                                    errors[item.key]
                                        ? 'border-danger ring-danger/15 ring-2'
                                        : '',
                                ]"
                                :aria-invalid="
                                    errors[item.key] ? 'true' : undefined
                                "
                                @input="
                                    setDraft(
                                        item,
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    )
                                "
                            />
                            <span
                                v-if="item.unit"
                                class="text-[12.5px] text-basalt-light"
                                >{{ item.unit }}</span
                            >
                            <span
                                v-if="item.min !== null || item.max !== null"
                                class="text-ash-dark text-[11px]"
                                >{{ item.min ?? '…' }}–{{
                                    item.max ?? '…'
                                }}</span
                            >
                        </template>
                        <template v-else>
                            <span
                                v-if="item.value !== null"
                                class="font-mono text-[12.5px]"
                                >{{ shown(item.value, item) }}</span
                            >
                            <Button
                                v-if="item.href"
                                variant="secondary"
                                size="sm"
                                :href="item.href"
                                >{{ $t('Öffnen →') }}</Button
                            >
                        </template>
                        <span
                            v-if="saving === item.key"
                            class="ml-auto text-[11.5px] text-basalt-light"
                            >{{ $t('speichert …') }}</span
                        >
                        <span
                            v-else-if="savedKey === item.key"
                            class="text-success ml-auto text-[11.5px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                    </form>
                    <p
                        v-if="errors[item.key]"
                        class="text-danger mt-1 text-[11.5px] font-semibold"
                        role="alert"
                    >
                        {{ errors[item.key] }}
                    </p>

                    <p class="mt-2 text-[11.5px] text-basalt-light">
                        <template v-if="item.updated">
                            {{
                                item.updated.at
                                    ? $t('Geändert von :person am :date', {
                                          person:
                                              item.updated.by ?? $t('System'),
                                          date: item.updated.at,
                                      })
                                    : $t('Geändert von :person', {
                                          person:
                                              item.updated.by ?? $t('System'),
                                      })
                            }}
                            <template v-if="lastChange(item)">
                                ·
                                <span class="font-mono">{{
                                    changeText(
                                        lastChange(item)?.old,
                                        lastChange(item)?.new,
                                        item.unit,
                                    )
                                }}</span>
                            </template>
                        </template>
                        <template v-else>
                            {{ $t('Startwert aus dem Code')
                            }}<template v-if="item.default !== null">
                                ({{ shown(item.default, item) }})</template
                            >
                        </template>
                        ·
                        <button
                            type="button"
                            class="text-lava-dark underline hover:text-basalt"
                            :aria-expanded="historyItem?.key === item.key"
                            @click="showHistory(item)"
                        >
                            {{ $t('Verlauf')
                            }}<template v-if="(item.history ?? []).length > 0">
                                ({{ item.history.length }})</template
                            >
                        </button>
                        · {{ $t('Scope:') }}
                        {{
                            item.scope === 'installation'
                                ? $t('Installation')
                                : $t('Site')
                        }}
                    </p>
                </Card>

                <div
                    v-if="!isWide && historyItem?.key === item.key"
                    class="-mt-1"
                >
                    <SettingHistory
                        :item="item"
                        :can-edit="can_edit"
                        @restore="restore"
                    />
                </div>
            </template>
        </div>

        <aside
            v-if="isWide && historyItem"
            class="sticky top-4 min-w-0"
            :aria-label="$t('Verlauf')"
        >
            <SettingHistory
                :item="historyItem"
                :can-edit="can_edit"
                @restore="restore"
            />
        </aside>
    </div>
</template>
