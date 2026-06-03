<script setup lang="ts">
import type { ApexOptions } from "apexcharts"
import type {
	DashboardAccountCompletion,
	DashboardAccountCompletionItem,
	DashboardHomeBanner,
	DashboardHomeBannerDetails,
	DashboardHomeProfileOptions,
	DashboardProfile,
} from "@/Types/Dashboard"
import { router, useForm } from "@inertiajs/vue3"
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue"
import VueApexCharts from "vue3-apexcharts"
import { useI18n } from "vue-i18n"
import { useToast } from "@nuxt/ui/composables"
import { route } from "ziggy-js"
import { createDateTimeFormatter } from "@/utils/dateTimeFormat"
import { groupProfilePictureAccept, validateGroupProfilePictureFile } from "@/utils/groupProfilePictureValidation"
import { localizedValue } from "@/utils/localizedValue"
import { sanitizeMultilineText, sanitizeMultilineTextForInput } from "@/utils/textInputSanitizer"

const { t, locale } = useI18n()
const toast = useToast()
const homeProfileImageMaxBytes = 5 * 1024 * 1024
const homeProfileDescriptionMaxLength = 255
const defaultDisplayJobOptionValue = "__default__"
const accountCompletionAnimationDuration = 1450
const accountCompletionItemCheckDelay = 500
const accountCompletionFinalCheckHold = 350
const accountCompletionPanelPopDuration = 420

const props = defineProps<{
	profile: DashboardProfile
	homeProfileOptions: DashboardHomeProfileOptions
	homeBanner: DashboardHomeBanner
	homeBannerDetails?: DashboardHomeBannerDetails
	homeAccountCompletion?: DashboardAccountCompletion
}>()

const isHomeProfileModalOpen = ref(false)
const isAccountChecklistOpen = ref(true)
const hasShownCompletionDebugToast = ref(false)
const accountCompletionPanel = ref<HTMLElement | null>(null)
const accountCompletionBadgeTarget = ref<HTMLElement | null>(null)
const accountCompletionFlightStyle = ref<Record<string, string>>({})
const isAccountCompletionChecklistCelebrating = ref(false)
const isAccountCompletionListPopping = ref(false)
const isAccountCompletionAnimating = ref(false)
const hasAccountCompletionAnimationSettled = ref(false)
const hasStartedAccountCompletionAnimation = ref(false)
const animatedAccountCompletionCount = ref(0)
const backgroundImagePreviewUrl = ref<string | null>(props.profile.home_profile.background_image_url ?? null)
const backgroundImageInput = ref<HTMLInputElement | null>(null)
let accountCompletionAnimationTimer: number | null = null
let accountCompletionChecklistTimer: number | null = null
let accountCompletionPopTimer: number | null = null

const homeProfileForm = useForm({
	display_character_class_id: props.profile.home_profile.display_character_class_id?.toString() ?? defaultDisplayJobOptionValue,
	description: props.profile.home_profile.description ?? "",
	background_image: null as File | null,
	reset_background_image: false,
})

const selectedCharacter = computed(() => props.homeBanner.character)
const selectedDisplayJob = computed(() => props.homeBanner.character.display_job)
const hasShowoffJob = computed(() => selectedCharacter.value.id !== null && selectedDisplayJob.value !== null)
const displayJobName = computed(() => selectedDisplayJob.value?.name || t("dashboard.character_panel.no_display_job"))
const displayJobIconUrl = computed(() => selectedDisplayJob.value?.icon_url || selectedDisplayJob.value?.flaticon_url || null)
const displayJobLevel = computed(() => props.homeBanner.character.display_job_level)
const characterLocation = computed(() => [selectedCharacter.value.world, selectedCharacter.value.datacenter].filter(Boolean).join(" - "))
const biography = computed(() => props.profile.home_profile.description || t("dashboard.character_panel.hero_quote"))
const profileHeroStyle = computed(() => ({
	backgroundImage: `url("${props.profile.home_profile.background_image_url || "/default-homepage-bg.jpg"}")`,
	backgroundPosition: "center center",
}))

const characterClassOptions = computed(() => [
	{
		label: t("dashboard.character_panel.customization.display_job_default"),
		value: defaultDisplayJobOptionValue,
	},
	...props.homeProfileOptions.character_classes.map((characterClass) => ({
		label: characterClass.name,
		value: characterClass.id.toString(),
	})),
])

const homeProfileDescription = computed({
	get: () => homeProfileForm.description,
	set: (value: string | number | undefined) => {
		homeProfileForm.description = sanitizeMultilineTextForInput(String(value ?? ""))
		homeProfileForm.clearErrors("description")
	},
})
const homeProfileDescriptionLength = computed(() => homeProfileForm.description.length)
const homeProfileDescriptionCounter = computed(() => `${homeProfileDescriptionLength.value}/${homeProfileDescriptionMaxLength}`)
const canResetBackgroundImage = computed(() => Boolean(
	(backgroundImagePreviewUrl.value || props.profile.home_profile.background_image_url)
		&& !homeProfileForm.reset_background_image,
))

const revokePreviewUrl = (url: string | null) => {
	if (!url || !url.startsWith("blob:")) {
		return
	}

	URL.revokeObjectURL(url)
}

const replacePreviewUrl = (url: string | null) => {
	revokePreviewUrl(backgroundImagePreviewUrl.value)
	backgroundImagePreviewUrl.value = url
}

