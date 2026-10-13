<script lang="ts" setup>
import { Link } from '@inertiajs/vue3'
import { useNow } from '@vueuse/core'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import HomeEmptyState from '@/components/Home/HomeEmptyState.vue'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { V2HomeGroup } from '../../Types/V2Home'

const props = defineProps<{
	groups?: V2HomeGroup[]
	loading?: boolean
}>()
const { t, locale } = useI18n()
const now = useNow({ interval: 30_000 })
const groups = computed(() => props.groups?.slice(0, 3) ?? [])
const loading = computed(() => props.loading ?? props.groups === undefined)
const activityLabel = (group: V2HomeGroup) => group.last_activity_at
	? t('dashboard.group_panel.activity', { time: formatRelativeTime(group.last_activity_at, locale.value,
		t('dashboard.labels.just_now'), t('dashboard.labels.not_available'), now.value.getTime()) })
	: t('dashboard.group_panel.no_activity')
</script>

<template>
	<div class="panel-shape panel-style">
		<div class="flex flex-row gap-4 items-center justify-between">
			<div class="flex flex-row gap-4">
				<p class="item">{{ t('dashboard.home_sections.groups.title') }}</p>
			</div>
			<div>
				<Link :href="route('groups.index')" class="link">
					{{ t('dashboard.group_panel.view_all') }}
				</Link>
			</div>
		</div>
		<div class="group-box" :aria-busy="loading">
			<template v-if="loading">
				<p class="sr-only" role="status">{{ t('dashboard.group_panel.loading') }}</p>
				<div v-for="index in 3" :key="index" aria-hidden="true" class="group">
					<USkeleton class="size-12 shrink-0 rounded-lg bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
					<div class="flex min-w-0 flex-1 items-center justify-between gap-2">
						<div class="flex min-w-0 flex-1 flex-col gap-2">
							<USkeleton class="h-4 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" :class="index === 2 ? 'w-3/4' : 'w-5/6'" />
							<USkeleton class="h-3 w-2/3 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
						</div>
						<div class="flex shrink-0 items-center gap-2">
							<USkeleton v-for="action in 3" :key="action" class="size-8 rounded-md bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
						</div>
					</div>
				</div>
			</template>
			<HomeEmptyState v-else-if="!groups.length"
				:title="t('dashboard.empty_states.groups.title')"
				:description="t('dashboard.empty_states.groups.description')"
				:action-label="t('dashboard.empty_states.explore_groups')"
				:action-to="route('groups.index')" />
			<div v-for="g in loading ? [] : groups" :key="g.id" class="group">
				<img v-if="g.profile_picture_url" :src="g.profile_picture_url" alt="" class="rounded-lg w-12 h-12 shrink-0 object-fill">
				<div v-else class="rounded-lg w-12 h-12 shrink-0 bg-muted flex items-center justify-center" aria-hidden="true"><UIcon name="i-lucide-users" class="size-6 text-muted" /></div>
				<div class="w-full min-w-0 flex flex-row items-center justify-between gap-2">
					<div class="w-full min-w-0 flex flex-col items-start justify-center">
						<p class="text-md min-w-0 break-words">{{ g.name }}</p>
						<p class="text-muted text-xs">{{ activityLabel(g) }}</p>
					</div>
					<div class="flex shrink-0 flex-row items-center gap-x-2 gap-y-1 opacity-90 dark:opacity-75">
						<UButton :to="g.href" external target="_blank" rel="noopener noreferrer" color="neutral" variant="ghost" icon="i-lucide-external-link"
							:aria-label="t('dashboard.group_panel.open_homepage', { name: g.name })" :title="t('dashboard.group_panel.open_homepage', { name: g.name })" />
						<UButton :to="g.runs_href" color="neutral" variant="ghost" icon="i-lucide-calendar"
							:aria-label="t('dashboard.group_panel.open_runs', { name: g.name })" :title="t('dashboard.group_panel.open_runs', { name: g.name })" />
						<UButton v-if="g.can_manage && g.settings_href" :to="g.settings_href" color="neutral" variant="ghost" icon="i-lucide-settings"
							:aria-label="t('dashboard.group_panel.open_settings', { name: g.name })" :title="t('dashboard.group_panel.open_settings', { name: g.name })" />
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
@reference '../../../css/app.css';
.group {
	@apply w-full flex flex-row gap-4 items-center justify-between;
}
.group-box {
	@apply flex flex-col gap-y-4;
}
.panel-shape {
	@apply flex flex-col w-full gap-y-4 p-4;
}

.panel-style {
	@apply bg-elevation-1 dark:bg-elevation-3 shadow-md rounded-lg transition-all duration-100;

	/*DarkMode*/
	@apply dark:bg-elevation-3 dark:border-y dark:border-t-elevation-7 dark:border-b-elevation-2;
}

.item {
	@apply font-semibold transition-all
}
.link {
	@apply text-sm font-medium text-brand hover:text-highlighted hover:underline focus-visible:underline transition-colors
}
</style>
