<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type {
    MediaDetail,
    MediaFolder,
    MediaView,
    PageLocaleOption,
} from '../types';
import { useAutosave } from './autosave';
import Button from './Button.vue';
import Card from './Card.vue';
import { controlClasses, labelClasses } from './control';
import Field from './Field.vue';
import { t } from '../i18n';
import { formatBytes, formatDate, kindOf } from './media';
import ModuleSlot from './ModuleSlot.vue';
import Notice from './Notice.vue';
import PairRow from './PairRow.vue';
import { vDialog } from './a11y';

/**
 * Das Detail einer Datei (Mockup 14, rechte Spalte; auf Mobil als Blatt): Vorschau mit Fokuspunkt zum Ziehen,
 * Titel, Urheber, Alt-Text je Sprache mit Pflichtmarke, Ordner, Varianten des Themes, Verwendung, Knöpfe.
 * Im Papierkorb nur Wiederherstellen und Herunterladen. Die Rückfrage beim Löschen nennt die Inhalte (Mockup 14/2).
 * Titel, Urheber, Alt-Text, Ordner und Fokuspunkt speichern sich selbst (Autosave, der Fokuspunkt nach dem Loslassen);
 * Ersetzen, Herunterladen und Papierkorb bleiben Knöpfe.
 */
const props = defineProps<{
    detail: MediaDetail;
    locales: PageLocaleOption[];
    altRequired: boolean;
    variantNames: string[];
    folders: MediaFolder[];
    view: MediaView;
    trashDays?: number;
    canManage?: boolean;
    /** TB 29: Titel, Urheber, Alt-Text, Ordner, Fokuspunkt pflegen (Stufe «bearbeiten») */
    canEdit?: boolean;
}>();

const form = useForm<{
    title: string;
    credit: string;
    alt: Record<string, string>;
    focus_x: number;
    focus_y: number;
    folder_id: number | null;
}>({
    title: '',
    credit: '',
    alt: {},
    focus_x: 0.5,
    focus_y: 0.5,
    folder_id: null,
});

/** Die Rückfrage vor dem Papierkorb — als Karte mit Verwendungen oder als Zeile ohne. */
const confirming = ref(false);

const focusDragging = ref(false);

/* Danach Detail (Varianten «ausstehend») und Kacheln (Alt-Text-Marke) frisch — das Formular füllt sich nicht neu */
const autosave = useAutosave(form, {
    url: () => props.detail.urls.update,
    only: ['selected', 'media', 'errors'],
    enabled: () =>
        props.canEdit !== false &&
        props.view !== 'trash' &&
        props.detail.deleted_at === null &&
        !focusDragging.value,
    labels: {
        title: t('Titel'),
        credit: t('Urheber'),
        alt: t('Alt-Text'),
        focus_x: t('Fokuspunkt'),
        focus_y: t('Fokuspunkt'),
        folder_id: t('Ordner'),
    },
});

function fill(detail: MediaDetail): void {
    const alt: Record<string, string> = {};
    props.locales.forEach((l) => {
        alt[l.locale] = detail.alt?.[l.locale] ?? '';
    });
    form.title = detail.title ?? '';
    form.credit = detail.credit ?? '';
    form.alt = alt;
    form.focus_x = detail.focus_x ?? 0.5;
    form.focus_y = detail.focus_y ?? 0.5;
    form.folder_id = detail.folder_id ?? null;
    form.defaults();
    form.clearErrors();
    autosave.rebase();
    confirming.value = false;
}

/* Je Datei einmal füllen (die Seite setzt `:key` je Datei) — ein frisch geladenes Detail derselben Datei überschreibt nicht, was gerade getippt wird */
watch(
    () => props.detail.id,
    () => fill(props.detail),
    { immediate: true },
);

const errors = computed(() => form.errors as unknown as Record<string, string>);

const inTrash = computed(
    () => props.view === 'trash' || props.detail.deleted_at !== null,
);

const sub = computed(() => {
    const d = props.detail;
    const parts = [kindOf(d.mime, d.name)];
    if (d.width && d.height) {
        parts.push(`${d.width} × ${d.height}`);
    }
    parts.push(formatBytes(d.size));
    if (d.uploaded?.at) {
        parts.push(
            d.uploaded.by
                ? t('hochgeladen :date von :person', {
                      date: formatDate(d.uploaded.at),
                      person: d.uploaded.by,
                  })
                : t('hochgeladen :date', { date: formatDate(d.uploaded.at) }),
        );
    }

    return parts.join(' · ');
});

const folderOptions = computed(() => [
    { value: '', label: t('Alle (kein Ordner)') },
    ...props.folders.map((f) => ({
        value: String(f.id),
        label: `${'  '.repeat(f.depth)}${f.name}`,
    })),
]);