const openHomeProfileModal = () => {
	homeProfileForm.clearErrors()
	homeProfileForm.display_character_class_id = props.profile.home_profile.display_character_class_id?.toString() ?? defaultDisplayJobOptionValue
	homeProfileForm.description = props.profile.home_profile.description ?? ""
	homeProfileForm.background_image = null
	homeProfileForm.reset_background_image = false
	replacePreviewUrl(props.profile.home_profile.background_image_url ?? null)

	if (backgroundImageInput.value) {
		backgroundImageInput.value.value = ""
	}

	isHomeProfileModalOpen.value = true
}

const updateBackgroundImage = (event: Event) => {
	const target = event.target as HTMLInputElement
	const file = target.files?.[0] ?? null
	const fallbackUrl = homeProfileForm.reset_background_image
		? null
		: props.profile.home_profile.background_image_url ?? null

	homeProfileForm.clearErrors("background_image")
	homeProfileForm.background_image = file

	if (!file) {
		replacePreviewUrl(fallbackUrl)
		return
	}

	const imageValidation = validateGroupProfilePictureFile(file)

	if (!imageValidation.isValid) {
		homeProfileForm.background_image = null
		homeProfileForm.setError("background_image", t("dashboard.character_panel.customization.validation.image_invalid_format"))
		replacePreviewUrl(fallbackUrl)
		target.value = ""
		return
	}

	if (file.size > homeProfileImageMaxBytes) {
		homeProfileForm.background_image = null
		homeProfileForm.setError("background_image", t("dashboard.character_panel.customization.validation.image_too_large"))
		replacePreviewUrl(fallbackUrl)
		target.value = ""
		return
	}

	homeProfileForm.reset_background_image = false
	replacePreviewUrl(URL.createObjectURL(file))
}

const resetBackgroundImage = () => {
	homeProfileForm.clearErrors("background_image")
	homeProfileForm.background_image = null
	homeProfileForm.reset_background_image = true
	replacePreviewUrl(null)

	if (backgroundImageInput.value) {
		backgroundImageInput.value.value = ""
	}
}

const submitHomeProfile = () => {
	homeProfileForm
		.transform((data) => ({
			...data,
			display_character_class_id: data.display_character_class_id === defaultDisplayJobOptionValue
				? null
				: data.display_character_class_id,
			description: sanitizeMultilineText(data.description),
			reset_background_image: data.reset_background_image ? "1" : "0",
			_method: "put",
		}))
		.post(route("dashboard.profile.update"), {
			forceFormData: true,
			onSuccess: () => {
				isHomeProfileModalOpen.value = false
				homeProfileForm.background_image = null
				homeProfileForm.reset_background_image = false
				toast.add({
					title: t("general.success"),
					description: t("dashboard.character_panel.customization.updated"),
					color: "success",
					icon: "i-lucide-check",
				})
			},
		})
}

const isHomeBannerDetailsLoading = computed(() => props.homeBannerDetails === undefined)
const lastRunRecap = computed(() => props.homeBannerDetails?.last_run ?? null)
const lastRunActivityTypeName = computed(() => (
	localizedValue(lastRunRecap.value?.activity_type_name, locale.value, "en")
))
const lastRunActivityName = computed(() => (
	lastRunRecap.value?.activity_title
		|| lastRunActivityTypeName.value
		|| t("dashboard.character_panel.next_run.unknown_activity")
))
const lastRunProgress = computed(() => lastRunRecap.value?.progress ?? 0)
const lastRunProgressLabel = computed(() => (
	localizedValue(lastRunRecap.value?.progress_label, locale.value, "en")
		|| (lastRunProgress.value === 100
			? t("dashboard.character_panel.last_run.complete")
			: t("dashboard.character_panel.last_run.not_recorded"))
))
const lastRunCompletedAt = computed(() => {
	if (!lastRunRecap.value?.completed_at) {
		return t("dashboard.character_panel.last_run.not_recorded")
	}

	return createDateTimeFormatter(locale.value, {
		month: "short",
		day: "numeric",
		year: "numeric",
	}).format(new Date(lastRunRecap.value.completed_at))
})
const weekRangeFormatter = computed(() => new Intl.DateTimeFormat(locale.value, {
	month: "short",
	day: "numeric",
}))
const rawWeeklyRunParticipation = computed(() => props.homeBannerDetails?.weekly_participation ?? [])
const visibleWeeklyRunParticipation = computed(() => {
	const firstActiveWeekIndex = rawWeeklyRunParticipation.value.findIndex((week) => week.count > 0)

	return firstActiveWeekIndex === -1
		? []
		: rawWeeklyRunParticipation.value.slice(firstActiveWeekIndex)
})
const weeklyRunParticipation = computed(() => visibleWeeklyRunParticipation.value.map((week) => week.count))
const hasWeeklyRunParticipation = computed(() => weeklyRunParticipation.value.length > 0)
const weeklyRunParticipationRanges = computed(() => visibleWeeklyRunParticipation.value.map((week) => {
	const start = new Date(`${week.start}T00:00:00`)
	const end = new Date(`${week.end}T00:00:00`)

	return `${weekRangeFormatter.value.format(start)}-${weekRangeFormatter.value.format(end)}`
}))
const weeklyRunParticipationChartWidth = computed(() => {
	const pointCount = weeklyRunParticipation.value.length
	const width = Math.min(560, Math.max(180, pointCount * 46))

	return `min(${width}px, 100%)`
})

