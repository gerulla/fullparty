<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DevelopmentPreviewMenu from '@/components/Shared/DevelopmentPreviewMenu.vue'
import type { ActivityStatus } from '@/Types/ActivityCore'
import type { RunApplicationPreviewState } from '@/Types/RunParticipation'
import { activityStatuses, getActivityStatusMeta } from '@/utils/activityStatusMeta'
import { RUN_PARTICIPATION_META } from '@/utils/runParticipationMeta'

const props = defineProps<{ showApplication: boolean; actualStatus: string }>()
const run = defineModel<ActivityStatus | 'live'>('run', { required: true })
const application = defineModel<RunApplicationPreviewState>('application', { required: true })
const { t } = useI18n()
const runItems = computed(() => [
    { value: 'live', label: t('runs.participation.preview.actual_run', { status: t(`groups.activities.statuses.${props.actualStatus}`) }) },
    ...activityStatuses.map(value => ({
        value, label: t(`groups.activities.statuses.${value}`), icon: getActivityStatusMeta(value).icon,
    })),
])
const applicationItems = computed(() => [
    { value: 'live', label: t('runs.participation.preview.live') },
    { value: 'not_applied', label: t('runs.participation.preview.not_applied') },
    ...Object.entries(RUN_PARTICIPATION_META).map(([value, meta]) => ({
        value, label: value === 'approved' ? t('groups.activities.overview.participation.no_placement') : t(`runs.participation.statuses.${value}`),
        icon: value === 'approved' ? 'i-lucide-user-round-minus' : meta.icon,
    })),
])
</script>

<template>
    <DevelopmentPreviewMenu :active="run !== 'live' || application !== 'live'">
        <UFormField :label="t('runs.participation.preview.run_state')" class="px-2 py-1">
            <USelect v-model="run" :items="runItems" class="w-full rounded-md" />
        </UFormField>
        <UFormField v-if="showApplication" :label="t('runs.participation.preview.label')" :help="t('runs.participation.preview.help')" class="px-2 py-1">
            <USelect v-model="application" :items="applicationItems" class="w-full rounded-md" />
        </UFormField>
    </DevelopmentPreviewMenu>
</template>