/** Die Varianten des Themes in der Reihenfolge der Station B; was noch fehlt, steht als «ausstehend». */
const variantRows = computed(() => {
    const names =
        props.variantNames.length > 0
            ? props.variantNames
            : props.detail.variants.map((v) => v.name);

    return names.map((name) => {
        const v = props.detail.variants.find((x) => x.name === name);

        return {
            name,
            text: v
                ? `${v.width} × ${v.height}${v.formats.length ? ` · ${v.formats.map((f) => f.toUpperCase()).join(', ')}` : ''}`
                : t('ausstehend'),
        };
    });
});

/** TB 13 Scheibe 3: übernommene Alt-Texte eines Moduls — das Autosave speichert sie, sie gelten sofort (keine Fassungen). */
function applyAlt(value: unknown): void {
    const next = value as Record<string, string>;
    form.alt = { ...form.alt, ...next };
}

function altMissing(locale: string): boolean {
    return (form.alt[locale] ?? '').trim() === '';
}

/* ── Fokuspunkt: ziehen oder klicken, 0–1 je Achse (wie das Seitenbild in Pages/Form) ── */

function setFocus(event: PointerEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    form.focus_x =
        Math.round(
            Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)) *
                100,
        ) / 100;
    form.focus_y =
        Math.round(
            Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)) *
                100,
        ) / 100;
}

function startFocus(event: PointerEvent): void {
    if (inTrash.value) {
        return;
    }
    focusDragging.value = true;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    setFocus(event);
}

function moveFocus(event: PointerEvent): void {
    if (focusDragging.value) {
        setFocus(event);
    }
}

function endFocus(): void {
    if (!focusDragging.value) {
        return;
    }
    focusDragging.value = false;
    autosave.flush();
}

/* ── Ersetzen, Papierkorb, Wiederherstellen ── */

const replaceInput = ref<HTMLInputElement | null>(null);
const replacing = ref(false);

function replaceFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    input.value = '';
    if (!file) {
        return;
    }
    replacing.value = true;
    router.post(
        props.detail.urls.replace,
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                replacing.value = false;
            },
        },
    );
}

const busy = ref(false);

