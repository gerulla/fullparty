<script setup lang="ts">
import type {
	DashboardActivityOverview,
	DashboardActivityOverviewApplication,
	DashboardActivityOverviewGroup,
	DashboardActivityOverviewRun,
} from "@/Types/Dashboard"
import type { NotificationRecord, NotificationTranslator } from "@/Types/Notifications"
import { router, usePage } from "@inertiajs/vue3"
import { computed } from "vue"
import { useI18n } from "vue-i18n"
import { route } from "ziggy-js"
import { createRelativeTimeFormatter } from "@/utils/dateTimeFormat"
import { localizedValue } from "@/utils/localizedValue"
import {
	formatNotificationTime,
	resolveNotificationMeta,
	resolveNotificationTitle,
} from "@/utils/notificationPresentation"
import { useDateTimeFormat } from '@/composables/useDateTimeFormat'

const { formatDateTime } = useDateTimeFormat()

const props = defineProps<{
	overview?: DashboardActivityOverview
}>()

const { t, locale } = useI18n()
const page = usePage()

const fallbackLocale = computed(() => String(page.props.locale?.fallback ?? "en"))
const isOverviewLoading = computed(() => props.overview === undefined)
const userRuns = computed(() => props.overview?.runs ?? [])
const applications = computed(() => props.overview?.applications ?? [])
const recentGroups = computed(() => props.overview?.groups.slice(0, 3) ?? [])
const recentGroupActivities = computed(() => props.overview?.notifications.slice(0, 20) ?? [])
const skeletonRows = [1, 2, 3]
const skeletonTimelineRows = [1, 2, 3, 4, 5]

const groupAccentClasses = [
	"from-violet-500 to-fuchsia-500",
	"from-sky-500 to-cyan-400",
	"from-emerald-500 to-teal-400",
	"from-amber-400 to-orange-500",
	"from-rose-500 to-pink-500",
]

const openApplications = () => {
	router.get(route("account.applications"))
}

const openHref = (href: string | null | undefined) => {
	if (!href) {
		return
	}

	router.get(href)
}

const activityTypeName = (name: Record<string, string | null | undefined> | null) => (
	localizedValue(name, locale.value, fallbackLocale.value)
	|| t("groups.activities.cards.unknown_type")
)

const runTitle = (run: DashboardActivityOverviewRun) => (
	run.title || activityTypeName(run.activity_type_name)
)

const applicationTitle = (application: DashboardActivityOverviewApplication) => (
	application.title || activityTypeName(application.activity_type_name)
)

const runStyleLabel = (runStyle: string | null) => {
	if (!runStyle) {
		return null
	}

	const key = `groups.activities.run_styles.${runStyle}`
	const label = t(key)

	return label === key ? runStyle : label
}

const difficultyLabel = (difficulty: string | null) => {
	if (!difficulty) {
		return null
	}

	const key = `groups.activities.difficulties.${difficulty}`
	const label = t(key)

	return label === key ? difficulty : label
}

const runMeta = (run: DashboardActivityOverviewRun) => [
	run.group.name,
	run.datacenter,
	runStyleLabel(run.run_style),
	difficultyLabel(run.difficulty),
].filter(Boolean).join(" - ")

const applicationMeta = (application: DashboardActivityOverviewApplication) => [
	application.group.name,
	application.activity.datacenter,
	runStyleLabel(application.activity.run_style),
	difficultyLabel(application.activity.difficulty),
].filter(Boolean).join(" - ")

const formatStartsAt = (value: string | null) => {
	if (!value) {
		return t("groups.activities.cards.no_time")
	}

	return formatDateTime(new Date(value), {
		weekday: "short",
		day: "numeric",
		month: "short",
		hour: "2-digit",
		minute: "2-digit",
	})
}

