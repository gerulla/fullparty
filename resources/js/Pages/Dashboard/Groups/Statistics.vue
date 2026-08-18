<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import GroupStatisticsBarChart from "@/components/Groups/Statistics/GroupStatisticsBarChart.vue";
import GroupStatisticsDonutChart from "@/components/Groups/Statistics/GroupStatisticsDonutChart.vue";
import GroupStatisticsLineChart from "@/components/Groups/Statistics/GroupStatisticsLineChart.vue";
import GroupStatisticsMetricCard from "@/components/Groups/Statistics/GroupStatisticsMetricCard.vue";
import GroupStatisticsStackedBars from "@/components/Groups/Statistics/GroupStatisticsStackedBars.vue";
import PageHeader from "@/components/PageHeader.vue";
import type {
	GroupStatisticsApplicationMonth,
	GroupStatisticsCacheMeta,
	GroupStatisticsGroup,
	GroupStatisticsLoadoutItem,
	GroupStatisticsPayload,
} from "@/Types/GroupStatistics";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";

const props = defineProps<{
	group: GroupStatisticsGroup
	statistics: GroupStatisticsPayload
	statistics_cache: GroupStatisticsCacheMeta
}>();

const { t, locale } = useI18n();
const nowMs = ref(Date.now());
const isRefreshing = ref(false);
let cooldownTimer: number | undefined;

const numberFormatter = computed(() => new Intl.NumberFormat(locale.value));
const decimalFormatter = computed(() => new Intl.NumberFormat(locale.value, {
	minimumFractionDigits: 1,
	maximumFractionDigits: 1,
}));

const formatNumber = (value: number) => numberFormatter.value.format(value);
const formatDecimal = (value: number) => decimalFormatter.value.format(value);
const formatDay = (date: string) => createDateTimeFormatter(locale.value, {
	month: "short",
	day: "numeric",
}).format(new Date(date));
const formatMonth = (date: string) => createDateTimeFormatter(locale.value, {
	month: "short",
}).format(new Date(date));
const formatCacheDateTime = (date: string | null) => {
	if (!date) {
		return t("groups.statistics.cache.unknown");
	}

	return createDateTimeFormatter(locale.value, {
		dateStyle: "short",
		timeStyle: "short",
	}).format(new Date(date));
};
const formatCooldown = (seconds: number) => {
	const minutes = Math.floor(seconds / 60);
	const remainingSeconds = seconds % 60;

	return `${minutes}:${remainingSeconds.toString().padStart(2, "0")}`;
};

const metricCards = computed(() => [
	{
		key: "total_runs",
		label: t("groups.statistics.metrics.total_runs.label"),
		value: formatNumber(props.statistics.summary.total_runs),
		description: t("groups.statistics.metrics.total_runs.description", {
			count: props.statistics.summary.runs_with_participants,
		}),
		icon: "i-lucide-calendar-days",
		tone: "primary" as const,
	},
	{
		key: "total_participants",
		label: t("groups.statistics.metrics.total_participants.label"),
		value: formatNumber(props.statistics.summary.total_participants),
		description: t("groups.statistics.metrics.total_participants.description"),
		icon: "i-lucide-users",
		tone: "success" as const,
	},
	{
		key: "unique_participants",
		label: t("groups.statistics.metrics.unique_participants.label"),
		value: formatNumber(props.statistics.summary.unique_participants),
		description: t("groups.statistics.metrics.unique_participants.description"),
		icon: "i-lucide-user-round-check",
		tone: "info" as const,
	},
	{
		key: "average_participants",
		label: t("groups.statistics.metrics.average_participants.label"),
		value: formatDecimal(props.statistics.summary.average_participants_per_raid),
		description: t("groups.statistics.metrics.average_participants.description"),
		icon: "i-lucide-divide",
		tone: "warning" as const,
	},
	{
		key: "active_players",
		label: t("groups.statistics.metrics.active_players.label"),
		value: formatNumber(props.statistics.summary.active_players_past_month),
		description: t("groups.statistics.metrics.active_players.description"),
		icon: "i-lucide-activity",
		tone: "neutral" as const,
	},
]);

const participationTrendItems = computed(() => props.statistics.participation_trend.map((point) => ({
	key: point.date,
	label: formatDay(point.date),
	value: point.participant_count,
	secondaryLabel: t("groups.statistics.participation.tooltip", {
		date: formatDay(point.date),
		participants: point.participant_count,
		runs: point.run_count,
	}),
})));

