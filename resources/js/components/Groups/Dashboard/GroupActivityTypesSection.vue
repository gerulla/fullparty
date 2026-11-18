<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupDashboardContentItem } from '@/Types/Groups'
import ActivityTypeCard from '@/components/Shared/ActivityTypeCard.vue'

defineProps<{ items: GroupDashboardContentItem[]; groupSlug: string }>()
const { t, locale } = useI18n()
</script>

<template>
    <section aria-labelledby="group-activity-types-title" class="min-w-0 space-y-4">
        <h2 id="group-activity-types-title" class="text-lg font-semibold text-highlighted">
            {{ t('groups.dashboard.content.title') }}
        </h2>
        <div v-if="items.length" class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 sm:gap-4">
            <ActivityTypeCard v-for="item in items" :key="item.key" :title="item.activity_name"
                :image="item.activity_banner_image_url || item.activity_image_url">
                <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs">
                    <span class="text-white/70">{{ t('groups.dashboard.content.runs_done', item.completed_runs) }}</span>
                    <Link v-if="item.activity_type_id !== null"
                        :href="route('dashboard.runs.index', { locale, activity_type_id: item.activity_type_id, group: groupSlug })"
                        :aria-label="t('groups.dashboard.content.find_type_runs', { name: item.activity_name })"
                        class="ml-auto font-medium text-brand hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand">
                        {{ t('groups.dashboard.content.find_runs') }}
                    </Link>
                </div>
            </ActivityTypeCard>
        </div>
        <p v-else class="py-4 text-sm text-muted">{{ t('groups.dashboard.content.empty') }}</p>
    </section>
</template>