function destroy(): void {
    confirming.value = false;
    busy.value = true;
    router.delete(props.detail.urls.delete, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
}

function restore(): void {
    busy.value = true;
    router.post(
        props.detail.urls.restore,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function usageLine(u: MediaDetail['usages'][number]): string {
    return `${u.locale ? `(${u.locale.toUpperCase()}) · ` : ''}${u.field}`;
}

const secondaryLink =
    'inline-flex items-center justify-center whitespace-nowrap rounded-md border border-ash bg-white px-2.5 py-[5px] text-xs font-semibold leading-tight text-basalt transition-colors hover:bg-paper';
</script>

<template>
    <Card :status="inTrash ? 'warning' : null">
        <template #title>
            <span class="truncate" :title="detail.name">{{ detail.name }}</span>
        </template>
        <template #sub>{{ sub }}</template>

        <!-- Vorschau mit Fokuspunkt -->
        <div
            v-if="detail.is_image && detail.url"
            class="relative overflow-hidden rounded-md bg-paper select-none"
            :class="inTrash ? '' : 'cursor-crosshair touch-none'"
            :title="$t('Fokus :x × :y', { x: form.focus_x, y: form.focus_y })"
            @pointerdown.prevent="startFocus"
            @pointermove="moveFocus"
            @pointerup="endFocus"
            @pointercancel="endFocus"
        >
            <img
                :src="detail.url"
                :alt="detail.name"
                class="block h-[130px] w-full object-cover"
                :class="inTrash ? 'opacity-60' : ''"
                draggable="false"
            />
            <span
                v-if="!inTrash"
                class="pointer-events-none absolute h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-lava bg-white/60 shadow-[0_0_0_2px_rgba(35,38,43,.3)]"
                :style="{
                    left: `${form.focus_x * 100}%`,
                    top: `${form.focus_y * 100}%`,
                }"
                aria-hidden="true"
            />
        </div>
        <div
            v-else
            class="text-ash-dark flex h-[90px] items-center justify-center rounded-md border border-dashed border-ash text-xs"
        >
            {{ kindOf(detail.mime, detail.name) }} · {{ $t('keine Vorschau') }}
        </div>
        <p
            v-if="detail.is_image && detail.url && !inTrash"
            class="mt-1 text-[11.5px] text-basalt-light"
        >
            {{ $t('Fokuspunkt: ziehen — Varianten werden neu geschnitten.') }}
            <span class="font-mono"
                >{{ form.focus_x }} × {{ form.focus_y }}</span
            ><span
                v-if="autosave.saved('focus_x') || autosave.saved('focus_y')"
                class="text-success ml-1.5 font-semibold"
                >✓ {{ $t('gespeichert') }}</span
            >
        </p>

        <!-- Papierkorb -->
        <template v-if="inTrash">
            <Notice tone="warning" class="mt-3">
                {{
                    detail.deleted_at
                        ? $t('Im Papierkorb seit :date', {
                              date: formatDate(detail.deleted_at),
                          })
                        : $t('Im Papierkorb')
                }}
                —
                {{
                    $tc(
                        'endgültig gelöscht nach :count Tag.|endgültig gelöscht nach :count Tagen.',
                        trashDays ?? 30,
                    )
                }}
            </Notice>
            <div class="mt-3 flex flex-wrap gap-2">
                <Button size="sm" :disabled="busy" @click="restore">{{
                    $t('Wiederherstellen')
                }}</Button>
                <a
                    :href="detail.urls.download"
                    download
                    :class="secondaryLink"
                    >{{ $t('Herunterladen') }}</a
                >
            </div>
        </template>

        <!-- Metadaten -->
        <form v-else class="mt-2" @submit.prevent="autosave.flush()">
            <Field
                v-model="form.title"
                :saved="autosave.saved('title')"
                :label="$t('Titel')"
                :error="errors.title"
                :placeholder="$t('Titel für Beschriftungen')"
            />
            <Field
                v-model="form.credit"
                :saved="autosave.saved('credit')"
                :label="$t('Urheber')"
                :error="errors.credit"
                :placeholder="$t('Fotografin, Agentur …')"
            />

            <span
                :class="labelClasses"
                class="mt-2.5 flex items-center gap-1.5"
            >
                {{ $t('Alt-Text je Sprache') }}
                <span
                    v-if="altRequired"
                    class="rounded bg-lava/15 px-1.5 py-px text-[9px] font-semibold tracking-normal text-lava-dark normal-case"
                    >{{ $t('Pflicht') }}</span
                >
            </span>
            <div
                v-for="l in locales"
                :key="l.locale"
                class="mb-2 grid grid-cols-[2.2rem_1fr] items-center gap-2"
            >
                <label
                    :for="`alt-${detail.id}-${l.locale}`"
                    class="font-mono text-[11px] text-basalt-light"
                    >{{ l.locale.toUpperCase()
                    }}<span
                        v-if="autosave.saved(`alt.${l.locale}`)"
                        class="text-success ml-0.5 font-sans font-semibold"
                        :title="$t('gespeichert')"
                        >✓</span
                    ></label
                >
                <div>
                    <input
                        :id="`alt-${detail.id}-${l.locale}`"
                        v-model="form.alt[l.locale]"
                        type="text"
                        :class="[
                            controlClasses({
                                small: true,
                                error: Boolean(errors[`alt.${l.locale}`]),
                            }),
                            altMissing(l.locale) ? 'border-dashed' : '',
                        ]"
                        :placeholder="
                            altMissing(l.locale) ? $t('Alt-Text fehlt') : ''
                        "
                        :aria-label="
                            $t('Alt-Text :locale', {
                                locale: l.locale.toUpperCase(),
                            })
                        "
                    />
                    <p
                        v-if="errors[`alt.${l.locale}`]"
                        class="text-danger mt-0.5 text-[11px] font-semibold"
                    >
                        {{ errors[`alt.${l.locale}`] }}
                    </p>
                </div>
            </div>
            <!-- TB 13 Scheibe 3: Teile der Module an den Alt-Texten (KI «Alt-Texte» aus dem Bild) -->
            <ModuleSlot
                name="MediaAlt"
                :context="{
                    media: detail.id,
                    mime: detail.mime,
                    alt: form.alt,
                    locales: locales.map((l) => l.locale),
                }"
                @apply="applyAlt($event)"
            />
            <p
                v-if="errors.alt"
                class="text-danger mb-2 text-[11px] font-semibold"
            >
                {{ errors.alt }}
            </p>

            <Field
                v-if="folders.length > 0"
                :model-value="
                    form.folder_id === null ? '' : String(form.folder_id)
                "
                :saved="autosave.saved('folder_id')"
                :label="$t('Ordner')"
                type="select"
                :options="folderOptions"
                :error="errors.folder_id"
                @update:model-value="
                    form.folder_id = $event === '' ? null : Number($event)
                "
            />

            <template v-if="detail.is_image">
                <span :class="labelClasses" class="mt-2.5">{{
                    $t('Varianten · WebP und AVIF')
                }}</span>
                <p
                    v-if="detail.variants_state === 'pending'"
                    class="text-warning text-[12px]"
                >
                    {{ $t('Varianten werden erzeugt …') }}
                </p>
                <p
                    v-else-if="variantRows.length === 0"
                    class="text-[12px] text-basalt-light"
                >
                    {{ $t('Keine Varianten für diese Datei.') }}
                </p>
                <dl v-else>
                    <PairRow
                        v-for="v in variantRows"
                        :key="v.name"
                        :label="v.name"
                    >
                        <span class="font-mono text-[11.5px] font-normal">{{
                            v.text
                        }}</span>
                    </PairRow>
                </dl>
            </template>

            <span :class="labelClasses" class="mt-2.5">{{
                $tc(
                    'Verwendung · in :count Inhalt|Verwendung · in :count Inhalten',
                    detail.usages.length,
                )
            }}</span>
            <p
                v-if="detail.usages.length === 0"
                class="text-[12px] text-basalt-light"
            >
                {{ $t('Noch nirgends eingebunden.') }}
            </p>
            <ul v-else class="text-[12.5px] leading-[1.7]">
                <li
                    v-for="(u, i) in detail.usages"
                    :key="`${u.entry_id}-${u.locale}-${u.field}-${i}`"
                >
                    <a :href="u.url" class="text-lava-dark underline">{{
                        u.title
                    }}</a>
                    <span class="text-basalt-light"> {{ usageLine(u) }}</span>
                </li>
            </ul>

            <!-- Knöpfe -->
            <div
                v-if="canManage !== false"
                class="mt-3 flex flex-wrap items-center gap-2"
            >
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="replacing"
                    @click="replaceInput?.click()"
                    >{{ replacing ? $t('Ersetzt …') : $t('Ersetzen') }}</Button
                >
                <input
                    ref="replaceInput"
                    type="file"
                    class="hidden"
                    accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,application/pdf"
                    @change="replaceFile"
                />
                <a
                    :href="detail.urls.download"
                    download
                    :class="secondaryLink"
                    >{{ $t('Herunterladen') }}</a
                >
                <Button
                    variant="danger"
                    size="sm"
                    :disabled="busy"
                    @click="confirming = true"
                    >{{ $t('In den Papierkorb') }}</Button
                >
            </div>
            <p
                v-if="canManage !== false"
                class="mt-2 text-[11.5px] text-basalt-light"
            >
                {{ $t('Änderungen speichern sich selbst.') }}
            </p>

            <!-- Ohne Verwendung: kurze Bestätigung in der Zeile -->
            <p
                v-if="confirming && detail.usages.length === 0"
                class="mt-2 flex flex-wrap items-center gap-2 text-[12.5px]"
                role="alert"
            >
                <span>{{
                    $t('«:name» in den Papierkorb?', { name: detail.name })
                }}</span>
                <Button variant="danger" size="sm" @click="destroy">{{
                    $t('Ja, in den Papierkorb')
                }}</Button>
                <Button variant="link" size="sm" @click="confirming = false">{{
                    $t('Abbrechen')
                }}</Button>
            </p>
        </form>

        <div v-if="$slots.default" class="mt-3">
            <slot />
        </div>
    </Card>

    <!-- Mit Verwendung: Rückfrage als Karte (Mockup 14/2) -->
    <div
        v-if="confirming && detail.usages.length > 0"
        class="fixed inset-0 z-40 flex items-center justify-center bg-basalt/40 p-4"
        @click.self="confirming = false"
    >
        <Card
            status="danger"
            :title="$t(':name in den Papierkorb?', { name: detail.name })"
            v-dialog="() => (confirming = false)"
            class="w-full max-w-[460px] shadow-[0_20px_50px_-20px_rgba(35,38,43,.5)]"
            role="dialog"
            aria-modal="true"
            :aria-label="$t(':name in den Papierkorb?', { name: detail.name })"
        >
            <p class="text-[13px]">
                {{ $t('Das Bild ist') }}
                <b>{{
                    $tc(
                        'in :count Inhalt eingebunden|in :count Inhalten eingebunden',
                        detail.usages.length,
                    )
                }}</b
                >.
                {{
                    $tc(
                        'Im Papierkorb bleibt es :count Tag; die Seiten zeigen bis dahin die Variante aus dem Cache, danach keine Fläche.|Im Papierkorb bleibt es :count Tage; die Seiten zeigen bis dahin die Variante aus dem Cache, danach keine Fläche.',
                        trashDays ?? 30,
                    )
                }}
            </p>
            <ul class="mt-2 text-[12.5px] leading-[1.7]">
                <li
                    v-for="(u, i) in detail.usages"
                    :key="`${u.entry_id}-${u.locale}-${u.field}-${i}`"
                >
                    <a :href="u.url" class="underline">{{ u.title }}</a>
                    <span class="text-basalt-light"> {{ usageLine(u) }}</span>
                </li>
            </ul>
            <div class="mt-3.5 flex flex-wrap justify-end gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    @click="confirming = false"
                    >{{ $t('Abbrechen') }}</Button
                >
                <Button variant="danger" size="sm" @click="destroy">{{
                    $t('Trotzdem in den Papierkorb')
                }}</Button>
            </div>
        </Card>
    </div>
</template>
