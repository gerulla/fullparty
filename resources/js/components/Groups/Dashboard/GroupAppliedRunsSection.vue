<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupDashboardGroup } from '@/Types/Groups'
import RunCarousel from '@/components/Runs/RunCarousel.vue'
import { upcomingGroupRuns } from '@/utils/groupDashboardSchedule'
import { groupRunCard } from '@/utils/groupRunCards'

const props = defineProps<{ group: GroupDashboardGroup; now: Date }>()
const { t } = useI18n()
const runs = computed(() => upcomingGroupRuns(props.group.applied_activities ?? [], props.now)
    .map(activity => groupRunCard(activity, props.group)))
</script>

<template>
    <section v-if="runs.length" aria-labelledby="group-applied-runs-title" class="flex min-w-0 flex-col gap-4">
        <h2 id="group-applied-runs-title" class="pr-24 text-lg font-semibold text-highlighted">
            {{ t('groups.dashboard.user_home.your_upcoming_runs') }}
        </h2>
        <RunCarousel :runs="runs" type="date" :rows="1" :label="t('groups.dashboard.user_home.your_upcoming_runs')" />
    </section>
</template>
