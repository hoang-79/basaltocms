<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import CodeBlock from '../../components/CodeBlock.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';
import StateTag from '../../components/StateTag.vue';
import Toggle from '../../components/Toggle.vue';
import {
    cropRect,
    describeVariant,
    modeLabel,
} from '../../components/layoutEditor';
import type { VariantForm } from '../../components/layoutEditor';
import type { BackendProps } from '../../types';

/**
 * Station B — Bildvarianten (K-09, M-28, Mockup 27 Bild 4): Liste mit Name, Masse, Modus, Dichten, `sizes`, Laden und
 * Verwendung; das Formular mit Reitern Masse · Dichten und sizes · Laden; daneben der Zuschnitt an einem Bild aus der
 * Mediathek mit seinem Fokuspunkt. Gespeichert wird `design.images` als Theme-Entwurf.
 */
type VariantRow = {
    form: VariantForm;
    code: string;
    uses: number;
    line: number;
    file: string;
};

const props = defineProps<{
    broken: string | null;
    database: boolean;
    can_edit: boolean;
    theme: string;
    variants: VariantRow[];
    effective: (VariantForm & { describe: string })[];
    images: number;
    sample: {
        name: string;
        url: string;
        width: number;
        height: number;
        focus_x: number;
        focus_y: number;
    } | null;
    urls: Record<string, string>;
}>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);
const errors = computed(() => page.props.errors as Record<string, string>);
const editable = computed(() => props.can_edit && props.database);

const blank = (): VariantForm => ({
    name: '',
    width: 1200,
    height: null,
    mode: 'scale',
    densities: [1],
    sizes: null,
    lazy: true,
});
const selected = ref<string | null>(props.variants[0]?.form.name ?? null);
const row = computed(
    () => props.variants.find((v) => v.form.name === selected.value) ?? null,
);
const form = ref<VariantForm>(
    row.value
        ? { ...row.value.form, densities: [...row.value.form.densities] }
        : blank(),
);
const tab = ref<'masse' | 'dichten' | 'laden'>('masse');
const dirty = computed(
    () =>
        JSON.stringify(form.value) !==
        JSON.stringify(row.value?.form ?? blank()),
);

watch([row, () => props.variants], () => {
    form.value = row.value
        ? { ...row.value.form, densities: [...row.value.form.densities] }
        : blank();
});

function startNew(): void {
    selected.value = null;
    form.value = blank();
    tab.value = 'masse';
}

function toggleDensity(d: number): void {
    const list = form.value.densities.includes(d)
        ? form.value.densities.filter((x) => x !== d)
        : [...form.value.densities, d].sort();
    form.value = { ...form.value, densities: list.length ? list : [1] };
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (selected.value = form.value.name),
    };
    if (row.value) {
        router.put(
            `${props.urls.store}/${row.value.form.name}`,
            form.value as never,
            options,
        );
    } else {
        router.post(props.urls.store ?? '', form.value as never, options);
    }
}

function remove(): void {
    if (row.value) {
        router.delete(`${props.urls.store}/${row.value.form.name}`, {
            preserveScroll: true,
            onSuccess: () =>
                (selected.value = props.variants[0]?.form.name ?? null),
        });
    }
}

const crop = computed(() =>
    props.sample
        ? cropRect(
              { width: props.sample.width, height: props.sample.height },
              form.value,
              { x: props.sample.focus_x, y: props.sample.focus_y },
          )
        : null,
);

const input =
    'mt-1 block w-full rounded-md border border-ash bg-white px-2 py-1 text-[13px] normal-case disabled:bg-paper';
const lab =
    'block text-[12px] font-semibold tracking-wide text-basalt-light uppercase';
</script>

