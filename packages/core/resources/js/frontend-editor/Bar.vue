<script setup lang="ts">
import { computed } from 'vue';
import { t, tc } from '../i18n';
import type { IslandState } from './state';

/**
 * Die Leiste des Frontend-Editors (M-19): klein, unten, im Shadow DOM — Stand (live/Entwurf), Meldungen als
 * höfliche Live-Region, «Auf der Seite bearbeiten» / «Fertig» und der Weg ins Backend, wo freigegeben wird.
 */
const props = defineProps<{ state: IslandState }>();
const emit = defineEmits<{ toggle: [] }>();

const stand = computed(() => {
    const { version, live, editing } = props.state;
    if (!editing) {
        return version.number === live.number
            ? t('Live · Fassung :number', { number: live.number })
            : t('Live · Fassung :live · :status: Fassung :number', {
                  live: live.number,
                  status: t(version.status_label),
                  number: version.number,
              });
    }
    if (version.status === 'draft') {
        return t('Entwurf · Fassung :number', { number: version.number });
    }

    return t(':status · Fassung :number', {
        status: t(version.status_label),
        number: version.number,
    });
});

const hint = computed(() => {
    const { editing, fieldCount, version } = props.state;
    if (!editing) {
        return t('Freigabe im Backend.');
    }
    if (fieldCount === 0) {
        return t(
            'Auf dieser Seite lässt sich hier kein Text bearbeiten — bitte im Backend.',
        );
    }
    const fields = tc(
        'Ein Feld bearbeitbar|:count Felder bearbeitbar',
        fieldCount,
    );

    return version.status === 'draft'
        ? t(':fields — Änderungen speichern sich als Entwurf.', { fields })
        : t(':fields — die erste Änderung beginnt einen neuen Entwurf.', {
              fields,
          });
});
</script>

<template>
    <div
        class="bar"
        role="region"
        :aria-label="t('Basalto · Seite bearbeiten')"
    >
        <span class="hex" aria-hidden="true"></span>
        <p class="state">
            <abbr class="locale" :lang="state.locale">{{
                state.locale.toUpperCase()
            }}</abbr>
            · {{ stand }}
        </p>
        <p
            class="status"
            role="status"
            aria-live="polite"
            :data-tone="state.tone"
        >
            {{ state.message || hint }}
        </p>
        <div class="actions">
            <button
                type="button"
                :disabled="state.busy"
                @click="emit('toggle')"
            >
                {{
                    state.editing ? t('Fertig') : t('Auf der Seite bearbeiten')
                }}
            </button>
            <a :href="state.backendUrl">{{ t('Im Backend öffnen') }}</a>
        </div>
    </div>
</template>
