<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ApplicantRecordContext } from '../../../Types/ApplicantRecord'
import { useApplicantRecord } from '@/composables/useApplicantRecord'
import { localizedValue } from '@/utils/localizedValue'

const props = defineProps<ApplicantRecordContext>()
const { t, locale } = useI18n()
const { record, loading, failed, reload } = useApplicantRecord(props)
const columns = computed(() => [
    { accessorKey: 'label', header: t('groups.activities.management.queue.modal.record.boss') },
    { accessorKey: 'onsite', header: t('groups.activities.management.queue.modal.record.onsite'), meta: { class: { th: 'text-right', td: 'text-right' } } },
    { accessorKey: 'fflogs', header: t('groups.activities.management.queue.modal.record.fflogs'), meta: { class: { th: 'text-right', td: 'text-right' } } },
])
const sourceMessage = computed(() => {
    if (!record.value || record.value.fflogs_status === 'available') return null
    return t(`groups.activities.management.queue.modal.record.fflogs_${record.value.fflogs_status}`)
})
</script>

<template>
    <section class="space-y-3">
        <p class="text-xs text-muted">{{ t('groups.activities.management.queue.modal.record.description') }}</p>
        <div v-if="loading && !record" class="space-y-2" :aria-label="t('groups.activities.management.queue.modal.record.loading')" aria-busy="true">
            <USkeleton v-for="row in 4" :key="row" class="h-12 w-full" />
        </div>
        <p v-if="failed" class="text-sm text-error" role="alert">{{ t('groups.activities.management.queue.modal.record.error') }}</p>
        <template v-if="record">
            <UTable :data="record.milestones" :columns="columns" :empty="t('groups.activities.management.queue.modal.record.empty')" :ui="{ th: 'px-2 py-3', td: 'px-2 py-3' }">
                <template #label-cell="{ row }">
                    <span class="font-medium whitespace-normal text-toned">{{ localizedValue(row.original.label, locale, 'en') }}</span>
                </template>
                <template v-for="source in (['onsite', 'fflogs'] as const)" :key="source" #[`${source}-cell`]="{ row }">
                    <div v-if="row.original[source]" class="flex flex-wrap items-center justify-end gap-2">
                        <span class="whitespace-nowrap tabular-nums">{{ t('groups.activities.management.queue.modal.inspector.kills', { count: row.original[source].kills }) }}</span>
                        <UBadge :color="row.original[source].progress_percent >= 100 ? 'success' : 'neutral'" variant="soft" :label="`${row.original[source].progress_percent}%`" :aria-label="t('groups.activities.management.queue.modal.record.progress', { percent: row.original[source].progress_percent })" />
                    </div>
                    <span v-else class="text-muted" :title="t('groups.activities.management.queue.modal.record.unavailable')">—</span>
                </template>
            </UTable>
            <p v-if="!record.onsite_available" class="text-xs text-muted">{{ t('groups.activities.management.queue.modal.record.onsite_unavailable') }}</p>
            <p v-if="sourceMessage" class="text-xs text-muted" :class="{ 'text-error': record.fflogs_status === 'error' }">{{ sourceMessage }}</p>
        </template>
        <UButton v-if="failed || record?.fflogs_status === 'error'" color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :label="t('groups.activities.management.queue.modal.record.retry')" :loading="loading" @click="reload" />
    </section>
</template>
