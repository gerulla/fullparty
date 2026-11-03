<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { GroupDashboardContentItem } from '@/Types/Groups'

defineProps<{ items: GroupDashboardContentItem[]; groupSlug: string }>()
const { t, locale } = useI18n()
</script>

<template>
    <section aria-labelledby="group-activity-types-title" class="min-w-0 space-y-4">
        <h2 id="group-activity-types-title" class="text-lg font-semibold text-highlighted">
            {{ t('groups.dashboard.content.title') }}
        </h2>
        <div v-if="items.length" class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 sm:gap-4">
            <article v-for="item in items" :key="item.key"
                class="relative flex min-h-40 min-w-0 flex-col justify-end overflow-hidden border border-default bg-neutral-950 p-3 sm:min-h-44 sm:p-4">
                <img v-if="item.activity_banner_image_url || item.activity_image_url"
                    :src="item.activity_banner_image_url || item.activity_image_url!" alt="" loading="lazy" decoding="async"
                    class="absolute inset-0 size-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-t from-neutral-950 via-neutral-950/65 to-neutral-950/10" />
                <div class="relative space-y-2">
                    <h3 class="text-sm font-semibold text-white [overflow-wrap:anywhere] sm:text-base">{{ item.activity_name }}</h3>
                    <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs">
                        <span class="text-white/70">{{ t('groups.dashboard.content.runs_done', item.completed_runs) }}</span>
                        <Link v-if="item.activity_type_id !== null"
                            :href="route('dashboard.runs.index', { locale, activity_type_id: item.activity_type_id, group: groupSlug })"
                            :aria-label="t('groups.dashboard.content.find_type_runs', { name: item.activity_name })"
                            class="ml-auto font-medium text-brand hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand">
                            {{ t('groups.dashboard.content.find_runs') }}
                        </Link>
                    </div>
                </div>
            </article>
        </div>
        <p v-else class="py-4 text-sm text-muted">{{ t('groups.dashboard.content.empty') }}</p>
    </section>
</template>
