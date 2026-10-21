<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { breakpointsTailwind, useBreakpoints, useNow } from '@vueuse/core'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import HomeEmptyState from '@/components/Home/HomeEmptyState.vue'
import { useAppearance } from '@/composables/useAppearance'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import { localizedValue } from '@/utils/localizedValue'
import type { V2HomeApplication, V2HomeCharacter, V2HomeParticipationStatus } from '../../Types/V2Home'
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'

const { t, locale } = useI18n()
const { isDark } = useAppearance()
const { formatDateTime } = useDateTimeFormat()
const now = useNow({ interval: 30_000 })
const badgeVariant = computed(() => isDark.value ? 'outline' : 'solid')
const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('md')
const isMobileOrTablet = breakpoints.smaller('xl')
const applicationLimit = computed(() => isMobile.value ? 2 : isMobileOrTablet.value ? 3 : 4)

const props = defineProps<{
	applications?: V2HomeApplication[]
	history?: V2HomeApplication[]
	character?: V2HomeCharacter | null
	loading?: boolean
	historyLoading?: boolean
}>()

const forCharacter = (applications: V2HomeApplication[] | undefined) => (
	applications?.filter(application => application.selected_character_id === props.character?.id).slice(0, applicationLimit.value) ?? []
)
const tabs = computed(() => [
	{ label: t('dashboard.runs_tabs.upcoming'), value: 'upcoming', slot: 'upcoming', applications: forCharacter(props.applications), loading: props.loading ?? props.applications === undefined },
	{ label: t('dashboard.runs_tabs.history'), value: 'history', slot: 'history', applications: forCharacter(props.history), loading: props.historyLoading ?? props.history === undefined },
])

const statusColors = {
	approved: 'success',
	pending: 'warning',
	on_bench: 'info',
	declined: 'error',
	withdrawn: 'error',
	cancelled: 'error',
	assigned: 'brand',
	checked_in: 'success',
	late: 'warning',
	missing: 'error',
	filled_in: 'info',
} as const satisfies Record<V2HomeParticipationStatus, string>

const runTitle = (application: V2HomeApplication) => (
	application.run.title || localizedValue(application.run.activity_type.name, locale.value)
)
const relativeStart = (application: V2HomeApplication) => formatRelativeTime(
	application.run.starts_at, locale.value, t('dashboard.character_applications.now'),
	t('dashboard.labels.not_available'), now.value.getTime(),
)
const fullStart = (application: V2HomeApplication) => formatDateTime(new Date(application.run.starts_at), {
	dateStyle: 'full', timeStyle: 'short',
})
</script>

