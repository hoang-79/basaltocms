<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { t } from '../../i18n';
import { formatPostal } from '../address';
import { useAutosave } from '../autosave';
import Button from '../Button.vue';
import { controlClasses } from '../control';
import CountrySelect from '../CountrySelect.vue';
import Field from '../Field.vue';
import PhoneInput from '../PhoneInput.vue';
import RichTextField from '../RichTextField.vue';
import { fillFrom, filled, handoff, typedSince } from './lookup';
import LookupInput from './LookupInput.vue';
import { mergeServerValues } from './state';
import type { AdminField, AdminFormSection } from './types';

/**
 * Ein Formular aus Beschreibung (CR-018, TB 99) mit den Feldern des Kerns. Mit `autosave` speichert es nach jeder
 * Pause selbst (CR-013; Felder «beim Verlassen» erst mit Enter oder beim Verlassen), sonst mit dem Knopf. Die Fehler
 * kommen aus der Prüfung im Controller des Plugins und stehen am Feld.
 */
const props = defineProps<{ section: AdminFormSection }>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const hasFile = props.section.fields.some((f) => f.type === 'file');
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const form = useForm<Record<string, any>>({ ...props.section.values });

const save = props.section.autosave
    ? useAutosave(form, {
          url: props.section.url,
          method: props.section.method,
          onLeave: props.section.fields
              .filter((f) => f.on_leave)
              .map((f) => f.name),
          labels: Object.fromEntries(
              props.section.fields.map((f) => [f.name, f.label]),
          ),
      })
    : null;

// Neue Werte vom Server für dieselbe Adresse (etwa «Neu suchen»): unberührte Felder übernehmen, Getipptes bleibt
let server = JSON.parse(JSON.stringify(props.section.values)) as Record<
    string,
    unknown
>;
watch(
    () => props.section.values,
    (values) => {
        const { updates, clean } = mergeServerValues(
            form.data(),
            server,
            values,
        );
        server = JSON.parse(JSON.stringify(values)) as Record<string, unknown>;
        Object.assign(form, updates);
        if (clean) {
            form.defaults({ ...form.data() });
            save?.rebase();
        }
    },
);

/* ── CR-021: Land, Telefon, PLZ, Nachschlagen, Anlegen ohne Knopf ── */

const countries = props.section.countries ?? [];
const root = ref<HTMLElement | null>(null);
const countryOf = (f: AdminField): string =>
    f.country_field &&
    typeof form[f.country_field] === 'string' &&
    form[f.country_field] !== ''
        ? String(form[f.country_field])
        : (f.default_country ?? 'CH');
const lookupUrl = (f: AdminField): string | null =>
    f.lookup ? (props.section.lookups?.[f.lookup.kind] ?? null) : null;

function onPostal(f: AdminField, value: string): void {
    form[f.name] = formatPostal(
        value,
        countries.find((c) => c.code === countryOf(f)),
    );
}

// Telefon und Land: Die Flagge im Telefonfeld setzt das Land des Formulars; ein neues Land setzt die Vorwahl, solange
// das Telefonfeld leer ist. Beim Laden liest das Telefonfeld sein Land aus der Nummer — das zählt nicht als Wahl.
const phoneCountry = reactive<Record<string, string>>({});
for (const f of props.section.fields.filter((x) => x.type === 'phone')) {
    phoneCountry[f.name] = countryOf(f);
}
let phoneReady = false;
function onPhoneCountry(f: AdminField, code: string): void {
    phoneCountry[f.name] = code;
    const countryField = f.country_field;
    if (!phoneReady || !countryField || form[countryField] === code) {
        return;
    }
    // Die Vorwahl setzt das Land nur, solange keine PLZ dieses Lands steht — sonst würde die PLZ ungültig
    const postalTaken = props.section.fields.some(
        (x) =>
            x.type === 'postal' &&
            x.country_field === countryField &&
            filled(form[x.name]),
    );
    if (!postalTaken) {
        form[countryField] = code;
    }
}
watch(
    () =>
        props.section.fields
            .filter((x) => x.type === 'phone' && x.country_field)
            .map((x) => form[x.country_field as string] as unknown),
    () => {
        for (const f of props.section.fields.filter(
            (x) => x.type === 'phone' && x.country_field,
        )) {
            if (!filled(form[f.name])) {
                phoneCountry[f.name] = countryOf(f);
            }
        }
    },
);

