<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useAutosave } from '../autosave';
import Button from '../Button.vue';
import { controlClasses } from '../control';
import Field from '../Field.vue';
import RichTextField from '../RichTextField.vue';
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
            class="grid gap-x-4 gap-y-3 md:grid-cols-2"
            novalidate
            @submit.prevent="submit"
        >
            <div
                v-for="f in section.fields"
                :key="f.name"
                :class="f.half ? '' : 'md:col-span-2'"
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
            <div v-if="!section.autosave" class="md:col-span-2">
                <Button type="submit" :disabled="form.processing">{{
                    section.submit
                }}</Button>
            </div>
        </form>
    </section>
</template>
