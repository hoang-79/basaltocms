<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useAutosave } from '../components/autosave';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import CodeBlock from '../components/CodeBlock.vue';
import { labelClasses } from '../components/control';
import { contrast, formatRatio, isHex } from '../components/design';
import Field from '../components/Field.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import { t } from '../i18n';

/**
 * Station B — Design (M-15, L-10): Farben, Schriften und Logo des Themes mit den Überschreibungen dieser Site. Jede
 * Änderung speichert sich selbst und ist sofort auf der Site sichtbar (CSS-Variablen, kein Build). Der Kontrast jedes
 * Farbpaars steht daneben; unter 4,5:1 weist der Server das Speichern ab. Bringt das Theme Farbvarianten mit
 * (`palette`, CR-015/TB 83), steht ihre Wahl zuoberst; eigene Farben gelten gegenüber der gewählten Variante.
 */
type Token = {
    name: string;
    theme: string;
    value: string;
    overridden: boolean;
};
type FontToken = Token & { weight: number | null };
type Palette = {
    name: string | null;
    label: string;
    colors: Record<string, string>;
    dark: Record<string, string>;
};

const props = defineProps<{
    colors: Token[];
    fonts: FontToken[];
    pairs: { foreground: string; background: string }[];
    palette: string | null;
    palettes: Palette[];
    min_contrast: number;
    space: number[];
    radius: number | null;
    styles: { path: string; url: string | null }[];
    logo: {
        theme: string | null;
        media: { id: number; name: string; url: string } | null;
    };
    images: { id: number; name: string; url: string }[];
    lava: string;
    file: string | null;
    tailwind: string;
    can_edit: boolean;
    urls: { update: string; reset: string; variants: string };
}>();

const form = useForm({
    palette: props.palette,
    colors: Object.fromEntries(props.colors.map((c) => [c.name, c.value])),
    fonts: Object.fromEntries(props.fonts.map((f) => [f.name, f.value])),
    logo_media_id: props.logo.media?.id ?? null,
});

const labels: Record<string, string> = {
    palette: t('Farbvariante'),
    colors: t('Farbe'),
    fonts: t('Schrift'),
    logo_media_id: t('Logo'),
};
const autosave = useAutosave(form, {
    url: () => props.urls.update,
    delay: 600,
    enabled: () =>
        props.can_edit &&
        Object.values(form.colors).every((v) => isHex(v)) &&
        pairRows.value.every((p) => p.ratio === null || p.ok),
    labels,
});

const pairRows = computed(() =>
    props.pairs.map((pair) => {
        const ratio = contrast(
            form.colors[pair.foreground] ?? '',
            form.colors[pair.background] ?? '',
        );

        return {
            ...pair,
            ratio,
            ok: ratio !== null && ratio >= props.min_contrast,
        };
    }),
);
const failing = computed(() => pairRows.value.filter((p) => !p.ok));

function colorError(name: string): string {
    const server = (form.errors as Record<string, string | undefined>)[
        `colors.${name}`
    ];
    if (server) {
        return server;
    }
    const value = form.colors[name] ?? '';
    if (!isHex(value)) {
        return t('Farbe als #rgb oder #rrggbb.');
    }
    const pair = failing.value.find(
        (p) => p.foreground === name || p.background === name,
    );

    return pair && pair.ratio !== null
        ? t(
              'Kontrast «:foreground» auf «:background» ist :ratio — mindestens 4,5:1.',
              {
                  foreground: pair.foreground,
                  background: pair.background,
                  ratio: formatRatio(pair.ratio),
              },
          )
        : '';
}

/** Die gewählte Farbvariante — ohne Varianten im Theme keine. */
const chosen = computed<Palette | null>(
    () => props.palettes.find((p) => p.name === form.palette) ?? null,
);

/** Der Wert «wie im Theme» einer Farbe: aus der gewählten Variante, sonst aus dem Theme. */
function themeValue(token: Token): string {
    return chosen.value?.colors[token.name] ?? token.theme;
}

/** Der Wert ganz ohne Variante — dahin führt «Alles wie im Theme». */
function baseValue(token: Token): string {
    return (
        props.palettes.find((p) => p.name === null)?.colors[token.name] ??
        token.theme
    );
}

