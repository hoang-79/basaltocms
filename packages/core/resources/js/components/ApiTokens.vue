<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId } from 'vue';
import { postJson, requestError } from '../http';
import { t } from '../i18n';
import type { ApiTokenRow, ProfileOption } from '../types-admin';
import { abilityLabel, expiryText, validationErrors } from './apiTokens';
import Button from './Button.vue';
import { controlClasses, labelClasses } from './control';
import Field from './Field.vue';
import Notice from './Notice.vue';
import StateTag from './StateTag.vue';

/**
 * API-Tokens im Profil (M-17): anlegen mit Name, Site, Fähigkeiten und optionalem Ablaufdatum — das ist eine Aktion
 * mit Knopf, kein Autosave. Der Klartext erscheint genau einmal (aus der JSON-Antwort, nie aus den Seiten-Props);
 * die Liste zeigt Site, Fähigkeiten, letzte Benutzung und Ablauf, widerrufen mit Rückfrage am Eintrag.
 */
const props = defineProps<{
    tokens: ApiTokenRow[];
    sites: ProfileOption[];
    abilities: string[];
    storeUrl: string;
    /** mit Platzhalter `__ID__` */
    destroyUrl: string;
    apiBase: string;
}>();

const name = ref('');
const siteId = ref(props.sites[0]?.value ?? '');
const chosen = ref<string[]>(['read']);
const expiresAt = ref('');
const errors = ref<Record<string, string>>({});
const failure = ref('');
const busy = ref(false);
const created = ref<{ token: string; message: string } | null>(null);
const copied = ref('');
const confirming = ref<number | null>(null);
const createdBox = ref<HTMLElement | null>(null);
const uid = useId();
const tomorrow = computed(() => {
    const d = new Date();
    d.setDate(d.getDate() + 1);

    return d.toISOString().slice(0, 10);
});

function toggleAbility(ability: string, on: boolean): void {
    chosen.value = on
        ? [...new Set([...chosen.value, ability])]
        : chosen.value.filter((a) => a !== ability);
}

async function create(): Promise<void> {
    errors.value = {};
    failure.value = '';
    copied.value = '';
    busy.value = true;
    try {
        const result = await postJson<{ token: string; message: string }>(
            props.storeUrl,
            {
                name: name.value,
                site_id: siteId.value === '' ? null : Number(siteId.value),
                abilities: chosen.value,
                expires_at: expiresAt.value || null,
            },
        );
        created.value = result;
        name.value = '';
        expiresAt.value = '';
        router.reload({ only: ['api_tokens'] });
        await nextTick();
        createdBox.value?.focus();
    } catch (error) {
        errors.value = validationErrors(error);
        if (Object.keys(errors.value).length === 0) {
            failure.value = requestError(error);
        }
    } finally {
        busy.value = false;
    }
}

async function copy(): Promise<void> {
    if (!created.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(created.value.token);
        copied.value = t('Kopiert.');
    } catch {
        copied.value = t('Kopieren ging nicht — bitte von Hand markieren.');
    }
}

function revoke(row: ApiTokenRow): void {
    router.delete(props.destroyUrl.replace('__ID__', String(row.id)), {
        preserveScroll: true,
        onFinish: () => {
            confirming.value = null;
        },
    });
}
</script>

