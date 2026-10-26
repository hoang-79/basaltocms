<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import Button from '../../components/Button.vue';
import { controlClasses, labelClasses } from '../../components/control';
import EmptyState from '../../components/EmptyState.vue';
import Field from '../../components/Field.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Table from '../../components/Table.vue';
import { t } from '../../i18n';
import type { SupportProps } from '../../types-extranet';

/**
 * Support-Zugriff im Hersteller-Extranet (CR-004 P-16): eine Kunden-Site dieser Installation befristet und nur lesend
 * öffnen. Der Grund ist Pflicht, der Kunde sieht ein Band und den Eintrag im Protokoll seiner Site. Oben das Formular
 * (Site suchen und wählen, Grund, Dauer), darunter die letzten Zugriffe mit «Öffnen» (im CMS des Herstellers, anderer
 * Host) und «Beenden». Ob jemand fremde Zugriffe beenden darf, entscheidet der Server über die URL.
 */
const props = defineProps<SupportProps>();

const q = ref(props.current.q);
let timer: ReturnType<typeof setTimeout> | undefined;

function search(): void {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            props.urls.index,
            q.value.trim() !== '' ? { q: q.value.trim() } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 350);
}
onBeforeUnmount(() => clearTimeout(timer));

/** Dauer in Minuten: feste Stufen bis zum Höchstwert, die Vorgabe des Servers immer dabei. */
const durations = computed(() => {
    const steps = [15, 30, 60, 120, 240].filter((m) => m <= props.minutes.max);
    if (!steps.includes(props.minutes.default)) {
        steps.push(props.minutes.default);
    }

    return steps.sort((a, b) => a - b);
});
const durationLabel = (minutes: number): string =>
    minutes < 60
        ? t(':count Minuten', { count: minutes })
        : minutes % 60 === 0
          ? t(':count Std.', { count: minutes / 60 })
          : t(':count Minuten', { count: minutes });

const form = useForm({
    site_id: null as number | null,
    reason: '',
    minutes: Math.min(props.minutes.default, props.minutes.max),
});

const reasonTooShort = computed(() => form.reason.trim().length < 10);

function start(): void {
    if (form.site_id === null || reasonTooShort.value) {
        return;
    }
    form.post(props.urls.start, { preserveScroll: true });
}

type Session = SupportProps['sessions'][number];

function end(session: Session): void {
    if (
        session.urls.end === null ||
        !window.confirm(
            t('Support-Zugriff auf «:site» beenden?', { site: session.site }),
        )
    ) {
        return;
    }
    router.post(session.urls.end, {}, { preserveScroll: true });
}

const statusLabel = (session: Session): string =>
    session.active
        ? t('aktiv')
        : session.ended
          ? t('beendet')
          : t('abgelaufen');

const linkClass =
    'inline-flex items-center px-1 py-[5px] text-xs font-semibold text-lava-dark underline hover:text-basalt';
</script>