const nextRunWithinSixHours = computed(() => props.homeBannerDetails?.next_run ?? null)
const isAccountCompletionLoading = computed(() => props.homeAccountCompletion === undefined)
const accountChecklistItems = computed(() => props.homeAccountCompletion?.items ?? [])
const accountChecklistPercent = computed(() => props.homeAccountCompletion?.percent ?? 0)
const displayedAccountChecklistItems = computed(() => {
	if (!isAccountCompletionChecklistCelebrating.value) {
		return accountChecklistItems.value
	}

	return accountChecklistItems.value.map((item, index) => ({
		...item,
		is_complete: index < animatedAccountCompletionCount.value,
	}))
})
const displayedAccountChecklistPercent = computed(() => {
	if (!isAccountCompletionChecklistCelebrating.value) {
		return accountChecklistPercent.value
	}

	const total = accountChecklistItems.value.length

	return total > 0
		? Math.round((animatedAccountCompletionCount.value / total) * 100)
		: 0
})
const isAccountSetupComplete = computed(() => {
	const completion = props.homeAccountCompletion

	return Boolean(completion && completion.total_count > 0 && completion.completed_count === completion.total_count)
})
const shouldPlayAccountCompletionAnimation = computed(() => {
	return props.homeAccountCompletion?.should_celebrate_completion === true
})
const showAccountCompletionBadge = computed(() => (
	isAccountSetupComplete.value
		&& hasAccountCompletionAnimationSettled.value
		&& !isAccountCompletionChecklistCelebrating.value
		&& !isAccountCompletionAnimating.value
))
const shouldRenderAccountCompletionBadgeTarget = computed(() => (
	!isAccountCompletionLoading.value
		&& (isAccountSetupComplete.value || isAccountCompletionAnimating.value)
))
const shouldShowAccountCompletionPanel = computed(() => (
	!isAccountCompletionLoading.value
		&& !showAccountCompletionBadge.value
))
const accountCompletionTaskRoutes: Record<string, string> = {
	verified_character: route("account.characters"),
	primary_character: route("account.characters"),
	joined_group: route("groups.index"),
	connected_discord: route("discord-app.user.redirect"),
	notification_preferences_reviewed: route("settings"),
}

const accountCompletionTaskRoute = (item: DashboardAccountCompletionItem) => {
	if (isAccountSetupComplete.value || isAccountCompletionChecklistCelebrating.value) {
		return null
	}

	return accountCompletionTaskRoutes[item.key] ?? null
}

const openAccountCompletionTask = (item: DashboardAccountCompletionItem) => {
	const target = accountCompletionTaskRoute(item)

	if (!target) {
		return
	}

	router.get(target)
}

const clearAccountCompletionTimers = () => {
	if (accountCompletionAnimationTimer !== null) {
		window.clearTimeout(accountCompletionAnimationTimer)
		accountCompletionAnimationTimer = null
	}

	if (accountCompletionChecklistTimer !== null) {
		window.clearTimeout(accountCompletionChecklistTimer)
		accountCompletionChecklistTimer = null
	}

	if (accountCompletionPopTimer !== null) {
		window.clearTimeout(accountCompletionPopTimer)
		accountCompletionPopTimer = null
	}
}

const finishAccountCompletionAnimation = () => {
	if (!isAccountCompletionAnimating.value) {
		return
	}

	clearAccountCompletionTimers()

	isAccountCompletionAnimating.value = false
	isAccountCompletionChecklistCelebrating.value = false
	isAccountCompletionListPopping.value = false
	hasAccountCompletionAnimationSettled.value = true
}

const playAccountCompletionAnimation = () => {
	const panel = accountCompletionPanel.value
	const target = accountCompletionBadgeTarget.value

	if (!panel || !target) {
		hasAccountCompletionAnimationSettled.value = true
		return
	}

	const panelRect = panel.getBoundingClientRect()
	const targetRect = target.getBoundingClientRect()
	const targetSize = Math.max(targetRect.width || 20, 20)
	const circleLeft = panelRect.left + (panelRect.width / 2) - (targetSize / 2)
	const circleTop = panelRect.top + (panelRect.height / 2) - (targetSize / 2)

	accountCompletionFlightStyle.value = {
		"--from-left": `${panelRect.left}px`,
		"--from-top": `${panelRect.top}px`,
		"--from-width": `${panelRect.width}px`,
		"--from-height": `${panelRect.height}px`,
		"--circle-left": `${circleLeft}px`,
		"--circle-top": `${circleTop}px`,
		"--swoosh-left": `${targetRect.left + 28}px`,
		"--swoosh-top": `${Math.min(circleTop, targetRect.top) - 34}px`,
		"--to-left": `${targetRect.left}px`,
		"--to-top": `${targetRect.top}px`,
		"--to-size": `${targetSize}px`,
	}

	isAccountCompletionAnimating.value = true

	accountCompletionAnimationTimer = window.setTimeout(
		finishAccountCompletionAnimation,
		accountCompletionAnimationDuration + 200,
	)
}

const beginAccountCompletionFlight = () => {
	void nextTick(() => {
		window.requestAnimationFrame(playAccountCompletionAnimation)
	})
}

const finishAccountCompletionChecklist = () => {
	accountCompletionChecklistTimer = window.setTimeout(() => {
		isAccountCompletionListPopping.value = true

		accountCompletionPopTimer = window.setTimeout(
			beginAccountCompletionFlight,
			accountCompletionPanelPopDuration,
		)
	}, accountCompletionFinalCheckHold)
}