const formatRelativeTime = (value: string | null) => {
	if (!value) {
		return t("dashboard.labels.just_now")
	}

	const target = new Date(value).getTime()
	const diffMs = target - Date.now()

	if (diffMs > 0) {
		return t("dashboard.labels.just_now")
	}

	const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
		["year", 1000 * 60 * 60 * 24 * 365],
		["month", 1000 * 60 * 60 * 24 * 30],
		["day", 1000 * 60 * 60 * 24],
		["hour", 1000 * 60 * 60],
		["minute", 1000 * 60],
	]

	for (const [unit, threshold] of units) {
		if (Math.abs(diffMs) >= threshold) {
			return createRelativeTimeFormatter(locale.value, { numeric: "auto" }).format(
				Math.round(diffMs / threshold),
				unit,
			)
		}
	}

	return t("dashboard.labels.just_now")
}

const formatRunStartsIn = (value: string | null) => {
	if (!value) {
		return t("dashboard.labels.not_available")
	}

	const target = new Date(value).getTime()
	const diffMs = target - Date.now()

	if (diffMs <= 0) {
		return t("dashboard.home_sections.applications.run_started")
	}

	const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
		["year", 1000 * 60 * 60 * 24 * 365],
		["month", 1000 * 60 * 60 * 24 * 30],
		["day", 1000 * 60 * 60 * 24],
		["hour", 1000 * 60 * 60],
		["minute", 1000 * 60],
	]

	for (const [unit, threshold] of units) {
		if (diffMs >= threshold) {
			return t("dashboard.home_sections.applications.run_is_in", {
				time: createRelativeTimeFormatter(locale.value, { numeric: "auto" }).format(
					Math.round(diffMs / threshold),
					unit,
				),
			})
		}
	}

	return t("dashboard.home_sections.applications.run_is_in", {
		time: createRelativeTimeFormatter(locale.value, { numeric: "auto" }).format(1, "minute"),
	})
}

const groupInitials = (name: string) => name
	.split(/\s+/)
	.filter(Boolean)
	.slice(0, 2)
	.map((part) => part[0]?.toUpperCase())
	.join("") || "G"

const groupAccentClass = (group: DashboardActivityOverviewGroup) => (
	groupAccentClasses[group.id % groupAccentClasses.length]
)

const groupActivityLabel = (group: DashboardActivityOverviewGroup) => {
	const key = `dashboard.home_sections.groups.activity.${group.last_activity_key}`
	const label = t(key, { time: formatRelativeTime(group.last_activity_at) })

	return label === key ? formatRelativeTime(group.last_activity_at) : label
}

const notificationTitle = (notification: NotificationRecord) => (
	resolveNotificationTitle(notification, t as NotificationTranslator)
)

const notificationTime = (notification: NotificationRecord) => (
	formatNotificationTime(notification.created_at, locale.value, t as NotificationTranslator)
)

const notificationCategoryLabel = (notification: NotificationRecord) => {
	if (!notification.category) {
		return t("notifications.ui.fallback_title")
	}

	const key = `settings.notifications.${notification.category}`
	const label = t(key)

	return label === key ? notification.category.replaceAll("_", " ") : label
}
</script>