<template>
    <Head :title="$t('Support-Zugriff')" />
    <PageHeader
        :title="$t('Support-Zugriff')"
        :crumb="$t('Extranet › Support')"
        :lead="
            $t(
                'Befristeter, nur lesender Zugriff in eine Kunden-Site. Der Grund ist Pflicht; der Kunde sieht während des Zugriffs ein Band und danach den Eintrag im Protokoll seiner Site.',
            )
        "
    />

    <section
        class="mb-6 rounded-lg border border-ash-light bg-white"
        aria-labelledby="support-start"
    >
        <div class="px-3.5 pt-3">
            <h2 id="support-start" class="text-sm font-semibold">
                {{ $t('Zugriff starten') }}
            </h2>
            <p class="mt-0.5 text-xs text-basalt-light">
                {{
                    $t(
                        'Geöffnet wird im CMS des Herstellers — ändern lässt sich dort nichts.',
                    )
                }}
            </p>
        </div>
        <form
            class="grid gap-x-5 px-3.5 pt-2 pb-3 lg:grid-cols-2"
            novalidate
            @submit.prevent="start"
        >
            <fieldset class="min-w-0">
                <legend :class="labelClasses">{{ $t('Site') }}</legend>
                <label class="sr-only" for="support-q">{{
                    $t('Site suchen')
                }}</label>
                <input
                    id="support-q"
                    v-model="q"
                    type="search"
                    :placeholder="`⌕ ${$t('Name oder Alias')}`"
                    :class="controlClasses({ small: true })"
                    @input="search"
                />
                <p
                    v-if="sites.length === 0"
                    class="mt-2 rounded-md bg-paper px-2.5 py-2 text-[12px] text-basalt-light"
                >
                    {{
                        current.q
                            ? $t('Keine Kunden-Site passt zu «:q».', {
                                  q: current.q,
                              })
                            : $t(
                                  'Auf dieser Installation gibt es keine Kunden-Site.',
                              )
                    }}
                </p>
                <div v-else class="mt-2 flex flex-col gap-1.5">
                    <label
                        v-for="site in sites"
                        :key="site.id"
                        class="flex cursor-pointer items-start gap-2 rounded-md border px-2.5 py-2 text-[12.5px]"
                        :class="
                            form.site_id === site.id
                                ? 'border-lava-dark bg-lava/10'
                                : 'border-ash-light hover:border-ash'
                        "
                    >
                        <input
                            v-model="form.site_id"
                            type="radio"
                            name="site_id"
                            class="mt-0.5 accent-lava-dark"
                            :value="site.id"
                        />
                        <span class="min-w-0">
                            <strong class="font-semibold">{{
                                site.name
                            }}</strong>
                            <span
                                class="ml-1 font-mono text-[11.5px] break-all text-basalt-light"
                                >{{ site.handle }}</span
                            >
                            <span
                                class="block text-[11.5px] text-basalt-light"
                                >{{
                                    $t('Konto :account', {
                                        account: site.account,
                                    })
                                }}</span
                            >
                        </span>
                    </label>
                </div>
                <p
                    v-if="form.errors.site_id"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                    role="alert"
                >
                    {{ form.errors.site_id }}
                </p>
            </fieldset>

            <div class="min-w-0 max-lg:mt-3">
                <Field
                    v-model="form.reason"
                    :label="$t('Grund')"
                    type="textarea"
                    :rows="4"
                    required
                    :maxlength="500"
                    :error="form.errors.reason"
                    :help="
                        $t(
                            'Mindestens 10 Zeichen — der Kunde sieht ihn im Protokoll.',
                        )
                    "
                />
                <Field
                    :model-value="String(form.minutes)"
                    :label="$t('Dauer')"
                    type="select"
                    :options="
                        durations.map((m) => ({
                            value: String(m),
                            label: durationLabel(m),
                        }))
                    "
                    :error="form.errors.minutes"
                    :help="$t('Danach endet der Zugriff von selbst.')"
                    @update:model-value="form.minutes = Number($event)"
                />
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.site_id === null ||
                            reasonTooShort
                        "
                        >{{ $t('Zugriff starten') }}</Button
                    >
                    <span
                        v-if="form.site_id === null"
                        class="text-[11.5px] text-basalt-light"
                        >{{ $t('Zuerst eine Site wählen.') }}</span
                    >
                </div>
            </div>
        </form>
    </section>

    <section aria-labelledby="support-sessions">
        <h2 id="support-sessions" class="mb-2 text-[15px] font-semibold">
            {{ $t('Zugriffe') }}
        </h2>
        <div
            v-if="sessions.length === 0"
            class="rounded-lg border border-ash-light bg-white"
        >
            <EmptyState
                :title="$t('Noch kein Support-Zugriff')"
                :text="
                    $t(
                        'Jeder Zugriff erscheint hier mit Person, Grund und Dauer.',
                    )
                "
                icon="user"
                tone="ash"
            />
        </div>
        <Table v-else>
            <template #head>
                <tr>
                    <th class="px-3 py-2">{{ $t('Site') }}</th>
                    <th class="px-3 py-2">{{ $t('Person') }}</th>
                    <th class="px-3 py-2">{{ $t('Grund') }}</th>
                    <th class="px-3 py-2">{{ $t('Beginn') }}</th>
                    <th class="px-3 py-2">{{ $t('bis') }}</th>
                    <th class="px-3 py-2">{{ $t('Status') }}</th>
                    <th class="px-3 py-2">
                        <span class="sr-only">{{ $t('Aktionen') }}</span>
                    </th>
                </tr>
            </template>
            <tr
                v-for="session in sessions"
                :key="session.id"
                class="align-top"
                :class="session.active ? 'bg-lava/5' : ''"
            >
                <td
                    :data-label="$t('Site')"
                    class="px-3 py-2.5 text-[13px] font-semibold"
                >
                    {{ session.site }}
                </td>
                <td
                    :data-label="$t('Person')"
                    class="px-3 py-2.5 text-[12.5px]"
                >
                    {{ session.person }}
                    <span
                        v-if="session.own"
                        class="ml-1 rounded-full border border-ash px-2 text-[10px] font-semibold text-basalt-light"
                        >{{ $t('Sie') }}</span
                    >
                </td>
                <td
                    :data-label="$t('Grund')"
                    class="px-3 py-2.5 text-[12.5px] break-words"
                >
                    {{ session.reason }}
                </td>
                <td
                    :data-label="$t('Beginn')"
                    class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                >
                    {{ session.started ?? '—' }}
                </td>
                <td
                    :data-label="$t('bis')"
                    class="px-3 py-2.5 text-xs md:whitespace-nowrap"
                >
                    {{ session.ended ?? session.until }}
                </td>
                <td :data-label="$t('Status')" class="px-3 py-2.5">
                    <StateTag :tone="session.active ? 'lava' : 'grey'">{{
                        statusLabel(session)
                    }}</StateTag>
                </td>
                <td :data-label="$t('Aktionen')" class="px-3 py-2">
                    <div
                        v-if="session.urls.open || session.urls.end"
                        class="flex flex-wrap items-center gap-x-1"
                    >
                        <a
                            v-if="session.urls.open"
                            :href="session.urls.open"
                            :class="linkClass"
                            >{{ $t('Öffnen') }}</a
                        >
                        <Button
                            v-if="session.urls.end"
                            variant="link"
                            size="sm"
                            @click="end(session)"
                            >{{ $t('Beenden') }}</Button
                        >
                    </div>
                    <span v-else class="text-ash-dark text-xs">—</span>
                </td>
            </tr>
        </Table>
    </section>
</template>