const checkNextAccountCompletionItem = () => {
	if (animatedAccountCompletionCount.value >= accountChecklistItems.value.length) {
		finishAccountCompletionChecklist()
		return
	}

	accountCompletionChecklistTimer = window.setTimeout(() => {
		animatedAccountCompletionCount.value += 1
		checkNextAccountCompletionItem()
	}, accountCompletionItemCheckDelay)
}

const startAccountCompletionChecklistAnimation = () => {
	clearAccountCompletionTimers()
	isAccountChecklistOpen.value = true
	isAccountCompletionChecklistCelebrating.value = true
	isAccountCompletionListPopping.value = false
	isAccountCompletionAnimating.value = false
	hasAccountCompletionAnimationSettled.value = false
	animatedAccountCompletionCount.value = 0
	checkNextAccountCompletionItem()
}

watch(
	() => [
		isAccountCompletionLoading.value,
		isAccountSetupComplete.value,
		shouldPlayAccountCompletionAnimation.value,
	] as const,
	([isLoading, isComplete, shouldPlay]) => {
		if (isLoading || hasStartedAccountCompletionAnimation.value) {
			return
		}

		if (!isComplete) {
			hasAccountCompletionAnimationSettled.value = false
			return
		}

		hasStartedAccountCompletionAnimation.value = true

		if (!shouldPlay) {
			hasAccountCompletionAnimationSettled.value = true
			return
		}

		startAccountCompletionChecklistAnimation()
	},
	{ immediate: true, flush: "post" },
)

watch(
	() => props.homeAccountCompletion?.should_celebrate_completion,
	(shouldCelebrateCompletion) => {
		if (!shouldCelebrateCompletion || hasShownCompletionDebugToast.value) {
			return
		}

		hasShownCompletionDebugToast.value = true
		toast.add({
			title: "Account completion debug",
			description: "Completed all account setup steps for the first time.",
			color: "success",
			icon: "i-lucide-party-popper",
		})
	},
	{ immediate: true },
)

const nextRunActivityName = computed(() => {
	const nextRun = nextRunWithinSixHours.value

	if (!nextRun) {
		return ""
	}

	return localizedValue(nextRun.activity_type_name, locale.value, "en")
		|| nextRun.activity_title
		|| t("dashboard.character_panel.next_run.unknown_activity")
})

const nextRunDateTime = computed(() => {
	const nextRun = nextRunWithinSixHours.value

	if (!nextRun?.starts_at) {
		return ""
	}

	return createDateTimeFormatter(locale.value, {
		month: "short",
		day: "numeric",
		hour: "2-digit",
		minute: "2-digit",
	}).format(new Date(nextRun.starts_at))
})

const openNextRun = () => {
	const nextRun = nextRunWithinSixHours.value

	if (!nextRun?.activity_id || !nextRun.group.slug) {
		return
	}

	router.get(route("groups.activities.overview", {
		group: nextRun.group.slug,
		activity: nextRun.activity_id,
	}))
}

const runCountLabel = (count: number) => t(
	count === 1
		? "dashboard.character_panel.participation.run_singular"
		: "dashboard.character_panel.participation.run_plural",
)

const performanceChartSeries = computed(() => [
	{
		name: t("dashboard.character_panel.participation.runs"),
		data: weeklyRunParticipation.value,
	},
])

const performanceChartOptions = computed<ApexOptions>(() => ({
	chart: {
		type: "area",
		background: "transparent",
		sparkline: {
			enabled: true,
		},
		toolbar: {
			show: false,
		},
		zoom: {
			enabled: false,
		},
	},
	colors: ["#c084fc"],
	dataLabels: {
		enabled: false,
	},
	fill: {
		type: "gradient",
		gradient: {
			type: "vertical",
			opacityFrom: 0.45,
			opacityTo: 0,
			stops: [0, 70, 100],
		},
	},
	grid: {
		show: false,
		padding: {
			top: 4,
			right: 0,
			bottom: 0,
			left: 0,
		},
	},
	legend: {
		show: false,
	},
	markers: {
		size: 3,
		colors: ["#080a14"],
		strokeColors: "#d8b4fe",
		strokeWidth: 2,
		hover: {
			size: 4,
		},
	},
	stroke: {
		curve: "smooth",
		lineCap: "round",
		width: 2,
	},
	tooltip: {
		theme: "dark",
		custom: ({ dataPointIndex, series }) => {
			const count = Math.round(series[0]?.[dataPointIndex] ?? 0)
			const range = weeklyRunParticipationRanges.value[dataPointIndex] ?? ""

			return `<div class="px-3 py-2 text-sm text-neutral-100">${t("dashboard.character_panel.participation.week_tooltip", {
				range,
				count,
				runLabel: runCountLabel(count),
			})}</div>`
		},
	},
	xaxis: {
		axisBorder: {
			show: false,
		},
		axisTicks: {
			show: false,
		},
		crosshairs: {
			show: false,
		},
		labels: {
			show: false,
		},
		tooltip: {
			enabled: false,
		},
	},
	yaxis: {
		show: false,
		min: 0,
	},
}))

const openCharacters = () => {
	router.get(route("account.characters"))
}

const openRunDiscovery = () => {
	router.get(route("dashboard.runs.index"))
}

onBeforeUnmount(() => {
	clearAccountCompletionTimers()

	revokePreviewUrl(backgroundImagePreviewUrl.value)
})
</script>

