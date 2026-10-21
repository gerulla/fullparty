<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import HomeEmptyState from '@/components/Home/HomeEmptyState.vue'
import RunCarousel from '@/components/Home/RunCarousel.vue'
import RunCarouselSkeleton from '@/components/Home/RunCarouselSkeleton.vue'
import type { V2HomeGroupRuns } from '../../Types/V2Home'

const props = defineProps<{
    groups?: V2HomeGroupRuns[]
    loading?: boolean
}>()
const { t } = useI18n()
const loading = computed(() => props.loading ?? props.groups === undefined)
const quietGroupsIllustration = '/images/empty-states/groups-quiet.png'
</script>

<template>
    <div class="w-full min-w-0 flex flex-col gap-4" :aria-busy="loading">
        <div class="flex items-center justify-between gap-4">
            <h2 class="min-w-0 text-2xl font-bold xl:text-3xl">{{ t('dashboard.from_your_groups.title') }}</h2>
            <p class="shrink-0 text-right text-xs text-muted xl:text-sm">{{ t('dashboard.from_your_groups.period') }}</p>
        </div>
        <div v-if="loading" class="flex min-w-0 flex-col gap-8">
            <p role="status" class="sr-only">{{ t('dashboard.from_your_groups.loading') }}</p>
            <div v-for="group in 2" :key="group" class="flex min-w-0 flex-col gap-4">
                <div aria-hidden="true" class="flex h-6 items-center gap-2 pr-24 xl:h-7">
                    <USkeleton class="size-6 shrink-0 rounded-full bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
                    <USkeleton class="h-3.5 max-w-full rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none xl:h-4" :class="group === 1 ? 'w-44' : 'w-36'" />
                </div>
                <RunCarouselSkeleton />
            </div>
        </div>
        <HomeEmptyState v-else-if="!groups?.length" spacious
            :title="t('dashboard.empty_states.group_runs.title')"
            :description="t('dashboard.empty_states.group_runs.description')"
            :action-label="t('dashboard.empty_states.find_runs')"
            :action-to="route('dashboard.runs.index')">
            <template #illustration>
                <img :src="quietGroupsIllustration" alt="" aria-hidden="true"
                    class="h-40 w-48 object-contain" width="192" height="160" loading="lazy" decoding="async">
            </template>
        </HomeEmptyState>
        <div v-else class="flex min-w-0 flex-col gap-8">
            <section v-for="row in groups" :key="row.group.id" class="flex min-w-0 flex-col gap-4"
                :aria-labelledby="`group-runs-${row.group.id}`">
                <h3 :id="`group-runs-${row.group.id}`" class="pr-24 text-base font-semibold xl:text-lg">
                    <Link :href="row.runs_href" :aria-label="t('dashboard.group_panel.open_runs', { name: row.group.name })"
                        class="inline-flex max-w-full items-center gap-2 rounded-md text-default hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                        <UAvatar :src="row.group.profile_picture_url ?? undefined" alt="" icon="i-lucide-users" size="xs"
                            class="shrink-0" :ui="{ root: 'bg-elevation-3 text-muted dark:bg-elevation-5' }" />
                        <span class="truncate">{{ row.group.name }}</span>
                    </Link>
                </h3>
                <RunCarousel :runs="row.runs" :label="t('dashboard.group_panel.open_runs', { name: row.group.name })" />
            </section>
        </div>
    </div>
</template>