<template>
	<UTabs
		:items="tabs"
		default-value="upcoming"
		:unmount-on-hide="false"
		variant="link"
		class="panel-shape panel-style"
		:ui="{
			root: 'items-stretch gap-4',
			list: 'items-center gap-4 border-0 bg-transparent p-0 mb-0',
			trigger: 'item',
			indicator: 'hidden',
		}"
	>
		<template #list-trailing>
			<Link :href="route('account.runs.index')" class="link ml-auto shrink-0">
				{{ t('dashboard.character_applications.view_all') }}
			</Link>
		</template>

		<template v-for="tab in tabs" :key="tab.value" #[tab.slot]>
			<div class="run-box" :class="tab.value === 'upcoming' ? 'upcoming-runs' : 'run-history'" :aria-busy="tab.loading">
				<template v-if="tab.loading">
					<p class="sr-only" role="status">{{ t('dashboard.character_applications.loading') }}</p>
					<div v-for="index in applicationLimit" :key="index" aria-hidden="true" class="flex w-full min-w-0 items-center gap-2 p-2">
						<USkeleton class="hidden size-16 shrink-0 rounded-lg bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none md:block xl:hidden 2xl:block" />
						<div class="flex min-w-0 flex-1 flex-col gap-2">
							<div class="flex items-start justify-between gap-4">
								<div class="flex min-w-0 flex-1 flex-col gap-1.5 py-0.5">
									<USkeleton class="h-3.5 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" :class="index % 2 ? 'w-full' : 'w-4/5'" />
									<USkeleton v-if="index % 2" class="h-3.5 w-2/3 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
								</div>
								<USkeleton class="h-5 w-16 shrink-0 rounded-md bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
							</div>
							<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
								<USkeleton class="h-3 w-2/5 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
								<USkeleton class="h-3 w-1/3 rounded bg-elevation-3 dark:bg-elevation-5 motion-reduce:animate-none" />
							</div>
						</div>
					</div>
				</template>
				<HomeEmptyState v-else-if="!tab.applications.length"
					:title="t(`dashboard.empty_states.${tab.value}.title`)"
					:description="tab.value === 'history' ? t('dashboard.empty_states.history.description') : undefined"
					:action-label="t('dashboard.empty_states.find_runs')"
					:action-to="route('dashboard.runs.index')" />
				<component
					v-for="application in tab.loading ? [] : tab.applications"
					:key="application.id"
					:is="application.can_view_run === false ? 'div' : Link"
					:href="application.can_view_run === false ? undefined : application.run.href"
					class="run">
					<div class="flex w-full min-w-0 flex-row items-center gap-2 p-2">
						<img v-if="application.run.activity_type.small_image_url" :src="application.run.activity_type.small_image_url" alt="" class="hidden rounded-lg w-16 h-16 shrink-0 object-fill md:block xl:hidden 2xl:block">
						<div v-else class="hidden rounded-lg w-16 h-16 shrink-0 bg-muted items-center justify-center md:flex xl:hidden 2xl:flex" aria-hidden="true"><UIcon name="i-lucide-calendar-days" class="size-6 text-muted" /></div>
						<div class="flex min-w-0 flex-1 flex-col items-center justify-stretch gap-2">
							<div class="w-full flex flex-row items-start justify-between gap-4">
								<p class="text-sm min-w-0 break-words">{{ runTitle(application) }}</p>
								<UBadge :variant="badgeVariant" :color="statusColors[application.display_status]" class="shrink-0">{{ t(`dashboard.character_applications.statuses.${application.display_status}`) }}</UBadge>
							</div>
							<div class="w-full flex flex-row flex-wrap items-start justify-between gap-x-4 gap-y-1">
								<p class="text-muted text-xs">{{ t('dashboard.character_applications.host', { name: application.run.group.name }) }}</p>
								<time :datetime="application.run.starts_at" :title="fullStart(application)" class="text-xs text-muted">{{ tab.value === 'upcoming' ? t('dashboard.character_applications.starts', { time: relativeStart(application) }) : relativeStart(application) }}</time>
							</div>
						</div>
					</div>
				</component>
			</div>
		</template>
	</UTabs>
</template>

<style scoped>
@reference '../../../css/app.css';
.run-box {
	@apply flex flex-col gap-y-4;
}
.run {
	@apply flex flex-row items-center gap-2 rounded-md justify-between hover:scale-105 cursor-pointer transition-all duration-100;
	@apply dark:hover:bg-elevation-5 hover:bg-elevation-2
}
.panel-shape {
	@apply flex flex-col w-full gap-y-4 p-4;
}

.panel-style {
	@apply bg-elevation-1 dark:bg-elevation-3 shadow-md rounded-lg transition-all duration-100;

	/*DarkMode*/
	@apply dark:bg-elevation-3 dark:border-y dark:border-t-elevation-7 dark:border-b-elevation-2;
}

:deep(.item) {
	@apply p-0 rounded-none text-base font-normal text-muted transition-all cursor-pointer;
}

:deep(.item[data-state='inactive']:hover) {
	@apply scale-105 text-brand-700 dark:text-brand ;
}

:deep(.item[data-state='active']) {
	@apply font-semibold underline underline-offset-10 text-default;
}
.link {
	@apply text-sm font-medium text-brand hover:text-highlighted hover:underline focus-visible:underline transition-colors;
}
</style>
