<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { controlClasses, labelClasses } from '@core/components/control';
import AutosaveState from '@core/components/AutosaveState.vue';
import { useAutosave } from '@core/components/autosave';
import Button from '@core/components/Button.vue';
import Notice from '@core/components/Notice.vue';
import PageHeader from '@core/components/PageHeader.vue';
import type { ForumOverviewProps } from '../types';

/**
 * Extranet › Community (CR-006 § 6): Zahlen, die Seite «Community» im Frontend (mit einem Klick anlegen, wenn sie
 * fehlt) und das eigene Community-Konto — verknüpft schreibt man im Forum mit dem Abzeichen «Team» (FO-K14). Seit
 * TB 39 auch offene Moderation und die Einstellungen von Flood-Control, Meldungen, Verwarnungen und Zensur.
 */
const props = defineProps<ForumOverviewProps>();

function link(): void {
    router.post(props.urls.link, {}, { preserveScroll: true });
}
function unlink(): void {
    router.delete(props.urls.unlink, { preserveScroll: true });
}
const settingsForm = useForm({ ...props.settings });
/**
 * CR-013: Die Einstellungen speichern selbst; ein leeres oder ungültiges Feld blockiert die übrigen nicht. Die Fassung
 * der Nutzungsbedingungen erst beim Verlassen — eine neue Fassung fragt alle Mitglieder noch einmal.
 */
const settingsSave = useAutosave(settingsForm, {
    url: props.urls.settings,
    method: 'post',
    onLeave: ['terms_version'],
});
const settingError = (key: string): string =>
    (settingsForm.errors as Record<string, string | undefined>)[key] ?? '';