function onLookup(f: AdminField, values: Record<string, string>): void {
    if (!f.lookup) {
        return;
    }
    Object.assign(form, fillFrom(values, f.lookup.fill));
    void nextTick(() => maybeCreate());
}

// Anlegen ohne Knopf: der erste Eintrag in einem der genannten Felder legt an; die Antwort führt auf die Seite des
// neuen Eintrags. Was unterwegs noch getippt wird, übernimmt dort das Formular mit denselben Feldern.
const creates = props.section.creates ?? [];
/** Hinweis im Modal des Fliesstexts: Formulare mit Knopf übernehmen erst mit dem Knopf (leer = «wird automatisch gespeichert»). */
const richTextNote =
    props.section.autosave || creates.length > 0
        ? ''
        : t('Fliesstext · wird mit «:button» gespeichert', {
              button: props.section.submit,
          });
const creating = ref(false);
const createLabels = creates
    .map((n) => props.section.fields.find((f) => f.name === n)?.label ?? n)
    .map((label) => `«${label}»`)
    .join(` ${t('oder')} `);

function maybeCreate(): void {
    if (creates.length === 0 || creating.value) {
        return;
    }
    if (!creates.some((n) => filled(form[n]))) {
        return;
    }
    creating.value = true;
    handoff.from = props.section.url;
    handoff.sent = JSON.parse(JSON.stringify(form.data())) as Record<
        string,
        unknown
    >;
    handoff.latest = handoff.sent;
    form.submit(props.section.method, props.section.url, {
        preserveScroll: true,
        forceFormData: hasFile,
        onError: () => {
            creating.value = false;
            handoff.from = null;
        },
    });
}
watch(
    () => form.data(),
    (data) => {
        if (creating.value && handoff.from === props.section.url) {
            handoff.latest = JSON.parse(JSON.stringify(data)) as Record<
                string,
                unknown
            >;
        }
    },
    { deep: true },
);
function onFocusIn(event: FocusEvent): void {
    if (!creating.value) {
        return;
    }
    const holder = (event.target as HTMLElement | null)?.closest('[data-feld]');
    handoff.focus = holder?.getAttribute('data-feld') ?? null;
}

onMounted(() => {
    void nextTick(() => {
        phoneReady = true;
    });
    // Das Formular des eben angelegten Eintrags: was beim Anlegen noch dazukam, übernehmen und weiter fokussieren
    if (handoff.from === null || handoff.from === props.section.url) {
        return;
    }
    const names = props.section.fields.map((f) => f.name);
    const sentNames = Object.keys(handoff.sent);
    if (sentNames.length === 0 || sentNames.some((n) => !names.includes(n))) {
        return;
    }
    const typed = typedSince(handoff.sent, handoff.latest);
    const focus = handoff.focus;
    handoff.from = null;
    void nextTick(() => {
        Object.assign(form, typed);
        if (focus) {
            root.value
                ?.querySelector<HTMLElement>(
                    `[data-feld="${focus}"] input, [data-feld="${focus}"] select, [data-feld="${focus}"] textarea, [data-feld="${focus}"] button`,
                )
                ?.focus();
        }
    });
});

function submit(): void {
    form.submit(props.section.method, props.section.url, {
        preserveScroll: true,
        forceFormData: hasFile,
        onSuccess: () => form.reset(),
    });
}

const control = controlClasses();
const native = (f: AdminField): boolean =>
    ['url', 'password', 'date', 'time', 'datetime', 'color'].includes(f.type);
const inputType = (f: AdminField): string =>
    f.type === 'datetime' ? 'datetime-local' : f.type;
</script>

