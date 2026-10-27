<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { t, tc } from '@core/i18n';
import AutosaveState from '@core/components/AutosaveState.vue';
import { useAutosave } from '@core/components/autosave';
import Button from '@core/components/Button.vue';
import Card from '@core/components/Card.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import EmptyState from '@core/components/EmptyState.vue';
import Field from '@core/components/Field.vue';
import Notice from '@core/components/Notice.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import Table from '@core/components/Table.vue';
import Tabs from '@core/components/Tabs.vue';
import Toggle from '@core/components/Toggle.vue';
import type { TabDef } from '@core/types';
import type { AiFeature } from '../ai';
import type { AiSettingsPageProps } from '../types';

/**
 * Verwaltung › KI-Vorschläge (M-24): Anbieter, Modell und Schlüssel der Site — der Schlüssel geht nie an den Browser,
 * hier steht nur «gesetzt ✓ · …1234» —, Tageslimit, Kostenbremse und Schalter je Funktion; dazu die Nutzung je Monat
 * und die letzten Aufrufe aus dem Protokoll (ohne Inhalte). Oben der Hinweis, dass Inhalte an den Anbieter gehen.
 * Unter 768 px Karten statt Tabellen — nichts scrollt waagrecht.
 */
const props = defineProps<AiSettingsPageProps>();

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

const form = useForm({
    provider: props.settings.provider,
    model: props.settings.model,
    api_key: '',
    daily_limit: props.settings.daily_limit,
    monthly_token_limit: props.settings.monthly_token_limit as number | null,
    features: { ...props.settings.features },
});

const tab = ref('settings');
const tabs = computed<TabDef[]>(() => [
    {
        key: 'settings',
        label: t('Einstellungen'),
        errors: Object.keys(form.errors).length,
    },
    { key: 'usage', label: t('Nutzung'), count: props.months[0]?.count ?? 0 },
]);

const provider = computed(() =>
    props.providers.find((p) => p.value === form.provider),
);

/**
 * CR-013: Die Einstellungen speichern selbst; der Schlüssel erst beim Verlassen (Enter) — leer heisst «bisheriger
 * bleibt», nach dem Speichern leert sich das Feld, der Schlüssel kommt nie zurück in die Seite.
 */
const save = useAutosave(form, {
    url: props.urls.update,
    onLeave: ['api_key'],
    only: ['errors', 'settings'],
    onSaved: () => {
        if (form.api_key !== '') {
            form.api_key = '';
            save.rebase();
        }
    },
});

function removeKey(): void {
    if (
        window.confirm(
            t(
                'Den Schlüssel entfernen? Danach sind alle KI-Knöpfe dieser Site aus.',
            ),
        )
    ) {
        router.delete(props.urls.key, { preserveScroll: true });
    }
}

function toggleFeature(feature: AiFeature, value: boolean): void {
    form.features = { ...form.features, [feature]: value };
}

function number(value: number): string {
    return value.toLocaleString('de-CH');
}

const tokenField = computed({
    get: () =>
        form.monthly_token_limit === null
            ? ''
            : String(form.monthly_token_limit),
    set: (value: string) => {
        form.monthly_token_limit = value.trim() === '' ? null : Number(value);
    },
});
</script>

