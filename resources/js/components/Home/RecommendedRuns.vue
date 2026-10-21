<script lang="ts" setup>

import { computed } from 'vue'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import type { V2HomeRunCard } from '../../Types/V2Home'
import RunCarousel from '@/components/Home/RunCarousel.vue'
import RunCarouselSkeleton from '@/components/Home/RunCarouselSkeleton.vue'

const props = defineProps<{
	runs?: V2HomeRunCard[]
	loading?: boolean
}>()
const { t } = useI18n()
const loading = computed(() => props.loading ?? props.runs === undefined)
const visibleRuns = computed(() => props.runs?.slice(0, 10) ?? [])
const isMobileOrTablet = useBreakpoints(breakpointsTailwind).smaller('xl')
const carouselRows = computed(() => isMobileOrTablet.value ? 2 : 1)
</script>

<template>
	<div class="w-full min-w-0 flex flex-col gap-4">
		<h2 class="pr-24 text-2xl font-bold xl:text-3xl">{{ t('dashboard.recommended_runs.title') }}</h2>
		<div class="min-w-0" :aria-busy="loading">
			<template v-if="loading">
				<p role="status" class="sr-only">{{ t('dashboard.recommended_runs.loading') }}</p>
				<RunCarouselSkeleton :rows="carouselRows" />
			</template>
			<p v-else-if="!visibleRuns.length" class="text-sm text-muted">{{ t('dashboard.recommended_runs.empty') }}</p>
            <RunCarousel v-else :runs="visibleRuns" :rows="carouselRows" :label="t('dashboard.recommended_runs.title')" />
		</div>
	</div>
</template>