<template>
    <div>
        <p class="py-2 text-[12.5px] text-basalt-light">
            {{
                $t(
                    'Ein Token gilt für genau eine Site. «Lesen» ruft Inhalte, Medien und Navigation ab; «Schreiben» legt an, ändert und löscht — so weit Ihre Rolle auf der Site es erlaubt. Adresse:',
                )
            }}
            <code class="font-mono break-all text-basalt">{{ apiBase }}</code>
        </p>

        <div
            v-if="created"
            ref="createdBox"
            tabindex="-1"
            class="mb-3 focus:outline-none"
        >
            <Notice tone="success" :title="created.message">
                <label :for="`${uid}-token`" class="sr-only">{{
                    $t('Neues Token')
                }}</label>
                <textarea
                    :id="`${uid}-token`"
                    readonly
                    rows="2"
                    :value="created.token"
                    :class="[
                        controlClasses({ mono: true }),
                        'mt-1.5 resize-none break-all',
                    ]"
                    @focus="($event.target as HTMLTextAreaElement).select()"
                />
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <Button variant="secondary" size="sm" @click="copy">{{
                        $t('Kopieren')
                    }}</Button>
                    <Button variant="link" size="sm" @click="created = null">{{
                        $t('Ausblenden')
                    }}</Button>
                    <span
                        role="status"
                        class="text-[11.5px] text-basalt-light"
                        >{{ copied }}</span
                    >
                </div>
            </Notice>
        </div>

        <form
            novalidate
            class="rounded-md border border-ash-light p-3"
            :aria-labelledby="`${uid}-title`"
            @submit.prevent="create"
        >
            <h3 :id="`${uid}-title`" class="text-[13px] font-semibold">
                {{ $t('Neues Token') }}
            </h3>
            <div class="grid gap-x-4 md:grid-cols-2">
                <Field
                    v-model="name"
                    :label="$t('Name')"
                    required
                    :placeholder="$t('z. B. Website-Import')"
                    :help="$t('Woran Sie das Token später erkennen.')"
                    :error="errors.name"
                />
                <Field
                    v-model="siteId"
                    :label="$t('Site')"
                    type="select"
                    required
                    :options="sites"
                    :error="errors.site_id"
                    class="md:mt-0!"
                />
            </div>
            <fieldset
                class="mt-2.5"
                :aria-describedby="
                    errors.abilities ? `${uid}-abilities-error` : undefined
                "
            >
                <legend :class="labelClasses">{{ $t('Fähigkeiten') }}</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px]">
                    <label
                        v-for="ability in abilities"
                        :key="ability"
                        class="flex min-h-6 items-center gap-1.5"
                    >
                        <input
                            type="checkbox"
                            class="h-3.5 w-3.5 accent-lava-dark"
                            :checked="chosen.includes(ability)"
                            :aria-invalid="
                                errors.abilities ? 'true' : undefined
                            "
                            @change="
                                toggleAbility(
                                    ability,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        {{ abilityLabel(ability) }}
                    </label>
                </div>
                <p
                    v-if="errors.abilities"
                    :id="`${uid}-abilities-error`"
                    class="text-danger mt-1 text-[11.5px] font-semibold"
                >
                    {{ errors.abilities }}
                </p>
            </fieldset>
            <Field
                :label="$t('Ablaufdatum')"
                optional
                :error="errors.expires_at"
                :help="
                    $t(
                        'Leer = gilt, bis Sie es widerrufen. Abgelaufen am Ende des Tages.',
                    )
                "
            >
                <template #default="{ id, invalid, describedby }">
                    <input
                        :id="id"
                        v-model="expiresAt"
                        type="date"
                        :min="tomorrow"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :class="[
                            controlClasses({ error: invalid }),
                            'md:max-w-48',
                        ]"
                    />
                </template>
            </Field>
            <p
                v-if="failure"
                class="text-danger mt-2 text-[11.5px] font-semibold"
                role="alert"
            >
                {{ failure }}
            </p>
            <div class="mt-3 flex justify-end">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="busy || sites.length === 0"
                    >{{ $t('Token anlegen') }}</Button
                >
            </div>
        </form>

        <p
            v-if="tokens.length === 0"
            class="pt-3 text-[12.5px] text-basalt-light"
        >
            {{ $t('Noch kein Token.') }}
        </p>
        <ul v-else class="mt-2 divide-y divide-ash-light text-[12.5px]">
            <li
                v-for="t in tokens"
                :key="t.id"
                class="flex flex-wrap items-center justify-between gap-2 py-2"
            >
                <span class="min-w-0">
                    <span class="font-semibold break-words">{{ t.name }}</span>
                    <StateTag
                        v-for="a in t.abilities"
                        :key="a"
                        :tone="a === 'write' ? 'lava' : 'grey'"
                        class="ml-1"
                        >{{ abilityLabel(a) }}</StateTag
                    >
                    <StateTag v-if="t.expired" tone="danger" class="ml-1">{{
                        $t('abgelaufen')
                    }}</StateTag>
                    <span class="block text-[11.5px] text-basalt-light">
                        {{ t.site ?? '—' }} ·
                        {{
                            $t('zuletzt benutzt :date', {
                                date: t.last_used ?? $t('nie'),
                            })
                        }}
                        · {{ expiryText(t) }} ·
                        {{ $t('angelegt :date', { date: t.created ?? '—' }) }}
                    </span>
                </span>
                <span
                    v-if="confirming === t.id"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span class="text-[11.5px] font-semibold">{{
                        $t(
                            'Widerrufen? Anwendungen mit diesem Token verlieren sofort den Zugang.',
                        )
                    }}</span>
                    <Button variant="danger" size="sm" @click="revoke(t)">{{
                        $t('Ja, widerrufen')
                    }}</Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        @click="confirming = null"
                        >{{ $t('Abbrechen') }}</Button
                    >
                </span>
                <Button
                    v-else
                    variant="secondary"
                    size="sm"
                    :aria-label="
                        $t('Token «:name» widerrufen', { name: t.name })
                    "
                    @click="confirming = t.id"
                    >{{ $t('Widerrufen') }}</Button
                >
            </li>
        </ul>
    </div>
</template>