<template>
    <Head :title="$t('Bildvarianten')" />
    <PageHeader
        :title="$t('Bildvarianten')"
        :crumb="$t(':site › Einrichtung', { site: siteName })"
        :station="{ letter: 'B', keyword: 'design' }"
    >
        <template #lead>
            {{
                $t(
                    'Je Theme konfigurierbar (K-09): Name, Breite, Höhe, Modus, Dichten,',
                )
            }}
            <span class="font-mono">sizes</span>,
            {{
                $t(
                    'Lazy Loading. Blöcke wählen die Variante; die Mediathek fragt das Theme, nicht umgekehrt.',
                )
            }}
        </template>
        <template #actions>
            <Button v-if="editable" size="sm" @click="startNew">{{
                $t('+ Variante')
            }}</Button>
        </template>
    </PageHeader>

    <Notice v-if="errors.lava" tone="danger" class="mb-3" role="alert">{{
        errors.lava
    }}</Notice>
    <Notice v-if="broken" tone="danger" class="mb-3" role="alert">{{
        broken
    }}</Notice>
    <Notice v-else-if="!database" tone="grey" class="mb-3">
        {{ $t('Das Theme liegt im Ordner') }}
        <span class="font-mono">themes/{{ theme }}</span> —
        {{
            $t(
                'ändern lässt es sich hier erst nach «In die Datenbank übernehmen».',
            )
        }}
        <button
            v-if="can_edit"
            type="button"
            class="ml-1 underline"
            @click="router.post(urls.adopt ?? '', {}, { preserveScroll: true })"
        >
            {{ $t('In die Datenbank übernehmen') }}
        </button>
    </Notice>

    <nav
        class="mb-3 flex flex-wrap items-end gap-x-4 border-b border-ash-light"
        :aria-label="$t('Ansicht')"
    >
        <Link
            :href="urls.design ?? ''"
            class="-mb-px border-b-2 border-transparent py-2 text-[13px] text-basalt-light hover:text-basalt"
            >{{ $t('Tokens') }}</Link
        >
        <span
            class="-mb-px border-b-2 border-lava py-2 text-[13px] font-semibold"
            aria-current="page"
            >{{ $t('Bildvarianten') }}
            <span class="font-mono text-[11.5px] font-normal">{{
                variants.length
            }}</span></span
        >
    </nav>

    <ul
        class="divide-y divide-ash-light rounded-lg border border-ash-light bg-white"
        :aria-label="$t('Bildvarianten des Themes')"
    >
        <li
            v-for="v in variants"
            :key="v.form.name"
            class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2"
            :class="v.form.name === selected ? 'bg-lava/5' : ''"
        >
            <span class="font-mono text-[12.5px] font-semibold sm:w-24">{{
                v.form.name
            }}</span>
            <span class="font-mono text-[12px] sm:w-28"
                >{{ v.form.width }} × {{ v.form.height ?? '—' }}</span
            >
            <span class="text-[12.5px] sm:w-24">{{
                modeLabel(v.form.mode)
            }}</span>
            <span class="font-mono text-[11px] sm:w-16">{{
                v.form.densities.map((d) => `${d}×`).join(', ')
            }}</span>
            <span
                class="min-w-0 basis-full font-mono text-[11px] break-words text-basalt-light sm:flex-1 sm:basis-0"
                >{{ v.form.sizes ?? '—' }}</span
            >
            <StateTag :tone="v.form.lazy ? 'success' : 'grey'">{{
                v.form.lazy ? 'lazy' : 'eager'
            }}</StateTag>
            <span class="text-[11.5px] text-basalt-light sm:w-24">{{
                $tc(':count Verwendung|:count Verwendungen', v.uses)
            }}</span>
            <Button
                variant="link"
                size="sm"
                :aria-pressed="v.form.name === selected"
                @click="selected = v.form.name"
                >{{ editable ? $t('Bearbeiten') : $t('Ansehen') }}</Button
            >
        </li>
        <li
            v-if="variants.length === 0"
            class="px-3 py-3 text-[12.5px] text-basalt-light"
        >
            {{
                $t(
                    'Das Theme nennt keine Varianten — es gelten die Vorgaben (thumb, content, wide).',
                )
            }}
        </li>
    </ul>
    <p class="mt-1.5 text-[11.5px] text-basalt-light">
        {{
            $t(
                'Wirksam: :variants · «thumb» (Backend) und «content» gibt es immer · WebP immer, AVIF wo der Server es kann · Erzeugung in der Queue · der Fokuspunkt des Bildes (Mediathek) wirkt beim Zuschnitt «exakt».',
                { variants: effective.map((e) => e.name).join(', ') },
            )
        }}
    </p>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <Card
            :title="
                row
                    ? $t('Bildvariante «:name»', { name: row.form.name })
                    : $t('Neue Bildvariante')
            "
            :sub="
                row
                    ? `design.images.${row.form.name} · ${row.file}:${row.line}`
                    : 'design.images'
            "
        >
            <div
                class="mb-3 flex flex-wrap gap-x-4 border-b border-ash-light"
                role="tablist"
                :aria-label="$t('Formular')"
            >
                <button
                    v-for="t in [
                        { key: 'masse', label: $t('Masse') },
                        { key: 'dichten', label: $t('Dichten und sizes') },
                        { key: 'laden', label: $t('Laden') },
                    ] as const"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.key"
                    class="-mb-px border-b-2 py-1.5 text-[13px]"
                    :class="
                        tab === t.key
                            ? 'border-lava font-semibold'
                            : 'border-transparent text-basalt-light'
                    "
                    @click="tab = t.key"
                >
                    {{ t.label }}
                </button>
            </div>
            <div v-show="tab === 'masse'" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label :class="lab"
                        >{{ $t('Name')
                        }}<input
                            v-model="form.name"
                            :disabled="!editable"
                            :class="[input, 'font-mono']"
                        /><span
                            v-if="errors.name"
                            class="text-danger block text-[12px] normal-case"
                            >{{ errors.name }}</span
                        ></label
                    >
                    <label :class="lab"
                        >{{ $t('Breite (px)')
                        }}<input
                            v-model.number="form.width"
                            type="number"
                            min="16"
                            max="8000"
                            :disabled="!editable"
                            :class="input"
                    /></label>
                    <label :class="lab"
                        >{{ $t('Höhe (px)')
                        }}<input
                            :value="form.height ?? ''"
                            type="number"
                            min="16"
                            max="8000"
                            :disabled="!editable"
                            :class="input"
                            @input="
                                form = {
                                    ...form,
                                    height:
                                        ($event.target as HTMLInputElement)
                                            .value === ''
                                            ? null
                                            : Number(
                                                  (
                                                      $event.target as HTMLInputElement
                                                  ).value,
                                              ),
                                }
                            "
                        /><span
                            v-if="errors.height"
                            class="text-danger block text-[12px] normal-case"
                            >{{ errors.height }}</span
                        ></label
                    >
                </div>
                <fieldset>
                    <legend :class="lab">{{ $t('Modus') }}</legend>
                    <div
                        class="mt-1 inline-flex flex-wrap rounded-md border border-ash-light bg-paper p-0.5"
                        role="radiogroup"
                        :aria-label="$t('Modus')"
                    >
                        <button
                            v-for="m in ['scale', 'fit', 'exact'] as const"
                            :key="m"
                            type="button"
                            role="radio"
                            :aria-checked="form.mode === m"
                            :disabled="!editable"
                            class="rounded px-2.5 py-1 text-[12.5px]"
                            :class="
                                form.mode === m
                                    ? 'bg-white font-semibold shadow-sm'
                                    : 'text-basalt-light'
                            "
                            @click="form = { ...form, mode: m }"
                        >
                            {{ modeLabel(m) }}
                        </button>
                    </div>
                    <p class="mt-1 text-[11.5px] text-basalt-light">
                        {{
                            $t(
                                'proportional: nur Breite · Rahmen: passt ins Mass, ohne Zuschnitt · exakt: schneidet auf das Mass, der Fokuspunkt bestimmt den Ausschnitt.',
                            )
                        }}
                    </p>
                </fieldset>
            </div>
            <div v-show="tab === 'dichten'" class="space-y-3">
                <fieldset>
                    <legend :class="lab">{{ $t('Dichten') }}</legend>
                    <div class="mt-1 flex gap-3">
                        <label
                            v-for="d in [1, 2, 3]"
                            :key="d"
                            class="flex items-center gap-1 text-[13px]"
                            ><input
                                type="checkbox"
                                :checked="form.densities.includes(d)"
                                :disabled="!editable"
                                @change="toggleDensity(d)"
                            />{{ d }}×</label
                        >
                    </div>
                    <p class="mt-1 text-[11.5px] text-basalt-light">
                        {{
                            $t(
                                'Höhere Dichten entstehen nur, wenn das Original gross genug ist.',
                            )
                        }}
                    </p>
                </fieldset>
                <label :class="lab"
                    >sizes<input
                        :value="form.sizes ?? ''"
                        :disabled="!editable"
                        :class="[input, 'font-mono']"
                        placeholder="(min-width: 1024px) 960px, 100vw"
                        @input="
                            form = {
                                ...form,
                                sizes:
                                    ($event.target as HTMLInputElement).value ||
                                    null,
                            }
                        "
                    /><span
                        v-if="errors.sizes"
                        class="text-danger block text-[12px] normal-case"
                        >{{ errors.sizes }}</span
                    ></label
                >
            </div>
            <div
                v-show="tab === 'laden'"
                class="flex items-center gap-2 text-[13px]"
            >
                <Toggle
                    v-model="form.lazy"
                    :disabled="!editable"
                    :label="$t('Lazy Loading')"
                />
                {{ $t('Lazy Loading') }}
                <span class="text-[11.5px] text-basalt-light"
                    >—
                    {{
                        $t(
                            'aus für Bilder oben auf der Seite (Bühne), sonst an',
                        )
                    }}</span
                >
            </div>
            <p class="mt-3 text-[12px] text-basalt-light">
                {{ describeVariant(form) }}
            </p>
            <div
                v-if="editable"
                class="mt-3 flex flex-wrap justify-end gap-2 border-t border-ash-light pt-3"
            >
                <Button
                    v-if="row"
                    variant="danger"
                    size="sm"
                    class="mr-auto"
                    @click="remove"
                    >{{ $t('Entfernen') }}</Button
                >
                <Button
                    size="sm"
                    :disabled="!dirty || !form.name"
                    @click="save"
                    >{{ $t('Als Entwurf speichern') }}</Button
                >
            </div>
            <CodeBlock
                v-if="row"
                class="mt-3"
                :code="row.code"
                :start="row.line"
            />
        </Card>

        <div class="space-y-3">
            <Card
                v-if="sample && crop"
                :title="$t('Vorschau · :name', { name: sample.name })"
                :sub="`${form.width} × ${form.height ?? '—'} · ${modeLabel(form.mode)} · ${$t('Fokuspunkt :x % / :y %', { x: Math.round(sample.focus_x * 100), y: Math.round(sample.focus_y * 100) })}`"
            >
                <div class="relative overflow-hidden rounded-md">
                    <img :src="sample.url" alt="" class="block w-full" />
                    <div
                        class="absolute border-2 border-lava shadow-[0_0_0_9999px_rgba(35,38,43,0.45)]"
                        :style="{
                            left: `${crop.x * 100}%`,
                            top: `${crop.y * 100}%`,
                            width: `${crop.w * 100}%`,
                            height: `${crop.h * 100}%`,
                        }"
                        aria-hidden="true"
                    />
                    <span
                        class="absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-lava bg-white/60"
                        :style="{
                            left: `${sample.focus_x * 100}%`,
                            top: `${sample.focus_y * 100}%`,
                        }"
                        aria-hidden="true"
                    />
                </div>
                <p class="mt-2 text-[12px] text-basalt-light">
                    {{
                        $t('Original :width × :height', {
                            width: sample.width,
                            height: sample.height,
                        })
                    }}<template
                        v-if="
                            form.densities.includes(2) &&
                            sample.width < form.width * 2
                        "
                    >
                        ·
                        {{
                            $t('2× bräuchte :width px Breite — bleibt 1×', {
                                width: form.width * 2,
                            })
                        }}</template
                    >
                </p>
            </Card>
            <Notice tone="grey">
                <template v-if="images > 0">{{
                    $t(
                        'Ändert sich eine Variante, werden nach der Veröffentlichung alle :count Bilder der Site in der Queue neu geschnitten. Bis dahin liefert der Cache die alten.',
                        { count: images },
                    )
                }}</template>
                <template v-else>{{
                    $t(
                        'Die Site hat noch keine Bilder — eine geänderte Variante gilt für jedes neue Bild. Die Vorschau des Zuschnitts erscheint mit dem ersten Bild in der Mediathek.',
                    )
                }}</template>
            </Notice>
            <Button variant="secondary" size="sm" :href="urls.theme_editor">{{
                $t('Zur Freigabe im Theme-Editor')
            }}</Button>
        </div>
    </div>
</template>
