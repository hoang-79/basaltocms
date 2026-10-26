<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { t } from '@core/i18n';
import Button from '@core/components/Button.vue';
import { controlClasses, labelClasses } from '@core/components/control';
import Modal from '@core/components/Modal.vue';
import PageHeader from '@core/components/PageHeader.vue';
import StateTag from '@core/components/StateTag.vue';
import type {
    GroupRuleRow,
    ProfileFieldRow,
    RankRow,
    RecognitionProps,
    TrophyRow,
} from '../types';

/**
 * Extranet › Community › Profile und Auszeichnungen (CR-006 FO-18, FO-20): Ränge nach Beiträgen, Trophäen nach Regeln
 * oder von Hand, automatische Gruppen mit Vorschau, eigene Profilfelder. Der tägliche Lauf vergibt und ordnet zu;
 * «Jetzt anwenden» startet ihn sofort.
 */
const props = defineProps<RecognitionProps>();

const ruleLabel = (key: string | null): string =>
    key === null
        ? t('von Hand')
        : (props.rules.find((r) => r.value === key)?.label ?? key);
const needsThreshold = (key: string | null): boolean =>
    key !== null &&
    (props.rules.find((r) => r.value === key)?.threshold ?? true);
const groupName = (id: number | null): string =>
    id === null
        ? t('alle')
        : (props.all_groups.find((g) => g.id === id)?.name ?? '?');

// Vorschau «trifft heute n Mitglieder»
const preview = ref<number | null>(null);
async function loadPreview(
    rule: string | null,
    threshold: number | null,
): Promise<void> {
    preview.value = null;
    if (!rule) {
        return;
    }
    const response = await fetch(
        `${props.urls.preview}?rule=${encodeURIComponent(rule)}&threshold=${threshold ?? 0}`,
        { headers: { Accept: 'application/json' } },
    );
    preview.value = response.ok
        ? ((await response.json()) as { count: number }).count
        : null;
}

// Ränge
const rankForm = useForm({
    title: '',
    min_posts: 0,
    color: 'grey',
    icon: '' as string | null,
    visitor_group_id: null as number | null,
});
const rankEditing = ref<RankRow | null>(null);
const rankOpen = ref(false);
function editRank(rank: RankRow | null): void {
    rankEditing.value = rank;
    rankForm.defaults(
        rank
            ? {
                  title: rank.title,
                  min_posts: rank.min_posts,
                  color: rank.color,
                  icon: rank.icon ?? '',
                  visitor_group_id: rank.visitor_group_id,
              }
            : {
                  title: '',
                  min_posts: 0,
                  color: 'grey',
                  icon: '',
                  visitor_group_id: null,
              },
    );
    rankForm.reset();
    rankForm.clearErrors();
    rankOpen.value = true;
}
function saveRank(): void {
    const done = {
        preserveScroll: true,
        onSuccess: () => (rankOpen.value = false),
    };
    if (rankEditing.value) {
        rankForm.put(rankEditing.value.update_url, done);
    } else {
        rankForm.post(props.urls.ranks, done);
    }
}

// Trophäen
const trophyForm = useForm({
    title: '',
    description: '',
    icon: '🏆',
    rule: null as string | null,
    threshold: null as number | null,
    is_active: true,
});
const trophyEditing = ref<TrophyRow | null>(null);
const trophyOpen = ref(false);
function editTrophy(trophy: TrophyRow | null): void {
    trophyEditing.value = trophy;
    trophyForm.defaults(
        trophy
            ? {
                  title: trophy.title,
                  description: trophy.description ?? '',
                  icon: trophy.icon,
                  rule: trophy.rule,
                  threshold: trophy.threshold,
                  is_active: trophy.is_active,
              }
            : {
                  title: '',
                  description: '',
                  icon: '🏆',
                  rule: null,
                  threshold: null,
                  is_active: true,
              },
    );
    trophyForm.reset();
    trophyForm.clearErrors();
    trophyOpen.value = true;
    void loadPreview(trophyForm.rule, trophyForm.threshold);
}
function saveTrophy(): void {
    const done = {
        preserveScroll: true,
        onSuccess: () => (trophyOpen.value = false),
    };
    if (trophyEditing.value) {
        trophyForm.put(trophyEditing.value.update_url, done);
    } else {
        trophyForm.post(props.urls.trophies, done);
    }
}
watch(
    () => [trophyForm.rule, trophyForm.threshold] as const,
    ([rule, threshold]) => {
        if (trophyOpen.value) {
            void loadPreview(rule, threshold);
        }
    },
);