<template>
	<UCard
		class="overflow-hidden border-default bg-brand-950 "
		:ui="{ body: 'p-0 sm:p-0', root:'ring-transparent' }"
	>
		<section
			class="relative overflow-hidden bg-cover bg-no-repeat "
			:style="profileHeroStyle"
		>
			<div class="absolute inset-0 bg-linear-to-tl from-transparent  to-neutral-950 " />
			<div class="absolute inset-0 bg-linear-to-l from-transparent  to-neutral-950 to-95%" />

			<div class="relative flex flex-col justify-between">
				<div class="flex flex-col justify-between gap-8 lg:flex-row">
					<div class="max-w-xl p-10">
						<p class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-300">
							{{ t('dashboard.character_panel.main_character') }}
						</p>

						<div class="mt-4 flex min-w-0 items-start">
							<div class="relative inline-flex min-w-0 max-w-full pr-6">
								<h1 class="truncate text-4xl font-semibold tracking-tight text-white sm:text-5xl">
									{{ selectedCharacter.name }}
								</h1>
								<span
									v-if="shouldRenderAccountCompletionBadgeTarget"
									ref="accountCompletionBadgeTarget"
									class="absolute -right-1 top-0 flex h-5 w-5 items-center justify-center"
								>
									<UTooltip
										v-if="showAccountCompletionBadge"
										:text="t('dashboard.account_status.completed_tooltip')"
									>
										<span class="flex h-5 w-5 items-center justify-center rounded-full border border-emerald-300/35 bg-emerald-400/15 text-emerald-200 shadow-lg shadow-emerald-950/30">
											<UIcon name="i-lucide-check" class="h-3 w-3" />
										</span>
									</UTooltip>
								</span>
							</div>
						</div>

						<p v-if="characterLocation" class="mt-3 text-sm text-neutral-300 sm:text-base">
							{{ characterLocation }}
						</p>

						<div
							v-if="hasShowoffJob"
							class="mt-7 flex flex-wrap items-center gap-4"
						>
							<div class="flex h-16 w-16 items-center justify-center text-violet-100 shadow-lg shadow-violet-950/30">
								<img
									v-if="displayJobIconUrl"
									:src="displayJobIconUrl"
									:alt="displayJobName"
									class="w-full h-full object-contain"
								>
								<UIcon v-else name="i-lucide-sparkles" class="h-8 w-8" />
							</div>

							<div>
								<div class="flex flex-wrap items-center gap-2">
									<UBadge
										color="primary"
										variant="soft"
										icon="i-lucide-star"
										:label="displayJobName"
										class="bg-violet-500/20 text-violet-100 ring-violet-300/20"
									/>
								</div>
								<div class="mt-2 flex flex-wrap items-center gap-3 text-sm font-semibold text-white">
									<span v-if="displayJobLevel !== null">{{ t('dashboard.character_panel.level', { level: displayJobLevel }) }}</span>
								</div>
							</div>
						</div>

						<div class="mt-6 flex max-w-md items-start gap-2">
							<p class="max-h-48 overflow-y-auto pr-2 text-base leading-7 text-neutral-300">
								{{ biography }}
							</p>
							<UButton
								color="neutral"
								variant="ghost"
								icon="i-lucide-pencil"
								size="sm"
								:aria-label="t('dashboard.character_panel.customization.open')"
								class="shrink-0 text-neutral-300 hover:text-white"
								@click="openHomeProfileModal"
							/>
						</div>

						<UButton
							class="mt-7"
							color="neutral"
							variant="outline"
							trailing-icon="i-lucide-arrow-up-right"
							:label="t('dashboard.actions.characters')"
							@click="openCharacters"
						/>
					</div>

					<div class="m-6 flex w-auto max-w-xs flex-col gap-3 self-start">
						<button
							v-if="nextRunWithinSixHours"
							type="button"
							class="border border-white/10 bg-neutral-950/45 p-4 text-left shadow-xl shadow-neutral-950/30 backdrop-blur transition hover:border-violet-300/40 hover:bg-neutral-950/60"
							@click="openNextRun"
						>
							<p class="text-sm font-medium text-neutral-200">
								{{ t('dashboard.character_panel.next_run.title') }}
							</p>
							<div class="mt-3 flex items-center justify-between gap-3">
								<p class="min-w-0 text-sm text-neutral-300">
									<span class="font-semibold text-white">{{ nextRunActivityName }}</span>
									<span class="text-neutral-500"> - </span>
									<span>{{ nextRunDateTime }}</span>
								</p>
								<UIcon name="i-lucide-arrow-up-right" class="h-4 w-4 shrink-0 text-violet-200" />
							</div>
						</button>

						<div
							v-if="shouldShowAccountCompletionPanel"
							ref="accountCompletionPanel"
							class="border border-white/10 bg-neutral-950/45 shadow-xl shadow-neutral-950/30 backdrop-blur transition duration-300"
							:class="{
								'pointer-events-none opacity-0': isAccountCompletionAnimating,
								'account-completion-panel--pop border-emerald-300/25': isAccountCompletionListPopping,
							}"
						>
							<button
								type="button"
								class="flex w-full items-center justify-between gap-3 p-4 text-left"
								:aria-expanded="isAccountChecklistOpen"
								@click="isAccountChecklistOpen = !isAccountChecklistOpen"
							>
								<div class="min-w-0">
									<p class="text-sm font-medium text-neutral-200">
										{{ t('dashboard.account_status.title') }}
									</p>
									<p class="mt-1 text-xs text-neutral-400">
										{{ t('dashboard.account_status.percent_ready', { percent: displayedAccountChecklistPercent }) }}
									</p>
								</div>
								<UIcon
									name="i-lucide-chevron-down"
									class="h-4 w-4 shrink-0 text-violet-200 transition-transform"
									:class="{ 'rotate-180': isAccountChecklistOpen }"
								/>
							</button>

							<div
								v-if="isAccountChecklistOpen"
								class="border-t border-white/10 px-4 pb-4 pt-3"
							>
								<div class="mb-3 h-1 overflow-hidden bg-white/10">
									<div
										class="h-full bg-violet-400 transition-all"
										:style="{ width: `${displayedAccountChecklistPercent}%` }"
									/>
								</div>

								<div class="flex flex-col gap-2">
									<button
										v-for="item in displayedAccountChecklistItems"
										:key="item.key"
										type="button"
										class="flex w-full items-center gap-3 px-1 py-1 text-left text-sm transition"
										:class="accountCompletionTaskRoute(item)
											? 'cursor-pointer hover:bg-white/5'
											: 'cursor-default'"
										:disabled="!accountCompletionTaskRoute(item)"
										@click="openAccountCompletionTask(item)"
									>
										<UIcon
											:name="item.is_complete ? 'i-lucide-circle-check' : 'i-lucide-circle'"
											class="h-4 w-4 shrink-0"
											:class="[
												item.is_complete ? 'text-emerald-300' : 'text-neutral-500',
												isAccountCompletionChecklistCelebrating && item.is_complete ? 'account-completion-item-check' : '',
											]"
										/>
										<div class="min-w-0 flex-1">
											<p
												class="truncate"
												:class="item.is_complete ? 'text-neutral-300' : 'text-white'"
											>
												{{ t(`dashboard.account_status.items.${item.key}.title`) }}
											</p>
										</div>
										<span class="shrink-0 text-[0.65rem] uppercase tracking-[0.12em] text-neutral-500">
											{{ t(`dashboard.account_status.${item.priority}`) }}
										</span>
										<UIcon
											v-if="accountCompletionTaskRoute(item)"
											name="i-lucide-arrow-up-right"
											class="h-3.5 w-3.5 shrink-0 text-violet-200"
										/>
									</button>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div
					v-if="isAccountCompletionAnimating"
					class="account-completion-flight"
					:style="accountCompletionFlightStyle"
					@animationend.self="finishAccountCompletionAnimation"
				>
					<div class="account-completion-flight__panel">
						<div class="flex items-start justify-between gap-3">
							<div class="min-w-0">
								<p class="text-sm font-medium text-neutral-100">
									{{ t('dashboard.account_status.title') }}
								</p>
								<p class="mt-1 text-xs text-neutral-400">
									{{ t('dashboard.account_status.percent_ready', { percent: displayedAccountChecklistPercent }) }}
								</p>
							</div>
							<UIcon name="i-lucide-chevron-down" class="h-4 w-4 shrink-0 rotate-180 text-violet-200" />
						</div>

						<div class="mt-3 h-1 overflow-hidden bg-white/10">
							<div
								class="h-full bg-violet-400"
								:style="{ width: `${displayedAccountChecklistPercent}%` }"
							/>
						</div>

						<div class="mt-3 flex flex-col gap-2">
							<div
								v-for="item in displayedAccountChecklistItems"
								:key="`flight-${item.key}`"
								class="flex items-center gap-3 text-sm"
							>
								<UIcon
									:name="item.is_complete ? 'i-lucide-circle-check' : 'i-lucide-circle'"
									class="h-4 w-4 shrink-0"
									:class="item.is_complete ? 'text-emerald-300' : 'text-neutral-500'"
								/>
								<p
									class="truncate"
									:class="item.is_complete ? 'text-neutral-300' : 'text-white'"
								>
									{{ t(`dashboard.account_status.items.${item.key}.title`) }}
								</p>
							</div>
						</div>
					</div>
					<div class="account-completion-flight__check">
						<UIcon name="i-lucide-check" class="h-3 w-3" />
					</div>
				</div>

				<div class="bg-linear-to-b from-transparent to-neutral-950 to-60% px-2 py-5 shadow-2xl shadow-neutral-950/30 sm:px-3 md:px-4 xl:px-10 ">
					<div class="flex flex-col gap-5 lg:flex-row lg:items-center">
						<div class="h-full flex flex-row items-center border border-white/10 bg-neutral-950/40 px-2 shadow-2xl sm:px-3 xl:px-4">
							<div
								v-if="isHomeBannerDetailsLoading"
								class="flex min-w-0 flex-1 items-center gap-4"
							>
								<USkeleton class="h-20 w-20 shrink-0" />
								<div class="min-w-0 flex-1 space-y-2">
									<USkeleton class="h-3 w-28" />
									<USkeleton class="h-5 w-44" />
									<USkeleton class="h-4 w-32" />
									<USkeleton class="h-4 w-36" />
								</div>
							</div>
							<div
								v-else-if="lastRunRecap"
								class="flex min-w-0 flex-1 items-center gap-4"
							>
								<div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden border border-violet-300/20 bg-violet-950/50">
									<img
										v-if="lastRunRecap.activity_icon_url"
										:src="lastRunRecap.activity_icon_url"
										:alt="lastRunActivityName"
										class="h-full w-full object-cover"
									>
									<UIcon v-else :name="lastRunRecap.activity_icon" class="h-10 w-10 text-violet-200" />
								</div>
								<div class="min-w-0">
									<p class="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-400">
										{{ t('dashboard.character_panel.last_run.title') }}
									</p>
									<p class="mt-2 truncate text-base font-semibold text-white">
										{{ lastRunActivityName }}
									</p>
									<p v-if="lastRunActivityTypeName" class="mt-1 text-sm text-neutral-300">
										{{ lastRunActivityTypeName }}
									</p>
									<p class="mt-1 text-sm text-neutral-400">
										{{ t('dashboard.character_panel.last_run.completed_at', { date: lastRunCompletedAt }) }}
									</p>
								</div>
							</div>
							<div v-else class="flex min-w-0 flex-1 items-center gap-4">
								<div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden border border-violet-300/20 bg-violet-950/50">
									<UIcon name="i-lucide-calendar-check-2" class="h-10 w-10 text-violet-200" />
								</div>
								<div class="min-w-0">
									<p class="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-400">
										{{ t('dashboard.character_panel.last_run.title') }}
									</p>
									<p class="mt-2 text-base font-semibold text-white">
										{{ t('dashboard.character_panel.last_run.empty') }}
									</p>
								</div>
							</div>

							<div
								v-if="isHomeBannerDetailsLoading"
								class="grid min-w-0 gap-px overflow-hidden sm:grid-cols-3"
							>
								<div
									v-for="index in 3"
									:key="index"
									class="px-3 py-4"
								>
									<USkeleton class="mx-auto h-4 w-24" />
									<USkeleton class="mx-auto mt-4 h-8 w-16" />
									<USkeleton class="mx-auto mt-3 h-3 w-20" />
								</div>
							</div>
							<div
								v-else-if="lastRunRecap"
								class="grid min-w-0 gap-px overflow-hidden sm:grid-cols-3 "
							>
								<div class=" px-3 py-4 flex flex-col items-center">
									<p class="text-sm text-neutral-400">{{ t('dashboard.character_panel.last_run.progress') }}</p>
									<p class="mt-2 text-3xl font-semibold text-violet-300">{{ lastRunProgress }}%</p>
									<UProgress class="mt-2" color="primary" :model-value="lastRunProgress" />
									<p class="mt-2 text-sm text-center text-neutral-400">{{ lastRunProgressLabel }}</p>
								</div>
								<div class="px-3 py-4 flex flex-col items-center gap-2">
									<p class="text-sm text-neutral-400">{{ t('dashboard.character_panel.last_run.class_played') }}</p>
									<div class="mt-3 flex flex-col items-center gap-2">
										<img
											v-if="lastRunRecap.class_icon_url"
											:src="lastRunRecap.class_icon_url"
											:alt="lastRunRecap.class_name || t('dashboard.character_panel.last_run.no_class')"
											class="h-10 w-10 object-contain"
										>
										<p class="text-xl font-semibold leading-tight text-white">
											{{ lastRunRecap.class_name || t('dashboard.character_panel.last_run.no_class') }}
										</p>
									</div>
								</div>
								<div class="px-3 py-4 flex flex-col items-center gap-2">
									<p class="text-sm text-neutral-400">{{ t('dashboard.character_panel.last_run.phantom_job') }}</p>
									<div class="mt-3 flex flex-col items-center gap-2">
										<img
											v-if="lastRunRecap.phantom_job_icon_url"
											:src="lastRunRecap.phantom_job_icon_url"
											:alt="lastRunRecap.phantom_job_name ?? t('dashboard.character_panel.last_run.phantom_job')"
											class="h-10 w-10 object-contain"
										>
										<p class="text-xl font-semibold leading-tight text-white">
											{{ lastRunRecap.phantom_job_name || t('dashboard.character_panel.last_run.no_phantom_job') }}
										</p>
									</div>
								</div>
							</div>
						</div>

						<div class="min-w-[220px] flex-1">
							<div class="mb-4 flex justify-end">
								<UButton
									color="primary"
									variant="link"
									trailing-icon="i-lucide-arrow-right"
									:label="t('dashboard.character_panel.view_all_runs')"
									class="px-0 text-violet-200 hover:text-violet-100"
									@click="openRunDiscovery"
								/>
							</div>
							<div
								v-if="isHomeBannerDetailsLoading"
								class="relative h-20 overflow-hidden"
							>
								<USkeleton class="h-full w-full" />
							</div>
							<div v-else-if="hasWeeklyRunParticipation" class="flex justify-end">
								<div
									class="relative h-20 max-w-full overflow-hidden"
									:style="{ width: weeklyRunParticipationChartWidth }"
								>
									<VueApexCharts
										type="area"
										height="80"
										width="100%"
										:options="performanceChartOptions"
										:series="performanceChartSeries"
									/>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</section>
	</UCard>

	<UModal
		v-model:open="isHomeProfileModalOpen"
		:title="t('dashboard.character_panel.customization.title')"
		:description="t('dashboard.character_panel.customization.subtitle')"
		:ui="{ content: 'rounded-sm', header: 'border-0' }"
	>
		<template #body>
			<form class="flex flex-col gap-4" @submit.prevent="submitHomeProfile">
				<UFormField
					:label="t('dashboard.character_panel.customization.fields.display_job.label')"
					:error="homeProfileForm.errors.display_character_class_id"
				>
					<USelect
						v-model="homeProfileForm.display_character_class_id"
						:items="characterClassOptions"
						:placeholder="t('dashboard.character_panel.customization.fields.display_job.placeholder')"
						class="w-full"
					/>
				</UFormField>

				<UFormField
					:label="t('dashboard.character_panel.customization.fields.description.label')"
					:error="homeProfileForm.errors.description"
					:hint="homeProfileDescriptionCounter"
				>
					<UTextarea
						v-model="homeProfileDescription"
						:rows="4"
						:maxlength="homeProfileDescriptionMaxLength"
						:placeholder="t('dashboard.character_panel.customization.fields.description.placeholder')"
						class="w-full"
					/>
				</UFormField>

				<UFormField
					:label="t('dashboard.character_panel.customization.fields.background_image.label')"
					:error="homeProfileForm.errors.background_image"
				>
					<label class="flex cursor-pointer items-center justify-between gap-3 border border-white/10 bg-neutral-950/40 px-4 py-3 text-sm text-neutral-200 transition hover:border-violet-300/40">
						<span class="inline-flex min-w-0 items-center gap-2">
							<UIcon name="i-lucide-image-up" class="h-4 w-4 shrink-0 text-violet-200" />
							<span class="truncate">
								{{ homeProfileForm.background_image?.name || t('dashboard.character_panel.customization.fields.background_image.placeholder') }}
							</span>
						</span>
						<input
							ref="backgroundImageInput"
							type="file"
							class="sr-only"
							:accept="groupProfilePictureAccept"
							@change="updateBackgroundImage"
						>
					</label>
					<div class="mt-2 flex justify-end">
						<UButton
							type="button"
							color="neutral"
							variant="ghost"
							size="sm"
							icon="i-lucide-rotate-ccw"
							:label="t('dashboard.character_panel.customization.fields.background_image.reset')"
							:disabled="!canResetBackgroundImage"
							@click="resetBackgroundImage"
						/>
					</div>
				</UFormField>

				<div
					v-if="backgroundImagePreviewUrl"
					class="h-28 overflow-hidden border border-white/10 bg-neutral-950/40"
				>
					<img
						:src="backgroundImagePreviewUrl"
						:alt="t('dashboard.character_panel.customization.fields.background_image.preview_alt')"
						class="h-full w-full object-cover"
					>
				</div>

				<div class="mt-2 flex justify-end gap-2">
					<UButton
						type="button"
						color="neutral"
						variant="ghost"
						:label="t('general.cancel')"
						@click="isHomeProfileModalOpen = false"
					/>
					<UButton
						type="submit"
						color="primary"
						:label="t('general.save')"
						:loading="homeProfileForm.processing"
					/>
				</div>
			</form>
		</template>
	</UModal>