// Wechselt die Variante, folgen ihr alle Farben, die Sie nicht selbst gesetzt haben
watch(
    () => form.palette,
    (next, previous) => {
        const before = props.palettes.find((p) => p.name === previous);
        const after = props.palettes.find((p) => p.name === next);
        if (!before || !after) {
            return;
        }
        for (const token of props.colors) {
            const current = (form.colors[token.name] ?? '').toLowerCase();
            const target = after.colors[token.name];
            if (current === before.colors[token.name] && target) {
                form.colors[token.name] = target;
            }
        }
    },
);

/**
 * Bis zu drei Farben je Variante als Punkte — zuerst Akzent, Link und die dunkle Fläche; nur, was sich vom Grund der
 * Vorschau abhebt (in der dunklen Hälfte verschwände die dunkle Fläche).
 */
function swatches(palette: Palette, dark = false): string[] {
    const colors = dark
        ? { ...palette.colors, ...palette.dark }
        : palette.colors;
    const ground = colors.background ?? '';
    const preferred = ['accent', 'link', 'dark'];
    const rank = (name: string): number =>
        preferred.includes(name) ? preferred.indexOf(name) : preferred.length;
    const names = Object.keys(colors)
        .filter(
            (name) =>
                !['text', 'background', 'line', 'border', 'focus'].includes(
                    name,
                ) &&
                !name.endsWith('-text') &&
                !name.startsWith('on-'),
        )
        .sort((a, b) => rank(a) - rank(b));

    const visible = names
        .map((name) => colors[name] ?? '')
        .filter((color) => (contrast(color, ground) ?? 0) >= 1.4);

    return [...new Set(visible)].slice(0, 3);
}

function hasDark(palette: Palette): boolean {
    return Object.keys(palette.dark).length > 0;
}

function resetColor(token: Token): void {
    form.colors[token.name] = themeValue(token);
}

function resetFont(token: FontToken): void {
    form.fonts[token.name] = token.theme;
}

const changed = computed(
    () =>
        form.palette !== null ||
        props.colors.some((c) => form.colors[c.name] !== themeValue(c)) ||
        props.fonts.some((f) => form.fonts[f.name] !== f.theme) ||
        form.logo_media_id !== null,
);

function resetAll(): void {
    router.delete(props.urls.reset, {
        preserveScroll: true,
        onSuccess: () => {
            form.palette = null;
            props.colors.forEach((c) => (form.colors[c.name] = baseValue(c)));
            props.fonts.forEach((f) => (form.fonts[f.name] = f.theme));
            form.logo_media_id = null;
            form.defaults();
        },
    });
}

/** Als Text, nicht im Template zusammengesetzt — doppelte geschweifte Klammern würde Vue selbst auswerten. */
const logoTag = '{{ site.logo }}';

const logoUrl = computed(
    () =>
        props.images.find((i) => i.id === form.logo_media_id)?.url ??
        props.logo.media?.url ??
        props.logo.theme,
);
</script>