// Von Hand vergeben
const awarding = ref<TrophyRow | null>(null);
const search = ref('');
const found = ref<{ id: number; email: string; name: string }[]>([]);
async function find(): Promise<void> {
    const q = search.value.trim();
    if (q.length < 2) {
        found.value = [];
        return;
    }
    const response = await fetch(
        `${props.urls.customers}?q=${encodeURIComponent(q)}`,
        {
            headers: { Accept: 'application/json' },
        },
    );
    found.value = response.ok
        ? ((await response.json()) as {
              id: number;
              email: string;
              name: string;
          }[])
        : [];
}
function award(customer: number): void {
    if (awarding.value) {
        router.post(
            awarding.value.award_url,
            { customer_id: customer },
            { preserveScroll: true, onSuccess: () => (awarding.value = null) },
        );
    }
}

// Gruppenregeln
const ruleForm = useForm({
    visitor_group_id: null as number | null,
    rule: '',
    threshold: 0,
    is_active: true,
});
const ruleEditing = ref<GroupRuleRow | null>(null);
const ruleOpen = ref(false);
function editRule(rule: GroupRuleRow | null): void {
    ruleEditing.value = rule;
    ruleForm.defaults(
        rule
            ? {
                  visitor_group_id: rule.visitor_group_id,
                  rule: rule.rule,
                  threshold: rule.threshold,
                  is_active: rule.is_active,
              }
            : {
                  visitor_group_id: props.groups[0]?.id ?? null,
                  rule: props.rules[0]?.value ?? '',
                  threshold: 0,
                  is_active: true,
              },
    );
    ruleForm.reset();
    ruleForm.clearErrors();
    ruleOpen.value = true;
    void loadPreview(ruleForm.rule, ruleForm.threshold);
}
function saveRule(): void {
    const done = {
        preserveScroll: true,
        onSuccess: () => (ruleOpen.value = false),
    };
    if (ruleEditing.value) {
        ruleForm.put(ruleEditing.value.update_url, done);
    } else {
        ruleForm.post(props.urls.group_rules, done);
    }
}
watch(
    () => [ruleForm.rule, ruleForm.threshold] as const,
    ([rule, threshold]) => {
        if (ruleOpen.value) {
            void loadPreview(rule, threshold);
        }
    },
);

function remove(url: string, question: string): void {
    if (window.confirm(question)) {
        router.delete(url, { preserveScroll: true });
    }
}
function run(): void {
    router.post(props.urls.run, {}, { preserveScroll: true });
}

// Profilfelder
const fieldsForm = useForm({
    fields: props.fields.map((f) => ({
        ...f,
        options: f.options ?? [],
    })) as ProfileFieldRow[],
});
function addField(): void {
    fieldsForm.fields.push({
        key: '',
        label: '',
        type: 'text',
        visibility: 'all',
        required: false,
        options: [],
    });
}
/** Die Möglichkeiten einer Auswahl gehen als Text (eine je Zeile) an den Server. */
function saveFields(): void {
    fieldsForm
        .transform((data) => ({
            fields: data.fields.map((f) => ({
                ...f,
                options: (f.options ?? []).join('\n'),
            })),
        }))
        .put(props.urls.fields, { preserveScroll: true });
}
const error = (form: { errors: object }, key: string): string =>
    (form.errors as Record<string, string | undefined>)[key] ?? '';
const rankTone = (
    color: string,
): 'grey' | 'lava' | 'success' | 'warning' | 'danger' =>
    color === 'lava'
        ? 'lava'
        : color === 'green'
          ? 'success'
          : color === 'yellow'
            ? 'warning'
            : color === 'red'
              ? 'danger'
              : 'grey';
</script>