</template>

<style scoped>
.account-completion-panel--pop {
	transform: scale(1.04);
	box-shadow: 0 22px 48px rgb(16 185 129 / 0.18);
}

.account-completion-item-check {
	animation: account-completion-item-check 360ms cubic-bezier(0.22, 1, 0.36, 1);
}

.account-completion-flight {
	position: fixed;
	left: var(--from-left);
	top: var(--from-top);
	z-index: 80;
	display: flex;
	align-items: center;
	justify-content: center;
	width: var(--from-width);
	height: var(--from-height);
	overflow: hidden;
	border: 1px solid rgb(255 255 255 / 0.14);
	background: rgb(10 10 18 / 0.82);
	box-shadow: 0 22px 46px rgb(0 0 0 / 0.34);
	backdrop-filter: blur(14px);
	will-change: left, top, width, height, border-radius, transform, box-shadow;
	animation: account-completion-flight 1450ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
}

.account-completion-flight__panel {
	width: 100%;
	padding: 1rem;
	animation: account-completion-panel-fade 1450ms ease forwards;
}

.account-completion-flight__check {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	color: rgb(167 243 208);
	opacity: 0;
	animation: account-completion-check-reveal 1450ms ease forwards;
}

@keyframes account-completion-item-check {
	0% {
		opacity: 0.55;
		transform: scale(0.72);
	}

	62% {
		opacity: 1;
		transform: scale(1.22);
	}

	100% {
		opacity: 1;
		transform: scale(1);
	}
}

