<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ActivitySlot, ActivitySlotFieldValue } from '@/Types/ActivityRoster'
import { useRunOverviewPreferences } from '@/composables/useRunOverviewPreferences'
import { displayActivityPartyLabel } from '@/utils/activityPartyLabels'
import { translateCharacterClassName, translatePhantomJobName, translateRaidPositionName } from '@/utils/characterJobTranslations'
import { localizedValue } from '@/utils/localizedValue'
import AssignedHolsterTrigger from './AssignedHolsterTrigger.vue'

const props = defineProps<{ slot: ActivitySlot }>()
const { t, locale } = useI18n()
const { numberedSecondaryPartiesEnabled } = useRunOverviewPreferences()

function fieldDetail(field: ActivitySlotFieldValue) {
    const meta = field.display_meta
    const display = field.display_value ?? meta?.label
    const fallback = (typeof display === 'string' ? display : localizedValue(display, locale.value)) || meta?.name || ''
    let value = fallback
    let icon = 'i-lucide-list-checks'
    if (field.source === 'character_classes') {
        icon = 'i-lucide-swords'
        value = translateCharacterClassName(t, meta ?? { name: fallback }, fallback)
    } else if (field.source === 'raid_positions' || field.field_key === 'raid_position') {
        icon = 'i-lucide-crosshair'
        value = translateRaidPositionName(t, { key: meta?.key, name: fallback }, fallback)
    } else if (field.source === 'phantom_jobs') {
        icon = 'i-lucide-sparkles'
        value = translatePhantomJobName(t, { name: meta?.name || fallback }, fallback)
    }
    return {
        key: field.field_key, label: localizedValue(field.field_label, locale.value) || field.field_key, value,
        holsterFieldId: field.source === 'bozja_holsters' ? field.id : null,
        icon, image: meta?.transparent_icon_url || meta?.flaticon_url || meta?.icon_url || meta?.sprite_url,
    }
}

const details = computed(() => {
    const slot = props.slot
    const fields = slot.field_values.map(fieldDetail).filter(field => field.value)
    const groupKey = slot.is_fill_in ? slot.filled_group_key : slot.group_key
    const groupLabel = localizedValue(slot.is_fill_in ? slot.filled_group_label : slot.group_label, locale.value)
    if (slot.is_bench || (!groupLabel && !groupKey)) return fields

    const label = t('groups.activities.overview.board.party_label')
    const party = displayActivityPartyLabel(groupKey ?? '', groupLabel || groupKey || '', numberedSecondaryPartiesEnabled.value, label)
    return [{ key: 'placement-party', label, value: party, icon: 'i-lucide-users-round', image: null, holsterFieldId: null }, ...fields]
})
</script>

<template>
    <div v-if="details.length" class="@container">
        <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-xl bg-elevation-3 ring-1 ring-elevation-3 @min-[16rem]:grid-cols-2 dark:bg-elevation-6 dark:ring-elevation-6">
            <div v-for="detail in details" :key="detail.key"
                class="min-w-0 bg-elevation-1 px-3 py-2.5 last:odd:col-span-full dark:bg-elevation-4">
                <dt class="text-xs font-medium text-muted">{{ detail.label }}</dt>
                <dd class="mt-1 flex items-center gap-2 text-sm font-semibold leading-snug text-highlighted">
                    <img v-if="detail.image" :src="detail.image" alt="" class="size-5 shrink-0 object-contain" />
                    <UIcon v-else :name="detail.icon" aria-hidden="true" class="size-5 shrink-0 text-muted" />
                    <AssignedHolsterTrigger v-if="detail.holsterFieldId" :field-id="detail.holsterFieldId" :label="detail.value" class="wrap-anywhere" />
                    <span v-else class="min-w-0 wrap-anywhere">{{ detail.value }}</span>
                </dd>
            </div>
        </dl>
    </div>
</template>