<template>
    <section
        class="rounded-lg border border-ash-light bg-white p-4"
        :aria-label="section.title ?? undefined"
    >
        <h2 v-if="section.title" class="mb-3 text-[14px] font-semibold">
            {{ section.title }}
        </h2>
        <form
            ref="root"
            class="grid gap-x-4 gap-y-3 md:grid-cols-2"
            novalidate
            @submit.prevent="creates.length > 0 ? maybeCreate() : submit()"
            @focusin="onFocusIn"
        >
            <div
                v-for="f in section.fields"
                :key="f.name"
                :data-feld="f.name"
                :class="f.half ? '' : 'md:col-span-2'"
                @focusout="creates.includes(f.name) && maybeCreate()"
            >
                <label
                    v-if="f.type === 'checkbox'"
                    class="flex min-h-6 items-start gap-2 text-[13px]"
                >
                    <input
                        v-model="form[f.name]"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 accent-lava-dark"
                        :aria-describedby="
                            f.help ? `hilfe-${f.name}` : undefined
                        "
                    />
                    <span>
                        {{ f.label }}
                        <span
                            v-if="f.help"
                            :id="`hilfe-${f.name}`"
                            class="block text-[11.5px] text-basalt-light"
                            >{{ f.help }}</span
                        >
                        <span
                            v-if="errors[f.name]"
                            class="text-danger block text-[11.5px]"
                            role="alert"
                            >{{ errors[f.name] }}</span
                        >
                    </span>
                </label>
                <fieldset v-else-if="f.type === 'image'">
                    <legend class="text-[13px] font-semibold">
                        {{ f.label }}
                        <span
                            v-if="save?.saved(f.name)"
                            class="text-success ml-1.5 text-[11.5px] font-semibold"
                            >✓ {{ $t('gespeichert') }}</span
                        >
                    </legend>
                    <p
                        v-if="f.help"
                        :id="`hilfe-${f.name}`"
                        class="text-[11.5px] text-basalt-light"
                    >
                        {{ f.help }}
                    </p>
                    <div
                        class="mt-1 grid grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] gap-2"
                        :aria-describedby="
                            f.help ? `hilfe-${f.name}` : undefined
                        "
                    >
                        <label
                            class="flex h-20 cursor-pointer items-center justify-center rounded-sm border border-ash-light p-1 text-center text-[11.5px] has-[:checked]:border-lava has-[:checked]:ring-2 has-[:checked]:ring-lava/40 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-basalt"
                        >
                            <input
                                v-model="form[f.name]"
                                type="radio"
                                :name="f.name"
                                :value="null"
                                class="sr-only"
                            />
                            {{ f.none }}
                        </label>
                        <label
                            v-for="image in f.images ?? []"
                            :key="image.id"
                            class="flex h-20 cursor-pointer items-center justify-center rounded-sm border border-ash-light bg-white p-1 has-[:checked]:border-lava has-[:checked]:ring-2 has-[:checked]:ring-lava/40 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-basalt"
                            :title="image.name"
                        >
                            <input
                                v-model="form[f.name]"
                                type="radio"
                                :name="f.name"
                                :value="image.id"
                                class="sr-only"
                            />
                            <img
                                :src="image.url"
                                :alt="image.name"
                                class="max-h-full max-w-full object-contain"
                            />
                        </label>
                    </div>
                    <p
                        v-if="errors[f.name]"
                        class="text-danger mt-1 text-[11.5px]"
                        role="alert"
                    >
                        {{ errors[f.name] }}
                    </p>
                </fieldset>
                <Field
                    v-else-if="f.type === 'richtext'"
                    :label="f.label"
                    :help="f.help"
                    :error="errors[f.name]"
                    :required="f.required"
                    :saved="save?.saved(f.name)"
                    :waiting="save?.waiting(f.name)"
                >
                    <template #default="{ id, invalid }">
                        <RichTextField
                            :id="id"
                            v-model="form[f.name]"
                            :label="f.label"
                            :placeholder="f.placeholder"
                            :invalid="invalid"
                            :subtitle="richTextNote"
                        />
                    </template>
                </Field>
                <Field
                    v-else-if="f.type === 'country'"
                    :label="f.label"
                    :help="f.help"
                    :error="errors[f.name]"
                    :required="f.required"
                    :saved="save?.saved(f.name)"
                    :waiting="save?.waiting(f.name)"
                >
                    <template #default="{ id, invalid }">
                        <CountrySelect
                            :id="id"
                            v-model="form[f.name]"
                            :countries="countries"
                            :label="f.label"
                            :invalid="invalid"
                        />
                    </template>
                </Field>
                <Field
                    v-else-if="f.type === 'phone'"
                    :label="f.label"
                    :help="f.help"
                    :error="errors[f.name]"
                    :required="f.required"
                    :saved="save?.saved(f.name)"
                    :waiting="save?.waiting(f.name)"
                >
                    <template #default="{ id, invalid }">
                        <PhoneInput
                            :id="id"
                            v-model="form[f.name]"
                            :country="phoneCountry[f.name]"
                            :countries="countries"
                            :invalid="invalid"
                            @update:country="onPhoneCountry(f, $event)"
                        />
                    </template>
                </Field>
                <Field
                    v-else-if="f.type === 'postal'"
                    :label="f.label"
                    :help="f.help"
                    :error="errors[f.name]"
                    :required="f.required"
                    :saved="save?.saved(f.name)"
                    :waiting="save?.waiting(f.name)"
                >
                    <template #default="{ id, invalid, describedby }">
                        <input
                            :id="id"
                            :value="form[f.name]"
                            type="text"
                            inputmode="text"
                            autocomplete="postal-code"
                            :placeholder="
                                countries.find((c) => c.code === countryOf(f))
                                    ?.postal_example ?? f.placeholder
                            "
                            :class="[control, 'font-mono']"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedby"
                            v-bind="save ? save.leave(f.name) : {}"
                            @input="
                                onPostal(
                                    f,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </template>
                </Field>
                <Field
                    v-else-if="f.lookup && lookupUrl(f)"
                    :label="f.label"
                    :help="f.help"
                    :error="errors[f.name]"
                    :required="f.required"
                    :saved="save?.saved(f.name)"
                    :waiting="save?.waiting(f.name)"
                >
                    <template #default="{ id, invalid, describedby }">
                        <LookupInput
                            :id="id"
                            v-model="form[f.name]"
                            :url="lookupUrl(f) as string"
                            :country="countryOf(f)"
                            :invalid="invalid"
                            :describedby="describedby"
                            :placeholder="f.placeholder"
                            :mono="f.mono"
                            @select="onLookup(f, $event)"
                        />
                    </template>
                </Field>
                <Field
                    v-else-if="native(f) || f.type === 'file'"
                    :label="f.label"
                    :help="f.help"
                    :error="errors[f.name]"
                    :required="f.required"
                    :saved="save?.saved(f.name)"
                    :waiting="save?.waiting(f.name)"
                >
                    <template #default="{ id, invalid, describedby }">
                        <input
                            v-if="f.type === 'file'"
                            :id="id"
                            type="file"
                            :accept="f.accept || undefined"
                            :class="control"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedby"
                            @change="
                                form[f.name] =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            "
                        />
                        <input
                            v-else
                            :id="id"
                            v-model="form[f.name]"
                            :type="inputType(f)"
                            :placeholder="f.placeholder"
                            :required="f.required"
                            :autocomplete="
                                f.type === 'password' ? 'off' : undefined
                            "
                            :class="[control, f.mono ? 'font-mono' : '']"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedby"
                            v-bind="
                                f.on_leave && save ? save.leave(f.name) : {}
                            "
                        />
                    </template>
                </Field>
                <Field
                    v-else
                    v-model="form[f.name]"
                    :label="f.label"
                    :type="
                        f.type as
                            'text' | 'email' | 'number' | 'select' | 'textarea'
                    "
                    :options="f.choices"
                    :placeholder="f.placeholder"
                    :help="f.help"
                    :error="errors[f.name]"
                    :required="f.required"
                    :mono="f.mono"
                    :rows="f.rows"
                    :saved="save?.saved(f.name)"
                    :waiting="save?.waiting(f.name)"
                    v-bind="f.on_leave && save ? save.leave(f.name) : {}"
                />
            </div>
            <p
                v-if="creates.length > 0"
                class="text-[12px] text-basalt-light md:col-span-2"
                role="status"
            >
                {{
                    creating
                        ? t('Wird angelegt …')
                        : t(
                              'Wird angelegt, sobald :fields ausgefüllt ist — danach speichert jedes Feld selbst.',
                              { fields: createLabels },
                          )
                }}
            </p>
            <div v-else-if="!section.autosave" class="md:col-span-2">
                <Button type="submit" :disabled="form.processing">{{
                    section.submit
                }}</Button>
            </div>
        </form>
    </section>
</template>
