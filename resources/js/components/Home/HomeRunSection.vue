<script lang="ts" setup>

import { computed } from 'vue'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import type { V2HomeRunCard } from '../../Types/V2Home'
import RunCarousel from '@/components/Runs/RunCarousel.vue'
import RunCarouselSkeleton from '@/components/Home/RunCarouselSkeleton.vue'

const props = defineProps<{
	runs?: V2HomeRunCard[]
	loading?: boolean
    title: string
    loadingLabel?: string
    emptyLabel?: string
    findMore?: boolean
}>()
const { t } = useI18n()
const loading = computed(() => props.loading ?? props.runs === undefined)
const visibleRuns = computed(() => props.runs?.slice(0, 10) ?? [])
const isMobileOrTablet = useBreakpoints(breakpointsTailwind).smaller('xl')
const carouselRows = computed(() => isMobileOrTablet.value ? 2 : 1)
</script>

<template>
	<div class="w-full min-w-0 flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3" :class="{ 'pr-24': loading || visibleRuns.length }">
            <h2 class="min-w-0 text-2xl font-bold xl:text-3xl">{{ title }}</h2>
            <UButton v-if="findMore && (loading || visibleRuns.length)" :to="route('dashboard.runs.index')" icon="i-lucide-search"
                color="primary" variant="ghost" size="sm" class="shrink-0 rounded-md"
                :label="t('dashboard.find_more_runs')" />
        </div>
		<div class="min-w-0" :aria-busy="loading">
			<template v-if="loading">
				<p role="status" class="sr-only">{{ loadingLabel }}</p>
				<RunCarouselSkeleton :rows="carouselRows" bleed-end />
			</template>
            <slot v-else-if="!visibleRuns.length" name="empty">
                <p class="text-sm text-muted">{{ emptyLabel }}</p>
            </slot>
            <RunCarousel v-else :runs="visibleRuns" :rows="carouselRows" :label="title" bleed-end />
		</div>
	</div>
</template>
