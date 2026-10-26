<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Hex from './Hex.vue';
import Toggle from './Toggle.vue';
import { useAutosave } from './autosave';
import { t } from '../i18n';
import type { NotificationGroup, NotificationPreferences } from '../types';

/**
 * Einstellungen der Benachrichtigungen (TB 27), Reiter im Profil: ein Hauptschalter, je ein Schalter für Glocke und
 * E-Mail, darunter je Gruppe die Arten mit beiden Kanälen. Pflicht-Mails (Rechnungen, Kündigung) sind gesperrt und
 * als «Pflicht» markiert; wo die Mail anderswo eingestellt wird (Formular, Kommentar-Block), steht ein Strich.
 * Jede Änderung speichert sich selbst (Autosave wie die übrigen Einstellungen).
 */
const props = defineProps<{
    groups: NotificationGroup[];
    settings: NotificationPreferences;
    url: string;
    email: string;
}>();

const form = useForm<NotificationPreferences>({
    enabled: props.settings.enabled,
    bell: props.settings.bell,
    mail: props.settings.mail,
    types: JSON.parse(
        JSON.stringify(props.settings.types),
    ) as NotificationPreferences['types'],
});

const labels = computed<Record<string, string>>(() => {
    const out: Record<string, string> = {
        enabled: t('Benachrichtigungen'),
        bell: t('Glocke'),
        mail: t('E-Mail'),
    };
    for (const g of props.groups) {
        for (const type of g.types) {
            out[`types.${type.key}.bell`] = `${type.label} · ${t('Glocke')}`;
            out[`types.${type.key}.mail`] = `${type.label} · ${t('E-Mail')}`;
        }
    }

    return out;
});

const save = useAutosave(form, {
    url: () => props.url,
    delay: 300,
    labels: labels.value,
});

function channel(key: string, which: 'bell' | 'mail'): boolean {
    return form.types[key]?.[which] ?? false;
}

function setChannel(key: string, which: 'bell' | 'mail', value: boolean): void {
    form.types[key] = {
        ...(form.types[key] ?? { bell: false, mail: false }),
        [which]: value,
    };
}

/** Vorgaben der Arten wiederherstellen — die Hauptschalter bleiben, wie sie sind. */
function defaults(): void {
    for (const g of props.groups) {
        for (const type of g.types) {
            form.types[type.key] = { bell: type.bell, mail: type.mail };
        }
    }
}

const count = computed(() =>
    props.groups.reduce((n, g) => n + g.types.length, 0),
);
</script>

