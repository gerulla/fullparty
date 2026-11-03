<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActivityTypeFilter from '@/components/Runs/ActivityTypeFilter.vue'
import RunCard from '@/components/Runs/RunCard.vue'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { availableActivityTypes } from '@/utils/activityTypes'
import { groupDashboardDays } from '@/utils/groupDashboardSchedule'
import { groupRunCard } from '@/utils/groupRunCards'
import { isActiveRunParticipation } from '@/utils/runParticipationMeta'
import type { GroupDashboardGroup } from '@/Types/Groups'

const props = defineProps<{ group: GroupDashboardGroup; now: Date }>()
const { t } = useI18n()
const { displayTimeZone, displayTimeZoneLabel } = useDateTimeFormat()
const selectedTypeId = ref<number | null>(null)
const hideParticipating = ref(false)
const activities = computed(() => groupDashboardDays(props.group.calendar_activities, props.now, displayTimeZone.value)
    .flatMap(day => day.activities))
const activityTypes = computed(() => availableActivityTypes(activities.value))
const runs = computed(() => activities.value
    .filter(activity => selectedTypeId.value === null || activity.activity_type.id === selectedTypeId.value)
    .filter(activity => !hideParticipating.value || !isActiveRunParticipation(activity.participation_status))
    .map(activity => groupRunCard(activity, props.group)))

watch(activityTypes, types => {
    if (selectedTypeId.value !== null && !types.some(type => type.id === selectedTypeId.value)) selectedTypeId.value = null
})
</script>

<template>
    <section aria-labelledby="group-upcoming-runs-title" class="min-w-0 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 id="group-upcoming-runs-title" class="text-lg font-semibold text-highlighted">{{ t('groups.dashboard.week_overview.title') }}</h2>
                <span class="text-xs text-muted">{{ displayTimeZoneLabel }}</span>
            </div>
            <div class="flex w-full flex-wrap items-center gap-3 sm:ml-auto sm:w-auto">
                <USwitch v-model="hideParticipating" color="brand" size="sm"
                    :label="t('groups.dashboard.user_home.hide_participating')" />
                <ActivityTypeFilter
                    v-model="selectedTypeId" :activity-types="activityTypes" :disabled="!activityTypes.length"
                    color="brand" class="w-64 max-w-full"
                />
            </div>
        </div>
        <!-- Keep empty columns so filtered results and partial rows retain the same card width. -->
        <div v-if="runs.length" class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,18rem),1fr))] items-stretch gap-x-4 gap-y-6">
            <RunCard v-for="run in runs" :key="run.id" :run="run" type="date" />
        </div>
        <p v-else class="py-4 text-sm text-muted">{{ t(activities.length
            ? 'groups.dashboard.user_home.no_matching_runs' : 'groups.dashboard.week_overview.empty') }}</p>
    </section>
</template>