<template>
    <div>
        <Head :title="$t('KI-Vorschläge')" />
        <PageHeader
            :title="$t('KI-Vorschläge')"
            :crumb="$t('Verwaltung › KI-Vorschläge')"
        >
            <template #lead>
                <b class="font-semibold text-basalt">{{
                    $t('Heute :count von :limit Vorschlägen', {
                        count: today.count,
                        limit: today.limit,
                    })
                }}</b>
                ·
                {{
                    $t(':tokens Tokens in diesem Monat', {
                        tokens: number(today.tokens_month),
                    })
                }}
                ·
                {{
                    settings.has_key
                        ? $t('Schlüssel gesetzt')
                        : $t('kein Schlüssel — alle KI-Knöpfe aus')
                }}
            </template>
        </PageHeader>

        <div class="mb-3 flex flex-col gap-2">
            <Notice tone="lava" :title="$t('Datenschutz')">
                {{
                    $t(
                        'Jeder Vorschlag schickt den betroffenen Inhalt — Text, Quelltext der Übersetzung oder das Bild — mit dem Schlüssel dieser Site an den gewählten Anbieter. Basalto speichert dabei keine Inhalte, nur wer wann welche Funktion genutzt hat. Klären Sie, ob Ihre Inhalte das dürfen, und nennen Sie den Anbieter in Ihrer Datenschutzerklärung.',
                    )
                }}
                <a
                    v-if="provider"
                    :href="provider.privacy_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ml-1 font-semibold text-lava-dark underline"
                    >{{
                        $t('Datenschutz bei :provider', {
                            provider: provider.label,
                        })
                    }}</a
                >
            </Notice>
            <Notice
                v-if="!settings.has_key"
                tone="warning"
                :title="$t('Kein Schlüssel')"
            >
                {{
                    $t(
                        'Ohne Schlüssel sind die Knöpfe «Vorschlag» im Editor, beim Übersetzen, in den Metadaten und in der Mediathek aus — sie nennen den Grund.',
                    )
                }}
            </Notice>
        </div>

        <Tabs v-model="tab" :tabs="tabs">
            <template #settings>
                <form class="space-y-3" @submit.prevent="save.flush()">
                    <Card :title="$t('Anbieter und Schlüssel')">
                        <div class="grid gap-x-4 gap-y-2.5 md:grid-cols-2">
                            <Field
                                v-model="form.provider"
                                type="select"
                                :label="$t('Anbieter')"
                                :options="
                                    providers.map((p) => ({
                                        value: p.value,
                                        label: p.label,
                                    }))
                                "
                                :error="form.errors.provider"
                                :help="
                                    provider && !provider.vision
                                        ? $t(
                                              'Dieser Anbieter liest keine Bilder — Alt-Texte bleiben aus.',
                                          )
                                        : $t('Über das Laravel AI SDK')
                                "
                            />
                            <Field
                                v-model="form.model"
                                :label="$t('Modell')"
                                optional
                                mono
                                :placeholder="
                                    provider?.default_model ??
                                    $t('Vorgabe des Anbieters')
                                "
                                :error="form.errors.model"
                                :help="
                                    $t(
                                        'Leer = Vorgabe des SDK für diesen Anbieter',
                                    )
                                "
                            />
                        </div>
                        <div class="mt-2.5">
                            <label :class="labelClasses" for="ai-key">{{
                                $t('Schlüssel (API-Key)')
                            }}</label>
                            <div class="flex flex-wrap items-center gap-2">
                                <input
                                    id="ai-key"
                                    v-model="form.api_key"
                                    type="password"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="
                                        settings.has_key
                                            ? $t(
                                                  'leer lassen = bisheriger bleibt',
                                              )
                                            : $t('Schlüssel einfügen')
                                    "
                                    :class="[
                                        controlClasses({
                                            error: Boolean(form.errors.api_key),
                                            mono: true,
                                        }),
                                        'min-w-0 flex-1 basis-60',
                                    ]"
                                    :aria-describedby="'ai-key-state'"
                                    v-bind="save.leave('api_key')"
                                />
                                <span
                                    id="ai-key-state"
                                    class="text-[12px] whitespace-nowrap"
                                    :class="
                                        settings.has_key
                                            ? 'text-success font-semibold'
                                            : 'text-basalt-light'
                                    "
                                    >{{
                                        settings.has_key
                                            ? $t('gesetzt ✓ · :hint', {
                                                  hint: settings.key_hint ?? '',
                                              })
                                            : $t('nicht gesetzt')
                                    }}</span
                                >
                                <Button
                                    v-if="settings.has_key"
                                    variant="danger"
                                    size="sm"
                                    @click="removeKey"
                                    >{{ $t('Schlüssel entfernen') }}</Button
                                >
                            </div>
                            <p
                                v-if="form.errors.api_key"
                                class="text-danger mt-1 text-[11.5px] font-semibold"
                            >
                                {{ form.errors.api_key }}
                            </p>
                            <p class="mt-1 text-[11.5px] text-basalt-light">
                                {{
                                    $t(
                                        'Verschlüsselt gespeichert; er verlässt den Server nur in der Anfrage an den Anbieter — nie im Browser, im Protokoll oder im Export.',
                                    )
                                }}
                            </p>
                        </div>
                    </Card>

                    <Card :title="$t('Grenzen')">
                        <div class="grid gap-x-4 gap-y-2.5 md:grid-cols-2">
                            <Field
                                v-model="form.daily_limit"
                                type="number"
                                :label="$t('Tageslimit (Vorschläge je Tag)')"
                                :error="form.errors.daily_limit"
                                :help="
                                    $t(
                                        'Für die ganze Site; dazu höchstens zehn je Person und Minute.',
                                    )
                                "
                            />
                            <Field
                                v-model="tokenField"
                                type="number"
                                optional
                                :label="$t('Kostenbremse (Tokens je Monat)')"
                                :error="form.errors.monthly_token_limit"
                                :help="
                                    $t(
                                        'Leer = ohne Bremse. Ist sie erreicht, gibt es bis Monatsende keine Vorschläge mehr.',
                                    )
                                "
                            />
                        </div>
                    </Card>

                    <Card :title="$t('Funktionen')">
                        <ul class="divide-y divide-ash-light">
                            <li
                                v-for="f in features"
                                :key="f.value"
                                class="flex items-start gap-3 py-2"
                            >
                                <Toggle
                                    :model-value="form.features[f.value]"
                                    :label="f.label"
                                    :disabled="
                                        f.value === 'alt_text' &&
                                        provider?.vision === false
                                    "
                                    @update:model-value="
                                        toggleFeature(f.value, $event)
                                    "
                                />
                                <span class="min-w-0 text-[13px]">
                                    <span class="block font-semibold">{{
                                        f.label
                                    }}</span>
                                    <span
                                        class="block text-[12px] text-basalt-light"
                                        >{{ f.help }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                        <p class="mt-1 text-[11.5px] text-basalt-light">
                            {{
                                $t(
                                    'Jede Funktion arbeitet nur auf Klick und liefert einen Vorschlag; übernommen wird in den Entwurf — veröffentlicht wird nie automatisch.',
                                )
                            }}
                        </p>
                    </Card>

                    <div class="flex justify-end">
                        <AutosaveState :status="save.status.value" />
                    </div>
                    <p v-if="errors.ai" class="text-danger text-[12px]">
                        {{ errors.ai }}
                    </p>
                </form>
            </template>

            <template #usage>
                <div class="space-y-3">
                    <EmptyState
                        v-if="months.length === 0"
                        tone="ash"
                        icon="log"
                        :title="$t('Noch keine Nutzung')"
                        :text="
                            $t(
                                'Jeder Klick auf «Vorschlag» erscheint hier — mit Funktion, Person, Inhalt, Modell und Tokens, ohne den Text.',
                            )
                        "
                    />
                    <template v-else>
                        <h3 :class="labelClasses">
                            {{ $t('Nutzung je Monat') }}
                        </h3>
                        <Table>
                            <template #head>
                                <tr>
                                    <th class="px-3 py-2">{{ $t('Monat') }}</th>
                                    <th class="px-3 py-2">
                                        {{ $t('Vorschläge') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Übernommen') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Gescheitert') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Tokens') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Je Funktion') }}
                                    </th>
                                </tr>
                            </template>
                            <tr v-for="m in months" :key="m.month">
                                <td
                                    class="px-3 py-2.5 font-semibold"
                                    :data-label="$t('Monat')"
                                >
                                    {{ m.label }}
                                </td>
                                <td
                                    class="px-3 py-2.5 font-mono"
                                    :data-label="$t('Vorschläge')"
                                >
                                    {{ m.count }}
                                </td>
                                <td
                                    class="px-3 py-2.5 font-mono"
                                    :data-label="$t('Übernommen')"
                                >
                                    {{ m.applied }}
                                </td>
                                <td
                                    class="px-3 py-2.5 font-mono"
                                    :data-label="$t('Gescheitert')"
                                >
                                    {{ m.failed }}
                                </td>
                                <td
                                    class="px-3 py-2.5 font-mono"
                                    :data-label="$t('Tokens')"
                                >
                                    {{ number(m.tokens) }}
                                </td>
                                <td
                                    class="px-3 py-2.5 text-[12px] text-basalt-light"
                                    :data-label="$t('Je Funktion')"
                                >
                                    <span
                                        v-for="f in features"
                                        :key="f.value"
                                        class="mr-2 inline-block whitespace-nowrap"
                                        >{{ f.label }}
                                        <b class="font-mono text-basalt">{{
                                            m.features[f.value]
                                        }}</b></span
                                    >
                                </td>
                            </tr>
                        </Table>

                        <h3 :class="[labelClasses, 'mt-3']">
                            {{ $t('Letzte Aufrufe') }}
                        </h3>
                        <Table>
                            <template #head>
                                <tr>
                                    <th class="px-3 py-2">{{ $t('Zeit') }}</th>
                                    <th class="px-3 py-2">
                                        {{ $t('Person') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Funktion') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Inhalt oder Medium') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Modell') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Tokens') }}
                                    </th>
                                    <th class="px-3 py-2">
                                        {{ $t('Ergebnis') }}
                                    </th>
                                </tr>
                            </template>
                            <tr v-for="r in recent" :key="r.id">
                                <td
                                    class="px-3 py-2.5 font-mono text-[12px] whitespace-nowrap"
                                    :data-label="$t('Zeit')"
                                >
                                    {{ r.at }}
                                </td>
                                <td
                                    class="px-3 py-2.5"
                                    :data-label="$t('Person')"
                                >
                                    {{ r.user }}
                                </td>
                                <td
                                    class="px-3 py-2.5"
                                    :data-label="$t('Funktion')"
                                >
                                    {{ r.feature_label }}
                                    <span
                                        v-if="r.locale"
                                        class="font-mono text-[11px] text-basalt-light"
                                        >{{ r.locale.toUpperCase() }}</span
                                    >
                                </td>
                                <td
                                    class="px-3 py-2.5 break-words"
                                    :data-label="$t('Inhalt oder Medium')"
                                >
                                    <a
                                        v-if="r.subject"
                                        :href="r.subject.url"
                                        class="text-lava-dark underline"
                                        >{{ r.subject.label }}</a
                                    >
                                    <span v-else class="text-ash-dark">—</span>
                                </td>
                                <td
                                    class="px-3 py-2.5 text-[12px] break-words"
                                    :data-label="$t('Modell')"
                                >
                                    {{ r.provider }}
                                    <span
                                        v-if="r.model"
                                        class="block font-mono text-[11px] text-basalt-light"
                                        >{{ r.model }}</span
                                    >
                                </td>
                                <td
                                    class="px-3 py-2.5 font-mono"
                                    :data-label="$t('Tokens')"
                                >
                                    {{
                                        r.tokens === null
                                            ? '—'
                                            : number(r.tokens)
                                    }}
                                </td>
                                <td
                                    class="px-3 py-2.5"
                                    :data-label="$t('Ergebnis')"
                                >
                                    <StateTag
                                        v-if="r.status === 'error'"
                                        tone="danger"
                                        >{{ r.error }}</StateTag
                                    >
                                    <StateTag
                                        v-else-if="r.applied"
                                        tone="success"
                                        >{{ $t('übernommen') }}</StateTag
                                    >
                                    <StateTag v-else tone="grey">{{
                                        $t('nur vorgeschlagen')
                                    }}</StateTag>
                                </td>
                            </tr>
                            <template #foot>
                                {{
                                    tc(
                                        ':count Aufruf, ohne Inhalte und ohne Schlüssel|Die letzten :count Aufrufe, ohne Inhalte und ohne Schlüssel',
                                        recent.length,
                                    )
                                }}
                            </template>
                        </Table>
                    </template>
                </div>
            </template>
        </Tabs>
    </div>
</template>