<template>
    <div class="grid gap-4">
        <section
            class="grid gap-3 rounded-lg border border-ash-light bg-white p-4"
            :aria-label="$t('Grundeinstellung')"
        >
            <div class="flex items-start justify-between gap-4">
                <span>
                    <span class="block text-[13.5px] font-semibold">{{
                        $t('Benachrichtigungen erhalten')
                    }}</span>
                    <span class="block text-[12.5px] text-basalt-light">{{
                        $t(
                            'Aus: keine Glocke und keine Mails — ausser Pflicht-Mails wie Rechnungen und Kündigung.',
                        )
                    }}</span>
                </span>
                <Toggle
                    v-model="form.enabled"
                    :label="$t('Benachrichtigungen erhalten')"
                />
            </div>
            <div
                class="flex items-start justify-between gap-4 border-t border-ash-light pt-3"
            >
                <span>
                    <span class="block text-[13px] font-semibold">{{
                        $t('In der Glocke')
                    }}</span>
                    <span class="block text-[12.5px] text-basalt-light">{{
                        $t('Oben rechts, mit der Zahl der ungelesenen.')
                    }}</span>
                </span>
                <Toggle
                    v-model="form.bell"
                    :disabled="!form.enabled"
                    :label="$t('In der Glocke')"
                />
            </div>
            <div
                class="flex items-start justify-between gap-4 border-t border-ash-light pt-3"
            >
                <span>
                    <span class="block text-[13px] font-semibold">{{
                        $t('Per E-Mail')
                    }}</span>
                    <span class="block text-[12.5px] text-basalt-light">{{
                        $t('An :email, in Ihrer Sprache.', { email })
                    }}</span>
                </span>
                <Toggle
                    v-model="form.mail"
                    :disabled="!form.enabled"
                    :label="$t('Per E-Mail')"
                />
            </div>
        </section>

        <p v-if="count === 0" class="text-[12.5px] text-basalt-light">
            {{ $t('Für Ihre Rolle gibt es noch keine Benachrichtigungen.') }}
        </p>

        <section
            v-for="g in groups"
            :key="`${g.scope}|${g.group}`"
            class="overflow-hidden rounded-lg border border-ash-light bg-white"
            :aria-label="g.group"
        >
            <div
                class="flex items-center gap-3 border-b border-ash-light bg-paper px-4 py-2"
            >
                <h3 class="flex-1 text-[13px] font-semibold">
                    {{ g.group }}
                    <span
                        v-if="g.scope === 'extranet'"
                        class="ml-1.5 rounded bg-lava px-1.5 py-px align-middle text-[9.5px] font-semibold tracking-[.1em] text-white uppercase"
                        >{{ $t('Extranet') }}</span
                    >
                </h3>
                <span
                    class="text-ash-dark w-10 text-center font-mono text-[10.5px] uppercase"
                    aria-hidden="true"
                    >{{ $t('Glocke') }}</span
                >
                <span
                    class="text-ash-dark w-10 text-center font-mono text-[10.5px] uppercase"
                    aria-hidden="true"
                    >{{ $t('E-Mail') }}</span
                >
            </div>
            <div
                v-for="type in g.types"
                :key="type.key"
                class="flex items-center gap-3 border-b border-ash-light px-4 py-2.5 last:border-b-0"
                :class="!form.enabled ? 'opacity-60' : ''"
            >
                <Hex :icon="type.icon" tone="ash" size="sm" />
                <span class="min-w-0 flex-1">
                    <span class="block text-[13px]">{{ type.label }}</span>
                    <span
                        v-if="type.description"
                        class="block text-[12px] text-basalt-light"
                        >{{ type.description }}</span
                    >
                </span>
                <span class="flex w-10 justify-center">
                    <Toggle
                        :model-value="channel(type.key, 'bell')"
                        :disabled="!form.enabled || !form.bell"
                        :label="`${type.label} — ${$t('Glocke')}`"
                        @update:model-value="
                            setChannel(type.key, 'bell', $event)
                        "
                    />
                </span>
                <!-- «Pflicht» ausserhalb des Flusses unter dem Schalter — sonst stünde er höher als der Glocken-Schalter -->
                <span class="relative flex w-10 justify-center">
                    <template v-if="type.mail_required">
                        <Toggle
                            :model-value="true"
                            disabled
                            :label="`${type.label} — ${$t('E-Mail')} (${$t('Pflicht')})`"
                        />
                        <span
                            class="text-ash-dark absolute top-full left-1/2 -translate-x-1/2 font-mono text-[9.5px] leading-none"
                            aria-hidden="true"
                            >{{ $t('Pflicht') }}</span
                        >
                    </template>
                    <span
                        v-else-if="!type.mail_available"
                        class="text-ash-dark"
                        :title="
                            $t(
                                'Die Mail stellt man anderswo ein — siehe Beschreibung.',
                            )
                        "
                        >—<span class="sr-only">{{
                            $t(
                                'Die Mail stellt man anderswo ein — siehe Beschreibung.',
                            )
                        }}</span></span
                    >
                    <Toggle
                        v-else
                        :model-value="channel(type.key, 'mail')"
                        :disabled="!form.enabled || !form.mail"
                        :label="`${type.label} — ${$t('E-Mail')}`"
                        @update:model-value="
                            setChannel(type.key, 'mail', $event)
                        "
                    />
                </span>
            </div>
        </section>

        <p v-if="count > 0" class="text-[12.5px]">
            <button
                type="button"
                class="text-basalt-light underline hover:text-basalt"
                @click="defaults"
            >
                {{ $t('Vorgaben wiederherstellen') }}
            </button>
            <span
                v-if="save.status.value === 'error'"
                class="text-danger ml-2"
                >{{ Object.values(form.errors)[0] }}</span
            >
        </p>
    </div>
</template>