function createPage(): void {
    router.post(props.urls.create_page, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Community')" />
    <PageHeader
        :title="$t('Community')"
        :lead="
            $t(
                'Das Forum der Site: Struktur, Gruppen und Rechte, Ihr Konto im Team.',
            )
        "
    >
        <template #actions>
            <Button :href="urls.nodes" variant="secondary">{{
                $t('Foren')
            }}</Button>
            <Button :href="urls.groups" variant="secondary">{{
                $t('Gruppen und Rechte')
            }}</Button>
            <Button :href="urls.moderation" variant="secondary">{{
                $t('Moderation')
            }}</Button>
            <Button :href="urls.members" variant="secondary">{{
                $t('Mitglieder')
            }}</Button>
            <Button :href="urls.notices" variant="secondary">{{
                $t('Hinweise')
            }}</Button>
            <Button :href="urls.recognition" variant="secondary">{{
                $t('Profile und Auszeichnungen')
            }}</Button>
        </template>
    </PageHeader>

    <div class="flex flex-col gap-4">
        <dl class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div
                v-for="item in [
                    { label: $t('Foren'), value: counts.nodes },
                    { label: $t('Themen'), value: counts.threads },
                    { label: $t('Beiträge'), value: counts.posts },
                    {
                        label: $t('Mitglieder mit Profil'),
                        value: counts.members,
                    },
                    { label: $t('Heute aktiv'), value: counts.today },
                ]"
                :key="item.label"
                class="rounded-lg border border-ash-light bg-white px-4 py-3"
            >
                <dt
                    class="text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                >
                    {{ item.label }}
                </dt>
                <dd class="mt-1 font-mono text-xl font-semibold text-basalt">
                    {{ item.value }}
                </dd>
            </div>
        </dl>

        <Notice
            v-if="moderation.open || moderation.pending"
            tone="warning"
            :title="$t('Moderation')"
        >
            <p>
                {{
                    $t(
                        ':open gemeldete Beiträge, :pending warten auf die Freischaltung.',
                        { open: moderation.open, pending: moderation.pending },
                    )
                }}
                <Link
                    :href="urls.moderation"
                    class="font-semibold text-lava-dark hover:underline"
                    >{{ $t('Zur Moderation') }}</Link
                >
            </p>
        </Notice>

        <section class="rounded-lg border border-ash-light bg-white p-4">
            <h2 class="text-sm font-semibold text-basalt">
                {{ $t('Seite im Frontend') }}
            </h2>
            <p v-if="page" class="mt-1 text-[13px] text-basalt-light">
                {{
                    $t('Das Forum steht unter der Seite «:title».', {
                        title: page.title,
                    })
                }}
                <a
                    v-if="page.url"
                    :href="page.url"
                    target="_blank"
                    rel="noopener"
                    class="font-semibold text-lava-dark hover:underline"
                    >{{ page.url }}</a
                >
            </p>
            <template v-else>
                <p class="mt-1 text-[13px] text-basalt-light">
                    {{
                        $t(
                            'Noch keine Seite trägt das Forum. Richten Sie es unter /community ein — gibt es die Seite schon, hängt es sich an sie. Oder wählen Sie im Seitenbaum bei einer Seite unter «Experten» «Modul einhängen: Forum».',
                        )
                    }}
                </p>
                <div class="mt-3">
                    <Button @click="createPage">{{
                        $t('Forum unter /community einrichten')
                    }}</Button>
                </div>
            </template>
        </section>

        <section class="rounded-lg border border-ash-light bg-white p-4">
            <h2 class="text-sm font-semibold text-basalt">
                {{ $t('Ihr Konto im Forum') }}
            </h2>
            <template v-if="team.linked">
                <p class="mt-1 text-[13px] text-basalt-light">
                    {{
                        $t(
                            'Sie schreiben als «:name» mit dem Abzeichen «Team».',
                            { name: team.linked.name },
                        )
                    }}
                    <a
                        v-if="team.linked.profile_url"
                        :href="team.linked.profile_url"
                        target="_blank"
                        rel="noopener"
                        class="font-semibold text-lava-dark hover:underline"
                        >{{ $t('Profil im Forum') }}</a
                    >
                </p>
                <div class="mt-3">
                    <Button variant="secondary" @click="unlink">{{
                        $t('Verknüpfung lösen')
                    }}</Button>
                </div>
            </template>
            <template v-else>
                <p class="mt-1 text-[13px] text-basalt-light">
                    {{
                        $t(
                            'Verknüpfen Sie das Kundenkonto mit Ihrer E-Mail :email — im Forum erscheinen Sie dann als Team. Gibt es das Konto noch nicht, entsteht es dabei.',
                            { email: team.email },
                        )
                    }}
                </p>
                <div class="mt-3">
                    <Button @click="link">{{
                        $t('Mein Konto verknüpfen')
                    }}</Button>
                </div>
            </template>
            <Notice
                v-if="team.members.length"
                tone="grey"
                :title="$t('Team im Forum')"
                class="mt-3"
            >
                <ul class="flex flex-wrap gap-x-4 gap-y-1">
                    <li v-for="m in team.members" :key="m.name">
                        <b class="font-semibold">{{ m.name }}</b>
                        <span class="text-basalt-light"> · {{ m.user }}</span>
                    </li>
                </ul>
            </Notice>
        </section>

        <section class="rounded-lg border border-ash-light bg-white p-4">
            <h2 class="text-sm font-semibold text-basalt">
                {{ $t('Einstellungen') }}
            </h2>
            <form
                class="mt-3 grid gap-3 md:grid-cols-2"
                @submit.prevent="settingsSave.flush()"
            >
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Bearbeiten erlaubt (Minuten, 0 = immer)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.edit_minutes"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('edit_minutes'),
                            })
                        "
                    />
                    <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                        $t(
                            'So lange dürfen Mitglieder eigene Beiträge ändern; die Moderation immer.',
                        )
                    }}</span>
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Erlaubte Dateiarten')
                    }}</span>
                    <input
                        v-model="settingsForm.types"
                        type="text"
                        :class="
                            controlClasses({
                                error: !!settingError('types'),
                                mono: true,
                            })
                        "
                    />
                    <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                        $t(
                            'Mit Komma getrennt. SVG und HTML sind nie erlaubt; Bilder werden neu gespeichert (ohne Ort und Kamera).',
                        )
                    }}</span>
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Grösse je Datei (MB)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.max_mb"
                        type="number"
                        min="1"
                        :class="
                            controlClasses({ error: !!settingError('max_mb') })
                        "
                    />
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Speicher je Konto (MB)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.quota_mb"
                        type="number"
                        min="1"
                        :class="
                            controlClasses({
                                error: !!settingError('quota_mb'),
                            })
                        "
                    />
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Anhänge je Beitrag')
                    }}</span>
                    <input
                        v-model.number="settingsForm.max_files"
                        type="number"
                        min="1"
                        max="100"
                        :class="
                            controlClasses({
                                error: !!settingError('max_files'),
                            })
                        "
                    />
                </label>
                <fieldset class="block sm:col-span-2">
                    <legend :class="labelClasses">
                        {{ $t('Reaktionen') }}
                    </legend>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <label
                            v-for="option in reaction_options"
                            :key="option.key"
                            class="inline-flex items-center gap-1.5 rounded-full border border-ash-light bg-white px-3 py-1 text-[13px]"
                        >
                            <input
                                v-model="settingsForm.reactions"
                                type="checkbox"
                                :value="option.key"
                            />
                            <span aria-hidden="true">{{ option.symbol }}</span>
                            {{ option.label }}
                        </label>
                    </div>
                    <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                        $t(
                            'Diese Reaktionen stehen unter den Beiträgen zur Wahl — mindestens eine.',
                        )
                    }}</span>
                    <span
                        v-if="settingError('reactions')"
                        class="text-danger mt-1 block text-[11.5px] font-semibold"
                        >{{ settingError('reactions') }}</span
                    >
                </fieldset>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Verweis nach dem Verschieben (Tage, 0 = immer)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.redirect_days"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('redirect_days'),
                            })
                        "
                    />
                    <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                        $t(
                            'So lange zeigt das alte Forum einen Verweis auf ein verschobenes Thema.',
                        )
                    }}</span>
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t(
                            'Mindestabstand zwischen Beiträgen (Sekunden, 0 = aus)',
                        )
                    }}</span>
                    <input
                        v-model.number="settingsForm.flood_seconds"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('flood_seconds'),
                            })
                        "
                    />
                    <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                        $t('Flood-Control: Moderation und Team warten nie.')
                    }}</span>
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Vorläufig ausblenden ab Meldungen (0 = nie)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.auto_hide"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('auto_hide'),
                            })
                        "
                    />
                    <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                        $t(
                            'Ab so vielen offenen Meldungen verschwindet ein Beitrag, bis die Moderation entscheidet.',
                        )
                    }}</span>
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Verwarnungen gelten (Tage, 0 = immer)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.warning_days"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('warning_days'),
                            })
                        "
                    />
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Schreibsperre ab Punkten (0 = nie)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.mute_points"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('mute_points'),
                            })
                        "
                    />
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Dauer der Schreibsperre (Tage)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.mute_days"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('mute_days'),
                            })
                        "
                    />
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Sperre ab Punkten (0 = nie)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.ban_points"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('ban_points'),
                            })
                        "
                    />
                </label>
                <label class="block">
                    <span :class="labelClasses">{{
                        $t('Dauer der Sperre (Tage)')
                    }}</span>
                    <input
                        v-model.number="settingsForm.ban_days"
                        type="number"
                        min="0"
                        :class="
                            controlClasses({
                                error: !!settingError('ban_days'),
                            })
                        "
                    />
                </label>
                <label class="block md:col-span-2">
                    <span :class="labelClasses">{{
                        $t('Beiträge beim Löschen eines Kontos (Datenschutz)')
                    }}</span>
                    <select
                        v-model="settingsForm.privacy_posts"
                        :class="controlClasses({})"
                    >
                        <option value="anonymisieren">
                            {{
                                $t(
                                    'anonymisieren — die Diskussion bleibt lesbar',
                                )
                            }}
                        </option>
                        <option value="loeschen">
                            {{
                                $t(
                                    'löschen — Themen ohne andere Beiträge gehen mit',
                                )
                            }}
                        </option>
                    </select>
                </label>
                <fieldset class="md:col-span-2">
                    <legend :class="labelClasses">
                        {{ $t('Bedingungen der Community') }}
                    </legend>
                    <label class="flex items-start gap-2 text-[12.5px]">
                        <input
                            v-model="settingsForm.terms_required"
                            type="checkbox"
                            class="mt-0.5 accent-lava-dark"
                        />
                        <span>{{
                            $t(
                                'Zustimmung verlangen — wer das Forum öffnet, stimmt zuerst den Nutzungsbedingungen und den Community-Richtlinien zu.',
                            )
                        }}</span>
                    </label>
                    <label class="mt-2 block max-w-xs">
                        <span :class="labelClasses">{{ $t('Fassung') }}</span>
                        <input
                            v-model="settingsForm.terms_version"
                            type="text"
                            maxlength="40"
                            v-bind="settingsSave.leave('terms_version')"
                            :class="
                                controlClasses({
                                    error: !!settingError('terms_version'),
                                })
                            "
                        />
                        <span
                            class="mt-1 block text-[11.5px] text-basalt-light"
                            >{{
                                $t(
                                    'Eine neue Fassung (z. B. das Datum der Änderung) fragt alle noch einmal.',
                                )
                            }}</span
                        >
                    </label>
                </fieldset>
                <label class="block md:col-span-2">
                    <span :class="labelClasses">{{ $t('Zensur') }}</span>
                    <textarea
                        v-model="settingsForm.censor"
                        rows="3"
                        :class="
                            controlClasses({
                                error: !!settingError('censor'),
                            })
                        "
                    />
                    <span class="mt-1 block text-[11.5px] text-basalt-light">{{
                        $t(
                            'Ein Wort je Zeile; * steht für beliebig viele Buchstaben (spam* trifft «Spammer»). Ein Treffer blockiert das Absenden mit Hinweis.',
                        )
                    }}</span>
                </label>
                <div class="md:col-span-2">
                    <AutosaveState :status="settingsSave.status.value" />
                </div>
            </form>
        </section>

        <p class="text-[12px] text-basalt-light">
            <Link :href="urls.nodes" class="font-semibold hover:underline">{{
                $t('Foren einrichten')
            }}</Link>
            ·
            <Link :href="urls.groups" class="font-semibold hover:underline">{{
                $t('Wer darf was?')
            }}</Link>
        </p>
    </div>
</template>
