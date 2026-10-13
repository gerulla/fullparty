<script lang="ts" setup>

import { computed } from 'vue'
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
</script>

<template>
	<div class="w-full min-w-0 flex flex-col gap-4">
		<h2 class="pr-24 text-3xl font-bold">{{ t('dashboard.recommended_runs.title') }}</h2>
		<div class="min-w-0" :aria-busy="loading">
			<template v-if="loading">
				<p role="status" class="sr-only">{{ t('dashboard.recommended_runs.loading') }}</p>
				<RunCarouselSkeleton />
			</template>
			<p v-else-if="!visibleRuns.length" class="text-sm text-muted">{{ t('dashboard.recommended_runs.empty') }}</p>
            <RunCarousel v-else :runs="visibleRuns" :label="t('dashboard.recommended_runs.title')" />
		</div>
	</div>
</template>