const applicationStatusLabel = (key: string) => t(`groups.statistics.applications.statuses.${key}`);
const applicationDistributionSegments = computed(() => props.statistics.applications.distribution.map((item) => ({
	key: item.key,
	label: applicationStatusLabel(item.key),
	value: item.count,
	percent: item.percent,
})));
const dailyApplicationItems = computed(() => props.statistics.applications.daily_submissions.map((day) => ({
	key: day.date,
	label: createDateTimeFormatter(locale.value, {
		weekday: "short",
		month: "short",
		day: "numeric",
	}).format(new Date(day.date)),
	value: day.count,
	secondaryLabel: t("groups.statistics.applications.daily_tooltip", {
		date: formatDay(day.date),
		count: day.count,
	}),
})));
const applicationVolumeSegments = computed(() => props.statistics.applications.distribution.map((item) => ({
	key: item.key,
	label: applicationStatusLabel(item.key),
})));
const applicationVolumeItems = computed(() => props.statistics.applications.volume_by_month.map((month: GroupStatisticsApplicationMonth) => ({
	key: month.month,
	label: formatMonth(month.month),
	total: month.total,
	statuses: month.statuses,
})));

const toDistributionSegments = (items: GroupStatisticsLoadoutItem[]) => items.map((item) => ({
		key: item.key,
		label: item.label,
		value: item.count,
		percent: item.percent,
	}));

const classDistributionSegments = computed(() => toDistributionSegments(props.statistics.classes.distribution));
const phantomDistributionSegments = computed(() => toDistributionSegments(props.statistics.phantom_jobs.distribution));
const classTrendLabels = computed(() => props.statistics.classes.monthly_trend.months.map(formatMonth));
const phantomTrendLabels = computed(() => props.statistics.phantom_jobs.monthly_trend.months.map(formatMonth));

const assignmentCountLabel = (count: number) => t("groups.statistics.loadouts.assignment_count", { count });
const refreshAvailableAtMs = computed(() => (
	props.statistics_cache.refresh_available_at
		? new Date(props.statistics_cache.refresh_available_at).getTime()
		: null
));
const refreshCooldownRemainingSeconds = computed(() => {
	if (!refreshAvailableAtMs.value) {
		return 0;
	}

	return Math.max(0, Math.ceil((refreshAvailableAtMs.value - nowMs.value) / 1000));
});
const canRefreshStatistics = computed(() => props.statistics_cache.can_refresh || refreshCooldownRemainingSeconds.value === 0);
const refreshButtonLabel = computed(() => {
	if (isRefreshing.value) {
		return t("groups.statistics.cache.refreshing");
	}

	if (!canRefreshStatistics.value) {
		return t("groups.statistics.cache.cooldown", {
			time: formatCooldown(refreshCooldownRemainingSeconds.value),
		});
	}

	return t("groups.statistics.cache.refresh");
});
const refreshButtonTitle = computed(() => t("groups.statistics.cache.tooltip", {
	cached: formatCacheDateTime(props.statistics_cache.cached_at),
	expires: formatCacheDateTime(props.statistics_cache.expires_at),
}));

const refreshStatistics = () => {
	if (!canRefreshStatistics.value || isRefreshing.value) {
		return;
	}

	isRefreshing.value = true;

	router.post(route("groups.dashboard.statistics.refresh", props.group.slug), {}, {
		onFinish: () => {
			isRefreshing.value = false;
		},
	});
};

onMounted(() => {
	cooldownTimer = window.setInterval(() => {
		nowMs.value = Date.now();
	}, 1000);
});

onBeforeUnmount(() => {
	if (cooldownTimer !== undefined) {
		window.clearInterval(cooldownTimer);
	}
});
</script>

