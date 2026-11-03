<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupDashboardGroup } from '@/Types/Groups'
import RunCarousel from '@/components/Runs/RunCarousel.vue'
import GroupRunUpdatesPanel from '@/components/Groups/Dashboard/GroupRunUpdatesPanel.vue'
import { groupRunCard } from '@/utils/groupRunCards'

const props = defineProps<{ group: GroupDashboardGroup }>()
const { t } = useI18n()
const runs = computed(() => (props.group.hosted_activities ?? [])
    .map(activity => groupRunCard(activity, props.group)))
</script>

<template>
    <div v-if="runs.length" class="grid min-w-0 gap-6 md:grid-cols-[minmax(0,7fr)_minmax(0,3fr)]">
        <section aria-labelledby="group-hosted-runs-title" class="flex min-w-0 flex-col gap-4">
            <h2 id="group-hosted-runs-title" class="pr-24 text-lg font-semibold text-highlighted">
                {{ t('groups.dashboard.staff_home.hosted_runs') }}
            </h2>
            <RunCarousel :runs="runs" type="date" :rows="1"
                :label="t('groups.dashboard.staff_home.hosted_runs')" />
        </section>
        <GroupRunUpdatesPanel :notifications="group.hosted_run_notifications ?? []" />
    </div>
</template>
