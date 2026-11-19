<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ActivityCompletionSummary } from '@/Types/ActivityProgression'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'

const props = defineProps<{ completedProgression: ActivityCompletionSummary }>()
const { t, n } = useI18n()
const { formatDateTime, timeDisplayLabel } = useDateTimeFormat()
const labels = 'groups.activities.management.overview.progression'
const percent = (value: number) => n(value / 100, { style: 'percent', maximumFractionDigits: 2 })

const details = computed(() => {
    const result = props.completedProgression
    return [
        { key: 'completed_at', value: result.completedAt && `${formatDateTime(result.completedAt)} ${timeDisplayLabel.value}`, datetime: result.completedAt },
        { key: 'source', value: result.sourceLabel },
        { key: 'furthest_point', value: result.furthestPointLabel },
        { key: 'best_progress', value: result.bestProgressPercent !== null ? percent(result.bestProgressPercent) : null },
    ]
})
</script>

<template>
    <section :aria-label="t(labels + '.title')" class="min-w-0 rounded-xl border border-elevation-3 bg-elevation-1 text-default shadow-sm dark:border-elevation-6 dark:bg-elevation-3 dark:shadow-none">
        <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <UIcon name="i-lucide-chart-no-axes-column-increasing" aria-hidden="true" class="size-5 shrink-0 text-muted" />
                <h2 class="font-semibold text-highlighted">{{ t(labels + '.title') }}</h2>
            </div>
            <UButton v-if="completedProgression.progressLinkUrl" :href="completedProgression.progressLinkUrl"
                target="_blank" rel="noopener noreferrer" color="neutral" variant="ghost" size="sm"
                trailing-icon="i-lucide-external-link" class="rounded-md" :label="t(labels + '.view_fflogs')" />
        </header>

        <div class="space-y-5 border-t border-elevation-3 px-4 py-4 dark:border-elevation-6 sm:px-6">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 lg:grid-cols-4">
                <div v-for="detail in details" :key="detail.key" class="min-w-0">
                    <dt class="text-xs text-muted">{{ t(labels + '.' + detail.key) }}</dt>
                    <dd class="mt-1 text-sm wrap-anywhere" :class="detail.value ? 'font-semibold text-highlighted' : 'text-muted'">
                        <time v-if="detail.datetime" :datetime="detail.datetime">{{ detail.value }}</time>
                        <template v-else>{{ detail.value ?? t(labels + '.not_recorded') }}</template>
                    </dd>
                </div>
            </dl>

            <div v-if="completedProgression.notes" class="space-y-1">
                <h3 class="text-sm font-medium text-toned">{{ t(labels + '.notes') }}</h3>
                <p class="text-sm leading-relaxed wrap-anywhere whitespace-pre-wrap">{{ completedProgression.notes }}</p>
            </div>

            <div v-if="completedProgression.milestones.length">
                <h3 class="mb-2 text-sm font-medium text-toned">{{ t(labels + '.milestones') }}</h3>
                <ul class="grid gap-2">
                    <li v-for="milestone in completedProgression.milestones" :key="milestone.key"
                        class="relative isolate overflow-hidden rounded-lg bg-elevation-2 ring-1 ring-elevation-3 ring-inset dark:bg-elevation-4 dark:ring-elevation-6">
                        <div v-if="milestone.bestProgressPercent !== null" aria-hidden="true"
                            class="absolute inset-y-0 start-0 -z-1 bg-green-500/20 dark:bg-green-400/20"
                            :style="{ width: milestone.bestProgressPercent + '%' }" />
                        <div class="flex flex-col gap-1.5 px-3.5 py-2.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                            <span class="text-sm font-semibold wrap-anywhere text-highlighted">{{ milestone.label }}</span>
                            <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs sm:justify-end">
                                <div class="flex items-baseline gap-1.5">
                                    <dt class="text-muted">{{ t('groups.activities.management.complete_activity_modal.kills') }}</dt>
                                    <dd class="font-semibold tabular-nums text-toned">{{ n(milestone.kills) }}</dd>
                                </div>
                                <div class="flex items-baseline gap-1.5">
                                    <dt class="text-muted">{{ t(labels + '.best_progress') }}</dt>
                                    <dd class="font-semibold tabular-nums text-toned">{{ milestone.bestProgressPercent === null ? t(labels + '.not_recorded') : percent(milestone.bestProgressPercent) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