@keyframes account-completion-flight {
	0% {
		left: var(--from-left);
		top: var(--from-top);
		width: var(--from-width);
		height: var(--from-height);
		border-radius: 0;
		transform: scale(1);
	}

	32% {
		left: var(--circle-left);
		top: var(--circle-top);
		width: var(--to-size);
		height: var(--to-size);
		border-radius: 9999px;
		transform: scale(1);
	}

	68% {
		left: var(--swoosh-left);
		top: var(--swoosh-top);
		width: var(--to-size);
		height: var(--to-size);
		border-radius: 9999px;
		transform: scale(0.9);
		box-shadow: 0 18px 36px rgb(126 34 206 / 0.36);
	}

	100% {
		left: var(--to-left);
		top: var(--to-top);
		width: var(--to-size);
		height: var(--to-size);
		border-radius: 9999px;
		transform: scale(1);
		box-shadow: 0 16px 34px rgb(6 95 70 / 0.28);
	}
}

@keyframes account-completion-panel-fade {
	0%,
	18% {
		opacity: 1;
		transform: scale(1);
	}

	32%,
	100% {
		opacity: 0;
		transform: scale(0.82);
	}
}

@keyframes account-completion-check-reveal {
	0%,
	42% {
		opacity: 0;
		transform: scale(0.6) rotate(-18deg);
	}

	70% {
		opacity: 1;
		transform: scale(1.12) rotate(0deg);
	}

	100% {
		opacity: 1;
		transform: scale(1) rotate(0deg);
	}
}

</style>
