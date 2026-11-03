<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'
import { useGroupRunLabels } from '@/composables/useGroupRunLabels'
import { getActivityStatusMeta } from '@/utils/activityStatusMeta'
import type { GroupDashboardActivity } from '@/Types/Groups'

const props = defineProps<{ activity: GroupDashboardActivity | null }>()
const { t } = useI18n()
const { formatDateTime, displayTimeZoneLabel } = useDateTimeFormat()
const { title, typeName, target } = useGroupRunLabels()
const statusMeta = computed(() => getActivityStatusMeta(props.activity?.status ?? ''))
</script>

<template>
    <section aria-labelledby="group-next-run-title" class="space-y-3">
        <h2 id="group-next-run-title" class="text-lg font-semibold text-highlighted">{{ t('groups.dashboard.content.next_run') }}</h2>
        <UCard :ui="{ body: 'p-4 sm:p-5' }">
            <div v-if="activity" class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <img
                    v-if="activity.small_image_url || activity.banner_image_url"
                    :src="activity.small_image_url || activity.banner_image_url" alt=""
                    class="hidden size-20 shrink-0 object-cover sm:block"
                >
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h3 class="text-lg font-semibold text-highlighted [overflow-wrap:anywhere]">{{ title(activity) }}</h3>
                        <UBadge :color="statusMeta.color" :icon="statusMeta.icon" variant="subtle" :label="t(`groups.activities.statuses.${activity.status}`)" />
                    </div>
                    <p v-if="activity.title && activity.title !== typeName(activity)" class="text-sm text-muted">{{ typeName(activity) }}</p>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-toned">
                        <span class="inline-flex items-center gap-2">
                            <UIcon name="i-lucide-calendar-clock" class="size-4 shrink-0 text-muted" />
                            <time :datetime="activity.starts_at">
                                {{ formatDateTime(activity.starts_at, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }}
                            </time>
                            <span class="text-xs text-muted">{{ displayTimeZoneLabel }}</span>
                        </span>
                        <span v-if="activity.duration_hours" class="inline-flex items-center gap-2">
                            <UIcon name="i-lucide-clock" class="size-4 shrink-0 text-muted" />
                            {{ t('groups.activities.management.overview.duration', { count: activity.duration_hours }) }}
                        </span>
                        <span v-if="target(activity)" class="inline-flex items-center gap-2">
                            <UIcon name="i-lucide-flag" class="size-4 shrink-0 text-muted" />
                            {{ target(activity) }}
                        </span>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <UButton
                        color="neutral" variant="outline" trailing-icon="i-lucide-arrow-right"
                        :label="t('groups.dashboard.user_home.view_run')" @click="router.visit(activity.links.view)"
                    />
                    <UButton
                        v-if="activity.links.apply" color="brand" variant="solid" icon="i-lucide-file-pen-line"
                        :label="activity.has_existing_application ? t('groups.dashboard.upcoming_runs.view_application') : t('groups.dashboard.upcoming_runs.apply')"
                        @click="router.visit(activity.links.apply)"
                    />
                </div>
            </div>
            <div v-else class="flex items-start gap-3 py-2">
                <UIcon name="i-lucide-calendar-days" class="mt-0.5 size-5 shrink-0 text-muted" />
                <div class="space-y-1">
                    <p class="font-medium text-highlighted">{{ t('groups.dashboard.upcoming_runs.empty_title') }}</p>
                    <p class="text-sm text-muted">{{ t('groups.dashboard.upcoming_runs.empty_description') }}</p>
                </div>
            </div>
        </UCard>
    </section>
</template>