<template>
    <Head :title="$t('Design')" />
    <PageHeader :title="$t('Design')" :crumb="$t('Einrichtung · Station B')">
        <template #lead>
            {{ $t('Farben, Schriften und Logo aus') }}
            <code class="font-mono">design</code>
            {{
                $t(
                    'des Themes. Was Sie hier ändern, gilt nur für diese Site und ist sofort sichtbar — ohne Build.',
                )
            }}
        </template>
        <template #actions>
            <Button
                v-if="can_edit && changed"
                variant="secondary"
                @click="resetAll"
                >{{ $t('Alles wie im Theme') }}</Button
            >
        </template>
    </PageHeader>
    <!-- TB 10 Scheibe 3: Station B hat zwei Ansichten (Mockup 27) -->
    <nav
        class="mb-4 flex flex-wrap items-end gap-x-4 border-b border-ash-light"
        :aria-label="$t('Ansicht')"
    >
        <span
            class="-mb-px border-b-2 border-lava py-2 text-[13px] font-semibold"
            aria-current="page"
            >{{ $t('Tokens') }}</span
        >
        <Link
            :href="urls.variants"
            class="-mb-px border-b-2 border-transparent py-2 text-[13px] text-basalt-light hover:text-basalt"
            >{{ $t('Bildvarianten') }}</Link
        >
    </nav>

    <Notice v-if="!can_edit" class="mb-4">
        {{ $t('Ihre Rolle erlaubt, das Design anzusehen, nicht zu ändern.') }}
    </Notice>
    <Notice v-if="failing.length > 0" tone="danger" class="mb-4" role="alert">
        {{
            $tc(
                ':count Farbpaar erreicht den Mindestkontrast von 4,5:1 nicht — so wird nicht gespeichert.|:count Farbpaare erreichen den Mindestkontrast von 4,5:1 nicht — so wird nicht gespeichert.',
                failing.length,
            )
        }}
    </Notice>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <div class="grid min-w-0 content-start gap-4">
            <Card v-if="palettes.length > 0" :title="$t('Farbvariante')">
                <p class="mb-3 text-[12.5px] text-basalt-light">
                    {{
                        $t(
                            'Das Theme bringt Farbvarianten mit. Ihre eigenen Farben weiter unten gelten zusätzlich.',
                        )
                    }}
                </p>
                <fieldset>
                    <legend class="sr-only">{{ $t('Farbvariante') }}</legend>
                    <div
                        class="grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] gap-2"
                    >
                        <label
                            v-for="option in palettes"
                            :key="option.name ?? ''"
                            class="grid gap-2 rounded-sm border border-ash-light p-2 has-[:checked]:border-lava has-[:checked]:ring-2 has-[:checked]:ring-lava/40 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-basalt"
                            :class="can_edit ? 'cursor-pointer' : ''"
                        >
                            <input
                                v-model="form.palette"
                                type="radio"
                                name="palette"
                                :value="option.name"
                                class="sr-only"
                                :disabled="!can_edit"
                            />
                            <span
                                class="grid h-14 overflow-hidden rounded-sm border border-black/10"
                                :class="hasDark(option) ? 'grid-cols-2' : ''"
                                aria-hidden="true"
                            >
                                <span
                                    class="flex items-center justify-center gap-1"
                                    :style="{
                                        backgroundColor:
                                            option.colors.background,
                                        color: option.colors.text,
                                    }"
                                >
                                    <span class="text-[17px] font-semibold"
                                        >Aa</span
                                    >
                                    <span
                                        v-for="color in swatches(option)"
                                        :key="color"
                                        class="size-2.5 rounded-full"
                                        :style="{ backgroundColor: color }"
                                    ></span>
                                </span>
                                <span
                                    v-if="hasDark(option)"
                                    class="flex items-center justify-center gap-1"
                                    :style="{
                                        backgroundColor:
                                            option.dark.background ??
                                            option.colors.background,
                                        color:
                                            option.dark.text ??
                                            option.colors.text,
                                    }"
                                >
                                    <span class="text-[17px] font-semibold"
                                        >Aa</span
                                    >
                                    <span
                                        v-for="color in swatches(option, true)"
                                        :key="color"
                                        class="size-2.5 rounded-full"
                                        :style="{ backgroundColor: color }"
                                    ></span>
                                </span>
                            </span>
                            <span
                                class="flex flex-wrap items-baseline justify-between gap-x-2 text-[12.5px] font-semibold"
                            >
                                {{ option.label }}
                                <span
                                    v-if="
                                        form.palette === option.name &&
                                        autosave.saved('palette')
                                    "
                                    class="text-success text-[11px] font-normal"
                                    >{{ $t('gespeichert') }}</span
                                >
                            </span>
                        </label>
                    </div>
                </fieldset>
                <p
                    v-if="form.errors.palette"
                    class="text-danger mt-1 text-[11.5px]"
                >
                    {{ form.errors.palette }}
                </p>
            </Card>

            <Card :title="$t('Farben')">
                <p v-if="colors.length === 0" class="text-sm text-basalt-light">
                    {{ $t('Das Theme nennt keine Farben.') }}
                </p>
                <ul class="grid gap-3">
                    <li
                        v-for="token in colors"
                        :key="token.name"
                        class="grid gap-2 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"
                    >
                        <label
                            :for="`farbe-${token.name}`"
                            class="pt-2 font-mono text-[12.5px] font-semibold"
                            >{{ token.name }}</label
                        >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <input
                                    v-model="form.colors[token.name]"
                                    type="color"
                                    class="h-9 w-12 cursor-pointer rounded-sm border border-ash-light bg-white p-0.5"
                                    :aria-label="
                                        $t('Farbwähler :name', {
                                            name: token.name,
                                        })
                                    "
                                    :disabled="!can_edit"
                                />
                                <input
                                    :id="`farbe-${token.name}`"
                                    v-model="form.colors[token.name]"
                                    type="text"
                                    class="w-28 rounded-sm border border-ash-light px-2 py-1.5 font-mono text-[13px]"
                                    :class="
                                        colorError(token.name)
                                            ? 'border-danger'
                                            : ''
                                    "
                                    maxlength="7"
                                    spellcheck="false"
                                    :aria-invalid="!!colorError(token.name)"
                                    :aria-describedby="`farbe-${token.name}-hilfe`"
                                    :disabled="!can_edit"
                                />
                                <span
                                    v-if="
                                        autosave.saved(`colors.${token.name}`)
                                    "
                                    class="text-success text-[11px]"
                                    >{{ $t('gespeichert') }}</span
                                >
                            </div>
                            <p
                                :id="`farbe-${token.name}-hilfe`"
                                class="mt-1 text-[11.5px]"
                                :class="
                                    colorError(token.name)
                                        ? 'text-danger'
                                        : 'text-basalt-light'
                                "
                            >
                                <template v-if="colorError(token.name)">{{
                                    colorError(token.name)
                                }}</template>
                                <template v-else>
                                    {{
                                        chosen && chosen.name !== null
                                            ? `${chosen.label}:`
                                            : $t('Theme:')
                                    }}
                                    <code class="font-mono">{{
                                        themeValue(token)
                                    }}</code>
                                    <button
                                        v-if="
                                            can_edit &&
                                            form.colors[token.name] !==
                                                themeValue(token)
                                        "
                                        type="button"
                                        class="ml-2 underline"
                                        @click="resetColor(token)"
                                    >
                                        {{ $t('zurücksetzen') }}
                                    </button>
                                </template>
                            </p>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card :title="$t('Kontrast (WCAG 2.2 AA)')">
                <p
                    v-if="pairRows.length === 0"
                    class="text-sm text-basalt-light"
                >
                    {{
                        $t(
                            'Keine Farbpaare erkannt. Paare ergeben sich aus den Namen:',
                        )
                    }}
                    <code class="font-mono">text</code>,
                    <code class="font-mono">muted</code> {{ $t('und') }}
                    <code class="font-mono">link</code> {{ $t('auf') }}
                    <code class="font-mono">background</code>,
                    {{ $t('dazu') }}
                    <code class="font-mono">X-text</code>,
                    <code class="font-mono">X-muted</code>,
                    <code class="font-mono">X-link</code> {{ $t('auf') }}
                    <code class="font-mono">X</code>.
                </p>
                <ul class="grid gap-2">
                    <li
                        v-for="row in pairRows"
                        :key="`${row.foreground}-${row.background}`"
                        class="flex flex-wrap items-center gap-3"
                    >
                        <span
                            class="inline-flex min-w-28 items-center justify-center rounded-sm px-3 py-1.5 text-[13px] font-semibold"
                            :style="{
                                color: form.colors[row.foreground],
                                backgroundColor: form.colors[row.background],
                            }"
                            aria-hidden="true"
                            >{{ $t('Beispieltext') }}</span
                        >
                        <span class="font-mono text-[12.5px]">{{
                            $t(':foreground auf :background', {
                                foreground: row.foreground,
                                background: row.background,
                            })
                        }}</span>
                        <span
                            class="text-[12.5px] font-semibold"
                            :class="row.ok ? 'text-success' : 'text-danger'"
                        >
                            {{
                                row.ratio === null
                                    ? '—'
                                    : formatRatio(row.ratio)
                            }}
                            {{ row.ok ? $t('erfüllt') : $t('zu schwach') }}
                        </span>
                    </li>
                </ul>
            </Card>

            <Card :title="$t('Schriften')">
                <p v-if="fonts.length === 0" class="text-sm text-basalt-light">
                    {{ $t('Das Theme nennt keine Schriften.') }}
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Field
                        v-for="token in fonts"
                        :key="token.name"
                        v-model="form.fonts[token.name]"
                        :label="token.name"
                        :disabled="!can_edit"
                        :saved="autosave.saved(`fonts.${token.name}`)"
                        :error="
                            (form.errors as Record<string, string | undefined>)[
                                `fonts.${token.name}`
                            ]
                        "
                        :help="`${$t('Theme:')} ${token.theme}${token.weight ? ` · ${$t('Gewicht :weight', { weight: token.weight })}` : ''} — ${$t('die Schrift muss im Theme liegen oder auf dem Gerät vorhanden sein; sonst gilt die Ersatzschrift.')}`"
                    />
                </div>
                <p
                    v-for="token in fonts.filter(
                        (t) => form.fonts[t.name] !== t.theme,
                    )"
                    :key="`reset-${token.name}`"
                    class="mt-2 text-[11.5px]"
                >
                    <button
                        type="button"
                        class="underline"
                        @click="resetFont(token)"
                    >
                        {{
                            $t(':name zurück auf «:theme»', {
                                name: token.name,
                                theme: token.theme,
                            })
                        }}
                    </button>
                </p>
            </Card>

            <Card :title="$t('Logo')">
                <div class="flex flex-wrap items-center gap-4">
                    <div
                        class="flex h-16 w-40 items-center justify-center rounded-sm border border-ash-light bg-white p-2"
                    >
                        <img
                            v-if="logoUrl"
                            :src="logoUrl"
                            :alt="$t('Aktuelles Logo')"
                            class="max-h-full max-w-full object-contain"
                        />
                        <span v-else class="text-[12px] text-basalt-light">{{
                            $t('kein Logo')
                        }}</span>
                    </div>
                    <p class="text-[12.5px] text-basalt-light">
                        {{ $t('Im Theme über') }}
                        <code class="font-mono" v-text="logoTag"></code>.
                        {{
                            $t('Ohne Wahl gilt :logo.', {
                                logo: logo.theme
                                    ? $t('das Logo aus dem Theme')
                                    : $t('kein Logo (das Theme nennt keines)'),
                            })
                        }}
                    </p>
                </div>
                <fieldset v-if="can_edit" class="mt-3">
                    <legend :class="labelClasses">
                        {{ $t('Bild aus der Mediathek') }}
                    </legend>
                    <div
                        class="mt-1 grid grid-cols-[repeat(auto-fill,minmax(5.5rem,1fr))] gap-2"
                    >
                        <label
                            class="flex h-20 cursor-pointer items-center justify-center rounded-sm border text-[11.5px] has-[:checked]:border-lava has-[:checked]:ring-2 has-[:checked]:ring-lava/40 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-basalt"
                        >
                            <input
                                v-model="form.logo_media_id"
                                type="radio"
                                :value="null"
                                class="sr-only"
                            />
                            {{ $t('aus dem Theme') }}
                        </label>
                        <label
                            v-for="image in images"
                            :key="image.id"
                            class="flex h-20 cursor-pointer items-center justify-center rounded-sm border bg-white p-1 has-[:checked]:border-lava has-[:checked]:ring-2 has-[:checked]:ring-lava/40 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-basalt"
                            :title="image.name"
                        >
                            <input
                                v-model="form.logo_media_id"
                                type="radio"
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
                        v-if="form.errors.logo_media_id"
                        class="text-danger mt-1 text-[11.5px]"
                    >
                        {{ form.errors.logo_media_id }}
                    </p>
                </fieldset>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-4">
            <Card :title="$t('Lava (Station B)')">
                <p
                    v-if="file"
                    class="mb-2 font-mono text-[11.5px] text-basalt-light"
                >
                    {{ file }}
                </p>
                <CodeBlock :code="lava || $t('# kein design im Theme')" />
            </Card>
            <Card :title="$t('Stylesheets')">
                <p v-if="styles.length === 0" class="text-sm text-basalt-light">
                    {{ $t('Das Theme bindet kein Stylesheet ein') }} (<code
                        class="font-mono"
                        >css: assets/theme.css</code
                    >).
                </p>
                <ul class="grid gap-1 text-[12.5px]">
                    <li v-for="style in styles" :key="style.path">
                        <a
                            v-if="style.url"
                            :href="style.url"
                            class="font-mono underline"
                            target="_blank"
                            rel="noopener"
                            >{{ style.path }}</a
                        >
                        <span v-else class="text-danger font-mono">{{
                            $t(':path fehlt', { path: style.path })
                        }}</span>
                    </li>
                </ul>
                <p class="mt-2 text-[11.5px] text-basalt-light">
                    {{
                        $t('Abstände :space px · Radius :radius px', {
                            space: space.join(' · ') || '—',
                            radius: radius ?? '—',
                        })
                    }}
                </p>
            </Card>
            <Card :title="$t('Für Tailwind 4')">
                <p class="mb-2 text-[12px] text-basalt-light">
                    {{ $t('Dieselben Werte als') }}
                    <code class="font-mono">@theme</code> —
                    {{ $t('auch per') }}
                    <code class="font-mono"
                        >php artisan basalto:tokens --tailwind</code
                    >.
                </p>
                <CodeBlock :code="tailwind" />
            </Card>
        </div>
    </div>
</template>
