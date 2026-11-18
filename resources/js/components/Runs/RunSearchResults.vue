<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import RunCard from '@/components/Runs/RunCard.vue'
import RunCardSkeleton from '@/components/Runs/RunCardSkeleton.vue'
import { runSearchResultSorts, type RunDiscoveryDiscoverResponse, type RunSearchResultSort } from '@/Types/RunDiscovery'

defineProps<{ result: RunDiscoveryDiscoverResponse | null; loading: boolean; failed: boolean; columns: number; perPage: number; sort: RunSearchResultSort }>()
const emit = defineEmits<{ page: [page: number]; sort: [value: RunSearchResultSort]; clear: []; retry: []; bookmarkChanged: [id: number, saved: boolean] }>()
const { t } = useI18n()
const sortOptions = computed(() => runSearchResultSorts.map(value => ({
    value, label: t(`runs.discovery.results.sort_options.${value}`),
})))
</script>

<template>
    <section class="space-y-6" :aria-busy="loading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold text-highlighted sm:text-2xl" aria-live="polite">
                {{ loading ? t('runs.browse.loading') : t('runs.search.results', result?.meta.total ?? 0) }}
            </h2>
            <div class="flex max-w-full items-center gap-2">
                <USelect :model-value="sort" :items="sortOptions" :aria-label="t('runs.discovery.results.sort_by')"
                    icon="i-lucide-arrow-up-down" color="neutral" variant="soft"
                    class="min-h-10 max-w-full cursor-pointer rounded-full bg-elevation-1 text-highlighted shadow-md focus-visible:ring-2 focus-visible:ring-brand-700 dark:bg-elevation-4 dark:shadow-black/45 dark:focus-visible:ring-brand-400"
                    :ui="{
                        content: 'w-max min-w-(--reka-select-trigger-width) max-w-[calc(100vw-2rem)] rounded-2xl bg-elevation-1 shadow-lg ring-0 dark:bg-elevation-5 dark:shadow-black/55',
                        item: 'cursor-pointer before:rounded-xl data-highlighted:not-data-disabled:before:bg-elevation-2 dark:data-highlighted:not-data-disabled:before:bg-neutral-700',
                    }"
                    @update:model-value="emit('sort', $event)" />
                <UButton color="neutral" variant="ghost" icon="i-lucide-arrow-left" class="hidden rounded-full md:inline-flex"
                    :label="t('runs.search.back_to_browse')" @click="emit('clear')" />
            </div>
        </div>
        <UAlert v-if="failed" color="error" variant="soft" icon="i-lucide-circle-alert" class="rounded-2xl"
            :title="t('runs.search.failed')" :actions="[{ label: t('runs.search.retry'), onClick: () => emit('retry'), color: 'error', variant: 'outline' }]" />
        <div v-else-if="loading || result?.cards.length" class="grid gap-x-4 gap-y-8"
            :style="{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }">
            <template v-if="loading"><RunCardSkeleton v-for="index in perPage" :key="index" type="finder" /></template>
            <template v-else>
                <RunCard v-for="run in result?.cards" :key="run.id" :run="run" type="finder"
                    @bookmark-changed="emit('bookmarkChanged', run.id, $event)" />
            </template>
        </div>
        <div v-else class="rounded-3xl border border-default bg-elevation-1 px-6 py-16 text-center dark:bg-elevation-2">
            <UIcon name="i-lucide-search-x" class="mb-3 size-8 text-muted" />
            <h3 class="text-lg font-semibold text-highlighted">{{ t('runs.search.empty_title') }}</h3>
            <p class="mt-2 text-sm text-muted">{{ t('runs.search.empty_description') }}</p>
        </div>
        <UPagination v-if="!loading && result && result.meta.last_page > 1" :page="result.meta.current_page"
            :items-per-page="result.meta.per_page" :total="result.meta.total" :sibling-count="1"
            class="flex justify-center" :ui="{ item: 'rounded-full', first: 'rounded-full', prev: 'rounded-full', next: 'rounded-full', last: 'rounded-full' }"
            @update:page="emit('page', $event)" />
    </section>
</template>
