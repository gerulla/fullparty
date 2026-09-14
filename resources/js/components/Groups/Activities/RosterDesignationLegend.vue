<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { availableSpecialistDesignations } from '@/utils/specialistDesignations'
import type { ActivitySlot } from '@/Types/ActivityRoster'

const props = defineProps<{ slots: ActivitySlot[]; showSelf: boolean }>()
const { t } = useI18n()
const available = computed(() => [...new Set(props.slots.flatMap(slot => slot.available_designations ?? []))])
const markers = computed(() => [
    ...(available.value.includes('host') ? [{ key: 'host', icon: 'i-lucide-swords', iconClass: 'text-sky-500', labelKey: 'groups.activities.management.roster.host_badge' }] : []),
    ...(available.value.includes('raid_leader') ? [{ key: 'raid_leader', icon: 'i-lucide-crown', iconClass: 'text-amber-400', labelKey: 'groups.activities.management.roster.marker_tooltips.party_lead' }] : []),
    ...availableSpecialistDesignations(available.value).map(definition => ({
        key: definition.key,
        icon: definition.icon,
        iconClass: definition.menuClass,
        labelKey: `groups.activities.management.roster.${definition.key}_badge`,
    })),
    ...(props.showSelf ? [{ key: 'self', icon: 'i-mingcute-badge-line', iconClass: 'text-primary', labelKey: 'groups.activities.management.roster.self_badge' }] : []),
])
</script>

<template>
    <div v-if="markers.length" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-default px-4 py-2.5 text-xs">
        <span class="font-medium text-toned">{{ t('groups.activities.overview.board.designation_legend') }}</span>
        <ul class="flex flex-wrap items-center gap-x-4 gap-y-2" :aria-label="t('groups.activities.overview.board.designation_legend')">
            <li v-for="marker in markers" :key="marker.key" class="inline-flex items-center gap-1.5 text-muted">
                <UIcon :name="marker.icon" class="size-4 shrink-0" :class="marker.iconClass" aria-hidden="true" />
                {{ t(marker.labelKey) }}
            </li>
        </ul>
    </div>
</template>