<template>
    <Head :title="$t('Profile und Auszeichnungen')" />
    <PageHeader
        :title="$t('Profile und Auszeichnungen')"
        :crumb="$t('Community')"
        :lead="
            $t(
                'Ränge nach Beiträgen, Trophäen nach Regeln oder von Hand, Gruppen, die sich selbst füllen, und eigene Felder im Profil.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.overview" variant="secondary">{{
                $t('Übersicht')
            }}</Button>
            <Button @click="run">{{ $t('Jetzt anwenden') }}</Button>
        </template>
    </PageHeader>

    <div class="flex flex-col gap-5">
        <!-- Ränge -->
        <section class="rounded-lg border border-ash-light bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-basalt">
                    {{ $t('Ränge') }}
                </h2>
                <Button size="sm" variant="secondary" @click="editRank(null)">{{
                    $t('Neuer Rang')
                }}</Button>
            </div>
            <p class="mt-1 text-[12px] text-basalt-light">
                {{
                    $t(
                        'Jedes Mitglied trägt den höchsten Rang, dessen Schwelle es erreicht — wahlweise nur in einer Gruppe.',
                    )
                }}
            </p>
            <ul
                v-if="ranks.length"
                class="mt-3 flex flex-col divide-y divide-ash-light"
            >
                <li
                    v-for="rank in ranks"
                    :key="rank.id"
                    class="flex flex-wrap items-center gap-3 py-2 text-[13px]"
                >
                    <StateTag :tone="rankTone(rank.color)"
                        ><span
                            v-if="rank.icon"
                            aria-hidden="true"
                            class="mr-1"
                            >{{ rank.icon }}</span
                        >{{ rank.title }}</StateTag
                    >
                    <span class="text-basalt-light">{{
                        $t('ab :count Beiträgen · Gruppe: :group', {
                            count: rank.min_posts,
                            group: groupName(rank.visitor_group_id),
                        })
                    }}</span>
                    <span class="flex-1" />
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="editRank(rank)"
                        >{{ $t('Bearbeiten') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="danger"
                        @click="
                            remove(rank.destroy_url, $t('Diesen Rang löschen?'))
                        "
                        >{{ $t('Löschen') }}</Button
                    >
                </li>
            </ul>
        </section>

        <!-- Trophäen -->
        <section class="rounded-lg border border-ash-light bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-basalt">
                    {{ $t('Trophäen') }}
                </h2>
                <Button
                    size="sm"
                    variant="secondary"
                    @click="editTrophy(null)"
                    >{{ $t('Neue Trophäe') }}</Button
                >
            </div>
            <ul v-if="trophies.length" class="mt-3 grid gap-3 md:grid-cols-2">
                <li
                    v-for="trophy in trophies"
                    :key="trophy.id"
                    class="flex flex-col gap-2 rounded-md border border-ash-light p-3"
                >
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="text-xl" aria-hidden="true">{{
                            trophy.icon
                        }}</span>
                        <b class="font-semibold text-basalt">{{
                            trophy.title
                        }}</b>
                        <StateTag v-if="!trophy.is_active" tone="grey">{{
                            $t('aus')
                        }}</StateTag>
                    </p>
                    <p
                        v-if="trophy.description"
                        class="text-[12.5px] text-basalt"
                    >
                        {{ trophy.description }}
                    </p>
                    <p class="text-[12px] text-basalt-light">
                        {{ ruleLabel(trophy.rule)
                        }}<template v-if="needsThreshold(trophy.rule)">
                            ≥ {{ trophy.threshold ?? 0 }}</template
                        >
                        ·
                        {{
                            $tc(
                                ':count Mal vergeben|:count Mal vergeben',
                                trophy.awarded,
                                { count: trophy.awarded },
                            )
                        }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="secondary"
                            @click="editTrophy(trophy)"
                            >{{ $t('Bearbeiten') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="secondary"
                            @click="
                                ((awarding = trophy),
                                (search = ''),
                                (found = []))
                            "
                            >{{ $t('Von Hand vergeben') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="danger"
                            @click="
                                remove(
                                    trophy.destroy_url,
                                    $t(
                                        'Diese Trophäe löschen? Vergebene verschwinden mit.',
                                    ),
                                )
                            "
                            >{{ $t('Löschen') }}</Button
                        >
                    </div>
                </li>
            </ul>
        </section>

        <!-- Automatische Gruppen -->
        <section class="rounded-lg border border-ash-light bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-basalt">
                    {{ $t('Automatische Gruppenzuordnung') }}
                </h2>
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="!groups.length"
                    @click="editRule(null)"
                    >{{ $t('Neue Regel') }}</Button
                >
            </div>
            <p class="mt-1 text-[12px] text-basalt-light">
                {{
                    $t(
                        'Wer die Regel erfüllt, kommt in die Gruppe; wer sie nicht mehr erfüllt, geht wieder. Von Hand Zugeordnete bleiben.',
                    )
                }}
            </p>
            <p v-if="!groups.length" class="mt-2 text-[12.5px] text-basalt">
                {{
                    $t(
                        'Legen Sie zuerst unter «Gruppen und Rechte» eine eigene Gruppe an.',
                    )
                }}
            </p>
            <ul
                v-if="group_rules.length"
                class="mt-3 flex flex-col divide-y divide-ash-light"
            >
                <li
                    v-for="rule in group_rules"
                    :key="rule.id"
                    class="flex flex-wrap items-center gap-3 py-2 text-[13px]"
                >
                    <b class="font-semibold text-basalt">{{
                        groupName(rule.visitor_group_id)
                    }}</b>
                    <span
                        >← {{ ruleLabel(rule.rule)
                        }}<template v-if="needsThreshold(rule.rule)">
                            ≥ {{ rule.threshold }}</template
                        ></span
                    >
                    <StateTag v-if="!rule.is_active" tone="grey">{{
                        $t('aus')
                    }}</StateTag>
                    <span class="text-basalt-light"
                        >{{
                            $tc(
                                ':count Mitglied|:count Mitglieder',
                                rule.members,
                                { count: rule.members },
                            )
                        }}<template v-if="rule.last_run">
                            · {{ rule.last_run }}</template
                        ></span
                    >
                    <span class="flex-1" />
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="editRule(rule)"
                        >{{ $t('Bearbeiten') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="danger"
                        @click="
                            remove(
                                rule.destroy_url,
                                $t(
                                    'Diese Regel löschen? Die von ihr gesetzten Mitgliedschaften gehen mit.',
                                ),
                            )
                        "
                        >{{ $t('Löschen') }}</Button
                    >
                </li>
            </ul>
        </section>

        <!-- Profilfelder -->
        <section class="rounded-lg border border-ash-light bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-basalt">
                    {{ $t('Eigene Profilfelder') }}
                </h2>
                <Button size="sm" variant="secondary" @click="addField">{{
                    $t('Feld hinzufügen')
                }}</Button>
            </div>
            <form class="mt-3 flex flex-col gap-3" @submit.prevent="saveFields">
                <div
                    v-for="(field, i) in fieldsForm.fields"
                    :key="i"
                    class="grid gap-2 rounded-md border border-ash-light p-3 md:grid-cols-5"
                >
                    <label class="block">
                        <span :class="labelClasses">{{ $t('Schlüssel') }}</span>
                        <input
                            v-model="field.key"
                            type="text"
                            maxlength="30"
                            :class="
                                controlClasses({
                                    error: !!error(
                                        fieldsForm,
                                        `fields.${i}.key`,
                                    ),
                                    mono: true,
                                })
                            "
                        />
                    </label>
                    <label class="block">
                        <span :class="labelClasses">{{
                            $t('Beschriftung')
                        }}</span>
                        <input
                            v-model="field.label"
                            type="text"
                            maxlength="60"
                            :class="
                                controlClasses({
                                    error: !!error(
                                        fieldsForm,
                                        `fields.${i}.label`,
                                    ),
                                })
                            "
                        />
                    </label>
                    <label class="block">
                        <span :class="labelClasses">{{ $t('Art') }}</span>
                        <select
                            v-model="field.type"
                            :class="controlClasses({})"
                        >
                            <option value="text">{{ $t('Text') }}</option>
                            <option value="textarea">
                                {{ $t('Mehrzeilig') }}
                            </option>
                            <option value="url">
                                {{ $t('Adresse (URL)') }}
                            </option>
                            <option value="select">
                                {{ $t('Auswahl') }}
                            </option>
                        </select>
                    </label>
                    <label class="block">
                        <span :class="labelClasses">{{
                            $t('Sichtbar für')
                        }}</span>
                        <select
                            v-model="field.visibility"
                            :class="controlClasses({})"
                        >
                            <option value="all">{{ $t('Alle') }}</option>
                            <option value="members">
                                {{ $t('Angemeldete') }}
                            </option>
                            <option value="team">{{ $t('Nur Team') }}</option>
                        </select>
                    </label>
                    <label
                        v-if="field.type === 'select'"
                        class="block md:col-span-5"
                    >
                        <span :class="labelClasses">{{
                            $t('Möglichkeiten (eine je Zeile)')
                        }}</span>
                        <textarea
                            :value="(field.options ?? []).join('\n')"
                            rows="3"
                            :class="
                                controlClasses({
                                    error: !!error(
                                        fieldsForm,
                                        `fields.${i}.options`,
                                    ),
                                })
                            "
                            @input="
                                field.options = (
                                    $event.target as HTMLTextAreaElement
                                ).value.split('\n')
                            "
                        ></textarea>
                        <span
                            v-if="error(fieldsForm, `fields.${i}.options`)"
                            class="text-danger mt-1 block text-[11.5px] font-semibold"
                            >{{
                                error(fieldsForm, `fields.${i}.options`)
                            }}</span
                        >
                    </label>
                    <div class="flex items-end justify-between gap-2">
                        <label
                            class="inline-flex items-center gap-2 text-[13px]"
                        >
                            <input v-model="field.required" type="checkbox" />
                            {{ $t('Pflicht') }}
                        </label>
                        <Button
                            size="sm"
                            variant="danger"
                            @click="fieldsForm.fields.splice(i, 1)"
                            >{{ $t('Entfernen') }}</Button
                        >
                    </div>
                    <p
                        v-if="error(fieldsForm, `fields.${i}.key`)"
                        class="text-danger text-[11.5px] md:col-span-5"
                    >
                        {{ error(fieldsForm, `fields.${i}.key`) }}
                    </p>
                </div>
                <p
                    v-if="!fieldsForm.fields.length"
                    class="text-[12.5px] text-basalt-light"
                >
                    {{
                        $t(
                            'Noch keine eigenen Felder — etwa «Website», «Ort» oder «Basalto seit».',
                        )
                    }}
                </p>
                <div>
                    <Button type="submit" :disabled="fieldsForm.processing">{{
                        $t('Felder speichern')
                    }}</Button>
                </div>
            </form>
        </section>
    </div>

    <!-- Rang -->
    <Modal
        :open="rankOpen"
        :title="rankEditing ? $t('Rang bearbeiten') : $t('Neuer Rang')"
        :wide="false"
        @close="rankOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="saveRank">
            <label class="block">
                <span :class="labelClasses">{{ $t('Titel') }}</span>
                <input
                    v-model="rankForm.title"
                    type="text"
                    maxlength="60"
                    :class="
                        controlClasses({ error: !!error(rankForm, 'title') })
                    "
                />
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Ab Beiträgen') }}</span>
                <input
                    v-model.number="rankForm.min_posts"
                    type="number"
                    min="0"
                    :class="controlClasses({})"
                />
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Nur für Gruppe') }}</span>
                <select
                    v-model="rankForm.visitor_group_id"
                    :class="controlClasses({})"
                >
                    <option :value="null">{{ $t('alle') }}</option>
                    <option v-for="g in all_groups" :key="g.id" :value="g.id">
                        {{ g.name }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span :class="labelClasses">{{
                    $t('Zeichen (freiwillig)')
                }}</span>
                <input
                    v-model="rankForm.icon"
                    type="text"
                    maxlength="16"
                    list="rang-zeichen"
                    :class="controlClasses({})"
                    :placeholder="$t('z. B. ⭐ oder 🛡️')"
                />
                <datalist id="rang-zeichen">
                    <option
                        v-for="z in [
                            '⭐',
                            '🌟',
                            '🛡️',
                            '👑',
                            '💎',
                            '🔥',
                            '🚀',
                            '🎓',
                            '🏅',
                            '❤️',
                        ]"
                        :key="z"
                        :value="z"
                    />
                </datalist>
                <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                    $t(
                        'Steht statt des farbigen Punkts vor dem Titel des Rangs.',
                    )
                }}</span>
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Farbe') }}</span>
                <select v-model="rankForm.color" :class="controlClasses({})">
                    <option v-for="c in colors" :key="c" :value="c">
                        {{ c }}
                    </option>
                </select>
            </label>
            <div class="flex gap-2">
                <Button type="submit" :disabled="rankForm.processing">{{
                    $t('Speichern')
                }}</Button>
                <Button variant="secondary" @click="rankOpen = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>

    <!-- Trophäe -->
    <Modal
        :open="trophyOpen"
        :title="trophyEditing ? $t('Trophäe bearbeiten') : $t('Neue Trophäe')"
        :wide="false"
        @close="trophyOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="saveTrophy">
            <div class="grid grid-cols-[5rem_1fr] gap-3">
                <label class="block">
                    <span :class="labelClasses">{{ $t('Symbol') }}</span>
                    <input
                        v-model="trophyForm.icon"
                        type="text"
                        maxlength="16"
                        :class="controlClasses({})"
                    />
                </label>
                <label class="block">
                    <span :class="labelClasses">{{ $t('Titel') }}</span>
                    <input
                        v-model="trophyForm.title"
                        type="text"
                        maxlength="80"
                        :class="
                            controlClasses({
                                error: !!error(trophyForm, 'title'),
                            })
                        "
                    />
                </label>
            </div>
            <label class="block">
                <span :class="labelClasses">{{ $t('Beschreibung') }}</span>
                <input
                    v-model="trophyForm.description"
                    type="text"
                    maxlength="255"
                    :class="controlClasses({})"
                />
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Regel') }}</span>
                <select v-model="trophyForm.rule" :class="controlClasses({})">
                    <option :value="null">{{ $t('von Hand') }}</option>
                    <option v-for="r in rules" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
            </label>
            <label v-if="needsThreshold(trophyForm.rule)" class="block">
                <span :class="labelClasses">{{ $t('Schwelle') }}</span>
                <input
                    v-model.number="trophyForm.threshold"
                    type="number"
                    min="0"
                    :class="controlClasses({})"
                />
            </label>
            <p
                v-if="trophyForm.rule && preview !== null"
                class="text-[12.5px] text-basalt"
            >
                {{
                    $tc(
                        'Trifft heute :count Mitglied.|Trifft heute :count Mitglieder.',
                        preview,
                        { count: preview },
                    )
                }}
            </p>
            <label class="inline-flex items-center gap-2 text-[13px]">
                <input v-model="trophyForm.is_active" type="checkbox" />
                {{ $t('Aktiv') }}
            </label>
            <div class="flex gap-2">
                <Button type="submit" :disabled="trophyForm.processing">{{
                    $t('Speichern')
                }}</Button>
                <Button variant="secondary" @click="trophyOpen = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>

    <!-- Von Hand vergeben -->
    <Modal
        :open="awarding !== null"
        :title="$t('Von Hand vergeben')"
        :subtitle="awarding?.title ?? ''"
        :wide="false"
        @close="awarding = null"
    >
        <form class="flex flex-col gap-3" @submit.prevent="find">
            <label class="block">
                <span :class="labelClasses">{{ $t('E-Mail oder Name') }}</span>
                <input
                    v-model="search"
                    type="search"
                    :class="controlClasses({})"
                    @input="find"
                />
            </label>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="c in found"
                    :key="c.id"
                    class="flex items-center justify-between gap-2 text-[13px]"
                >
                    <span
                        >{{ c.name || c.email }}
                        <span class="text-basalt-light"
                            >· {{ c.email }}</span
                        ></span
                    >
                    <Button size="sm" @click="award(c.id)">{{
                        $t('Vergeben')
                    }}</Button>
                </li>
            </ul>
        </form>
    </Modal>

    <!-- Gruppenregel -->
    <Modal
        :open="ruleOpen"
        :title="ruleEditing ? $t('Regel bearbeiten') : $t('Neue Regel')"
        :wide="false"
        @close="ruleOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="saveRule">
            <label class="block">
                <span :class="labelClasses">{{ $t('Gruppe') }}</span>
                <select
                    v-model="ruleForm.visitor_group_id"
                    :class="
                        controlClasses({
                            error: !!error(ruleForm, 'visitor_group_id'),
                        })
                    "
                >
                    <option v-for="g in groups" :key="g.id" :value="g.id">
                        {{ g.name }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span :class="labelClasses">{{ $t('Regel') }}</span>
                <select v-model="ruleForm.rule" :class="controlClasses({})">
                    <option v-for="r in rules" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
                <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                    rules.find((r) => r.value === ruleForm.rule)?.help
                }}</span>
            </label>
            <label v-if="needsThreshold(ruleForm.rule)" class="block">
                <span :class="labelClasses">{{ $t('Schwelle') }}</span>
                <input
                    v-model.number="ruleForm.threshold"
                    type="number"
                    min="0"
                    :class="controlClasses({})"
                />
            </label>
            <p v-if="preview !== null" class="text-[12.5px] text-basalt">
                {{
                    $tc(
                        'Trifft heute :count Mitglied.|Trifft heute :count Mitglieder.',
                        preview,
                        { count: preview },
                    )
                }}
            </p>
            <label class="inline-flex items-center gap-2 text-[13px]">
                <input v-model="ruleForm.is_active" type="checkbox" />
                {{ $t('Aktiv') }}
            </label>
            <div class="flex gap-2">
                <Button type="submit" :disabled="ruleForm.processing">{{
                    $t('Speichern')
                }}</Button>
                <Button variant="secondary" @click="ruleOpen = false">{{
                    $t('Abbrechen')
                }}</Button>
            </div>
        </form>
    </Modal>
</template>