<template>
	<section class="mt-6 flex w-full flex-col gap-8 overflow-visible xl:h-[49rem] xl:flex-row xl:overflow-hidden">
		<div class="flex min-h-0 w-full flex-col gap-8 shadow-xl shadow-neutral-950/25 xl:h-full">
			<div class="flex h-[30rem] min-h-0 flex-col sm:h-[34rem] xl:h-auto xl:flex-1">
				<div class="mb-4 flex items-center justify-between gap-3">
					<div>
						<p class="text-sm font-semibold text-white">
							{{ t("dashboard.home_sections.runs.title") }}
						</p>
						<p class="mt-1 text-xs text-neutral-400">
							{{ t("dashboard.home_sections.runs.subtitle") }}
						</p>
					</div>
				</div>

				<div class="min-h-0 flex-1 overflow-y-auto pr-2">
					<div v-if="isOverviewLoading" class="flex flex-col gap-2">
						<div
							v-for="row in skeletonRows"
							:key="`run-skeleton-${row}`"
							class="flex min-w-0 items-center gap-4 border border-white/10 bg-elevated/25 p-3 shadow-xl"
						>
							<USkeleton class="h-16 w-16 shrink-0" />
							<div class="min-w-0 flex-1 space-y-2">
								<USkeleton class="h-5 w-2/3" />
								<USkeleton class="h-4 w-1/2" />
							</div>
							<div class="w-24 shrink-0 space-y-2">
								<USkeleton class="ml-auto h-5 w-20" />
								<USkeleton class="ml-auto h-3 w-24" />
							</div>
						</div>
					</div>

					<div v-else-if="userRuns.length > 0" class="flex flex-col gap-2">
						<button
							v-for="run in userRuns"
							:key="run.id"
							type="button"
							class="group flex min-w-0 items-center gap-4 border border-white/10 bg-elevated/25 p-3 text-left shadow-xl transition hover:border-violet-300/35 hover:bg-elevated/75"
							@click="openHref(run.href)"
						>
							<img
								v-if="run.image_url"
								:src="run.image_url"
								:alt="runTitle(run)"
								class="h-16 w-16 shrink-0 object-cover"
							>
							<div v-else class="flex h-16 w-16 shrink-0 items-center justify-center bg-white/[0.04] text-neutral-500">
								<UIcon name="i-lucide-swords" class="h-6 w-6" />
							</div>

							<div class="min-w-0 flex-1">
								<p class="truncate text-base font-semibold text-neutral-100">
									{{ runTitle(run) }}
								</p>
								<p class="mt-1 truncate text-sm text-neutral-400">
									{{ runMeta(run) }}
								</p>
							</div>

							<div class="shrink-0 text-right">
								<UBadge
									:color="run.status_color"
									variant="soft"
									:label="t(`dashboard.home_sections.runs.statuses.${run.status_key}`)"
								/>
								<p class="mt-2 text-xs text-neutral-400">
									{{ formatStartsAt(run.starts_at) }}
								</p>
							</div>

							<UIcon name="i-lucide-chevron-right" class="h-4 w-4 shrink-0 text-neutral-500 transition group-hover:text-violet-200" />
						</button>
					</div>

					<div v-else class="flex h-full items-center justify-center border border-white/10 bg-elevated/25 p-6 text-sm text-neutral-400">
						{{ t("dashboard.home_sections.runs.empty") }}
					</div>
				</div>
			</div>

			<div class="flex h-[30rem] min-h-0 flex-col sm:h-[34rem] xl:h-auto xl:flex-1">
				<div class="mb-4 flex items-center justify-between gap-3">
					<div>
						<p class="text-sm font-semibold text-white">
							{{ t("dashboard.home_sections.applications.title") }}
						</p>
						<p class="mt-1 text-xs text-neutral-400">
							{{ t("dashboard.home_sections.applications.subtitle") }}
						</p>
					</div>

					<UButton
						color="neutral"
						variant="ghost"
						size="sm"
						trailing-icon="i-lucide-chevron-right"
						:label="t('dashboard.home_sections.applications.view_all')"
						class="text-neutral-300 hover:text-white"
						@click="openApplications"
					/>
				</div>

				<div class="min-h-0 flex-1 overflow-y-auto pr-2">
					<div v-if="isOverviewLoading" class="flex flex-col gap-2">
						<div
							v-for="row in skeletonRows"
							:key="`application-skeleton-${row}`"
							class="flex min-w-0 items-center gap-4 border border-white/10 bg-elevated/25 p-3 shadow-xl"
						>
							<USkeleton class="h-16 w-16 shrink-0" />
							<div class="min-w-0 flex-1 space-y-2">
								<USkeleton class="h-5 w-3/4" />
								<USkeleton class="h-4 w-1/2" />
							</div>
							<USkeleton class="h-5 w-20 shrink-0" />
							<USkeleton class="h-4 w-10 shrink-0" />
						</div>
					</div>

					<div v-else-if="applications.length > 0" class="flex flex-col gap-2">
						<button
							v-for="application in applications"
							:key="application.id"
							type="button"
							class="group flex min-w-0 items-center gap-4 border border-white/10 bg-elevated/25 p-3 text-left shadow-xl transition hover:border-violet-300/35 hover:bg-elevated/75"
							@click="openHref(application.href)"
						>
							<img
								v-if="application.image_url"
								:src="application.image_url"
								:alt="applicationTitle(application)"
								class="h-16 w-16 shrink-0 object-cover"
							>
							<div v-else class="flex h-16 w-16 shrink-0 items-center justify-center bg-white/[0.04] text-neutral-500">
								<UIcon name="i-lucide-file-text" class="h-6 w-6" />
							</div>

							<div class="min-w-0 flex-1">
								<p class="truncate text-base font-semibold text-neutral-100">
									{{ applicationTitle(application) }}
								</p>
								<p class="mt-1 truncate text-sm text-neutral-400">
									{{ applicationMeta(application) }}
								</p>
							</div>

							<UBadge
								:color="application.status_color"
								variant="soft"
								:label="t(`dashboard.home_sections.applications.statuses.${application.status_key}`)"
								class="shrink-0"
							/>

							<p class="w-28 shrink-0 text-right text-sm text-neutral-400">
								{{ formatRunStartsIn(application.activity.starts_at) }}
							</p>

							<UIcon name="i-lucide-chevron-right" class="h-4 w-4 shrink-0 text-neutral-500 transition group-hover:text-violet-200" />
						</button>
					</div>

					<div v-else class="flex h-full items-center justify-center border border-white/10 bg-elevated/25 p-6 text-sm text-neutral-400">
						{{ t("dashboard.home_sections.applications.empty") }}
					</div>
				</div>
			</div>
		</div>

		<div class="flex min-h-0 w-full flex-col gap-8 xl:h-full">
			<div class="shrink-0">
				<div class="mb-4 flex items-center justify-between gap-3">
					<div>
						<p class="text-sm font-semibold text-white">
							{{ t("dashboard.home_sections.groups.title") }}
						</p>
						<p class="mt-1 text-xs text-neutral-400">
							{{ t("dashboard.home_sections.groups.subtitle") }}
						</p>
					</div>
				</div>

				<div v-if="isOverviewLoading" class="flex flex-col gap-2">
					<div
						v-for="row in skeletonRows"
						:key="`group-skeleton-${row}`"
						class="flex min-w-0 items-center gap-3 border border-white/10 bg-elevated/25 px-3 py-3 shadow-xl"
					>
						<USkeleton class="h-10 w-10 shrink-0" />
						<div class="min-w-0 flex-1 space-y-2">
							<USkeleton class="h-4 w-32" />
							<USkeleton class="h-3 w-44" />
						</div>
						<div class="flex shrink-0 items-center gap-1">
							<USkeleton class="h-7 w-7" />
							<USkeleton class="h-7 w-7" />
							<USkeleton class="h-7 w-7" />
						</div>
					</div>
				</div>

				<div v-else-if="recentGroups.length > 0" class="flex flex-col gap-2">
					<div
						v-for="group in recentGroups"
						:key="group.id"
						class="flex min-w-0 items-center gap-3 border border-white/10 bg-elevated/25 px-3 py-3 shadow-xl"
					>
						<img
							v-if="group.profile_picture_url"
							:src="group.profile_picture_url"
							:alt="group.name"
							class="h-10 w-10 shrink-0 object-cover"
						>
						<div
							v-else
							class="flex h-10 w-10 shrink-0 items-center justify-center bg-gradient-to-br text-xs font-bold text-white"
							:class="groupAccentClass(group)"
						>
							{{ groupInitials(group.name) }}
						</div>

						<div class="min-w-0 flex-1">
							<div class="flex min-w-0 items-center gap-2">
								<p class="truncate text-sm font-semibold text-neutral-100">
									{{ group.name }}
								</p>
								<UBadge
									color="neutral"
									variant="soft"
									size="xs"
									:label="t(`dashboard.home_sections.groups.roles.${group.role}`)"
									class="shrink-0"
								/>
							</div>
							<p class="mt-1 truncate text-xs text-neutral-400">
								{{ groupActivityLabel(group) }}
							</p>
						</div>

						<div class="flex shrink-0 items-center gap-1">
							<UTooltip :text="t('dashboard.home_sections.groups.actions.view_group')">
								<UButton
									color="neutral"
									variant="ghost"
									size="xs"
									icon="i-lucide-arrow-up-right"
									:aria-label="t('dashboard.home_sections.groups.actions.view_group')"
									@click.stop="openHref(group.urls.group)"
								/>
							</UTooltip>

							<UTooltip :text="t('dashboard.home_sections.groups.actions.view_runs')">
								<UButton
									color="neutral"
									variant="ghost"
									size="xs"
									icon="i-lucide-calendar-days"
									:aria-label="t('dashboard.home_sections.groups.actions.view_runs')"
									@click.stop="openHref(group.urls.runs)"
								/>
							</UTooltip>

							<UTooltip :text="t('dashboard.home_sections.groups.actions.settings')">
								<UButton
									color="neutral"
									variant="ghost"
									size="xs"
									icon="i-lucide-settings"
									:aria-label="t('dashboard.home_sections.groups.actions.settings')"
									:disabled="!group.urls.settings"
									@click.stop="openHref(group.urls.settings)"
								/>
							</UTooltip>
						</div>
					</div>
				</div>

				<div v-else class="border border-white/10 bg-elevated/25 p-6 text-center text-sm text-neutral-400">
					{{ t("dashboard.home_sections.groups.empty") }}
				</div>
			</div>

			<div class="flex h-[30rem] min-h-0 flex-col shadow-xl shadow-neutral-950/25 sm:h-[34rem] xl:h-auto xl:flex-1">
				<div class="mb-4 flex items-center justify-between gap-3">
					<div>
						<p class="text-sm font-semibold text-white">
							{{ t("dashboard.home_sections.activity.title") }}
						</p>
						<p class="mt-1 text-xs text-neutral-400">
							{{ t("dashboard.home_sections.activity.subtitle") }}
						</p>
					</div>
				</div>

				<div class="min-h-0 flex-1 overflow-y-auto pr-2">
					<div v-if="isOverviewLoading" class="flex flex-col gap-4 pl-1">
						<div
							v-for="row in skeletonTimelineRows"
							:key="`activity-skeleton-${row}`"
							class="flex gap-3"
						>
							<USkeleton class="size-8 shrink-0" />
							<div class="min-w-0 flex-1 space-y-2 pb-1">
								<div class="flex items-start justify-between gap-3">
									<div class="min-w-0 flex-1 space-y-2">
										<USkeleton class="h-4 w-2/3" />
										<USkeleton class="h-3 w-1/3" />
									</div>
									<USkeleton class="h-3 w-12 shrink-0" />
								</div>
							</div>
						</div>
					</div>

					<UTimeline
						v-else-if="recentGroupActivities.length > 0"
						:items="recentGroupActivities"
						size="sm"
						color="neutral"
						class="pl-1"
						:ui="{
							item: 'gap-x-3 pb-4 last:pb-0',
							container: 'pt-0',
							indicator: 'size-8 overflow-hidden border border-white/10 bg-white/[0.04]',
							wrapper: 'min-w-0',
						}"
					>
						<template #indicator="{ item }">
							<div class="flex size-8 items-center justify-center">
								<UIcon
									:name="resolveNotificationMeta(item).icon"
									:class="['h-4 w-4', resolveNotificationMeta(item).iconColor]"
								/>
							</div>
						</template>

						<template #wrapper="{ item }">
							<button
								type="button"
								class="min-w-0 pb-1 text-left"
								@click="openHref(item.open_url)"
							>
								<div class="flex min-w-0 items-start justify-between gap-3">
									<div class="min-w-0">
										<p class="truncate text-sm font-semibold text-neutral-100">
											{{ notificationTitle(item) }}
										</p>
										<p class="mt-1 truncate text-xs text-neutral-400">
											{{ notificationCategoryLabel(item) }}
										</p>
									</div>

									<p class="shrink-0 text-xs text-neutral-500">
										{{ notificationTime(item) }}
									</p>
								</div>
							</button>
						</template>
					</UTimeline>

					<div v-else class="flex h-full items-center justify-center border border-white/10 bg-elevated/25 p-6 text-sm text-neutral-400">
						{{ t("dashboard.home_sections.activity.empty") }}
					</div>
				</div>
			</div>
		</div>
	</section>
</template>