<template>
	<div class="w-full">
		<PageHeader
			:title="t('groups.statistics.title')"
			:subtitle="t('groups.statistics.subtitle', { group: group.name })"
		>
			<UButton
				color="neutral"
				variant="soft"
				icon="i-lucide-refresh-cw"
				:label="refreshButtonLabel"
				:title="refreshButtonTitle"
				:loading="isRefreshing"
				:disabled="!canRefreshStatistics || isRefreshing"
				@click="refreshStatistics"
			/>
		</PageHeader>

		<div class="mt-4 space-y-6">
			<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
				<GroupStatisticsMetricCard
					v-for="metric in metricCards"
					:key="metric.key"
					:label="metric.label"
					:value="metric.value"
					:description="metric.description"
					:icon="metric.icon"
					:tone="metric.tone"
				/>
			</div>

			<div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,0.95fr)]">
				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
					<template #header>
						<div class="flex items-start justify-between gap-4">
							<div>
								<p class="font-semibold text-md">
									{{ t("groups.statistics.participation.title") }}
								</p>
								<p class="text-sm text-muted">
									{{ t("groups.statistics.participation.subtitle") }}
								</p>
							</div>
							<UBadge
								color="neutral"
								variant="subtle"
								:label="t('groups.statistics.participation.badge')"
							/>
						</div>
					</template>

					<GroupStatisticsBarChart
						:items="participationTrendItems"
						:empty-title="t('groups.statistics.participation.empty_title')"
						:empty-description="t('groups.statistics.participation.empty_description')"
					/>
				</UCard>

				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
					<template #header>
						<div>
							<p class="font-semibold text-md">
								{{ t("groups.statistics.applications.distribution_title") }}
							</p>
							<p class="text-sm text-muted">
								{{ t("groups.statistics.applications.distribution_subtitle") }}
							</p>
						</div>
					</template>

					<GroupStatisticsDonutChart
						:segments="applicationDistributionSegments"
						:total-label="t('groups.statistics.applications.total_label')"
						:empty-title="t('groups.statistics.applications.empty_title')"
						:empty-description="t('groups.statistics.applications.empty_description')"
					/>
				</UCard>
			</div>

			<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
				<template #header>
					<div class="flex items-start justify-between gap-4">
						<div>
							<p class="font-semibold text-md">
								{{ t("groups.statistics.applications.daily_title") }}
							</p>
							<p class="text-sm text-muted">
								{{ t("groups.statistics.applications.daily_subtitle") }}
							</p>
						</div>
						<UBadge
							color="neutral"
							variant="subtle"
							:label="t('groups.statistics.applications.daily_badge')"
						/>
					</div>
				</template>

				<GroupStatisticsBarChart
					:items="dailyApplicationItems"
					:empty-title="t('groups.statistics.applications.daily_empty_title')"
					:empty-description="t('groups.statistics.applications.daily_empty_description')"
				/>
			</UCard>

			<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
				<template #header>
					<div>
						<p class="font-semibold text-md">
							{{ t("groups.statistics.applications.volume_title") }}
						</p>
						<p class="text-sm text-muted">
							{{ t("groups.statistics.applications.volume_subtitle") }}
						</p>
					</div>
				</template>

				<GroupStatisticsStackedBars
					:items="applicationVolumeItems"
					:segments="applicationVolumeSegments"
					:empty-title="t('groups.statistics.applications.empty_title')"
					:empty-description="t('groups.statistics.applications.empty_description')"
				/>
			</UCard>

			<div class="grid gap-6 xl:grid-cols-2">
				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
					<template #header>
						<div>
							<p class="font-semibold text-md">
								{{ t("groups.statistics.classes.title") }}
							</p>
							<p class="text-sm text-muted">
								{{ t("groups.statistics.classes.subtitle") }}
							</p>
						</div>
					</template>

					<GroupStatisticsDonutChart
						:segments="classDistributionSegments"
						:total-label="t('groups.statistics.loadouts.total_label')"
						:empty-title="t('groups.statistics.classes.empty_title')"
						:empty-description="t('groups.statistics.classes.empty_description')"
					/>
				</UCard>

				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
					<template #header>
						<div>
							<p class="font-semibold text-md">
								{{ t("groups.statistics.phantom_jobs.title") }}
							</p>
							<p class="text-sm text-muted">
								{{ t("groups.statistics.phantom_jobs.subtitle") }}
							</p>
						</div>
					</template>

					<GroupStatisticsDonutChart
						:segments="phantomDistributionSegments"
						:total-label="t('groups.statistics.loadouts.total_label')"
						:empty-title="t('groups.statistics.phantom_jobs.empty_title')"
						:empty-description="t('groups.statistics.phantom_jobs.empty_description')"
					/>
				</UCard>
			</div>

			<div class="grid gap-6 xl:grid-cols-2">
				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
					<template #header>
						<div>
							<p class="font-semibold text-md">
								{{ t("groups.statistics.classes.trend_title") }}
							</p>
							<p class="text-sm text-muted">
								{{ t("groups.statistics.classes.trend_subtitle") }}
							</p>
						</div>
					</template>

					<GroupStatisticsLineChart
						:months="statistics.classes.monthly_trend.months"
						:labels="classTrendLabels"
						:series="statistics.classes.monthly_trend.series"
						:empty-title="t('groups.statistics.classes.empty_title')"
						:empty-description="t('groups.statistics.classes.empty_description')"
						:count-label="assignmentCountLabel"
					/>
				</UCard>

				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-4 sm:p-4' }">
					<template #header>
						<div>
							<p class="font-semibold text-md">
								{{ t("groups.statistics.phantom_jobs.trend_title") }}
							</p>
							<p class="text-sm text-muted">
								{{ t("groups.statistics.phantom_jobs.trend_subtitle") }}
							</p>
						</div>
					</template>

					<GroupStatisticsLineChart
						:months="statistics.phantom_jobs.monthly_trend.months"
						:labels="phantomTrendLabels"
						:series="statistics.phantom_jobs.monthly_trend.series"
						:empty-title="t('groups.statistics.phantom_jobs.empty_title')"
						:empty-description="t('groups.statistics.phantom_jobs.empty_description')"
						:count-label="assignmentCountLabel"
					/>
				</UCard>
			</div>
		</div>
	</div>
</template>
