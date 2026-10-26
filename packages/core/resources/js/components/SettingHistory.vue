<script setup lang="ts">
import type { SettingHistoryRow, SettingItem } from '../types-admin';
import { valueText } from './admin';
import Button from './Button.vue';
import Card from './Card.vue';
import StateTag from './StateTag.vue';

/** Der Verlauf eines Einstellungswerts (Mockup 18/3): alt → neu, wer, wann; der aktuelle markiert, die übrigen wiederherstellbar. */
defineProps<{ item: SettingItem; canEdit: boolean }>();
defineEmits<{ (e: 'restore', row: SettingHistoryRow): void }>();
</script>

<template>
    <Card
        :title="$t('Verlauf · :label', { label: item.label })"
        :sub="$t('wer · wann · alt → neu')"
    >
        <ul
            v-if="(item.history ?? []).length > 0"
            class="divide-y divide-ash-light"
        >
            <li
                v-for="row in item.history"
                :key="row.id"
                class="flex items-center justify-between gap-2 py-2"
            >
                <span class="min-w-0">
                    <span class="block font-mono text-[12.5px] break-words"
                        >{{ valueText(row.old, item.unit) }} →
                        <strong>{{
                            valueText(row.new, item.unit)
                        }}</strong></span
                    >
                    <span class="block text-[11.5px] text-basalt-light"
                        >{{ row.by ?? $t('System') }} ·
                        {{ row.at ?? '—' }}</span
                    >
                </span>
                <StateTag v-if="row.current" tone="success">{{
                    $t('gilt jetzt')
                }}</StateTag>
                <Button
                    v-else-if="canEdit"
                    variant="link"
                    size="sm"
                    :title="
                        $t('Wert «:value» wiederherstellen', {
                            value: valueText(row.new, item.unit),
                        })
                    "
                    @click="$emit('restore', row)"
                    >{{ $t('Wiederherstellen') }}</Button
                >
            </li>
        </ul>
        <p v-else class="text-[12.5px] text-basalt-light">
            {{
                $t('Noch keine Änderung — es gilt der Startwert aus dem Code.')
            }}
        </p>
    </Card>
</template>
