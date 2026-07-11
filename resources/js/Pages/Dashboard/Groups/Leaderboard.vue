<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { route } from "ziggy-js";
import PageHeader from "@/components/PageHeader.vue";
import type {
	GroupLeaderboardCacheMeta,
	GroupLeaderboardCountEntry,
	GroupLeaderboardGroup,
	GroupLeaderboardHostSuccessEntry,
	GroupLeaderboardPayload,
	GroupLeaderboardPeriod,
} from "@/Types/GroupLeaderboard";
import { createDateTimeFormatter } from "@/utils/dateTimeFormat";

const props = defineProps<{
	group: GroupLeaderboardGroup
	leaderboard: GroupLeaderboardPayload
	leaderboard_cache: GroupLeaderboardCacheMeta
}>();

type LeaderboardLimit = '10' | '100' | 'all';

const { t, locale } = useI18n();
const nowMs = ref(Date.now());
const isRefreshing = ref(false);
const overallPeriod = ref<GroupLeaderboardPeriod>('all_time');
const raidLeaderPeriod = ref<GroupLeaderboardPeriod>('all_time');
const hostPeriod = ref<GroupLeaderboardPeriod>('all_time');
const overallLimit = ref<LeaderboardLimit>('10');
const raidLeaderLimit = ref<LeaderboardLimit>('10');
const hostLimit = ref<LeaderboardLimit>('10');
const overallRanking = ref<GroupLeaderboardCountEntry[]>(props.leaderboard.rankings.overall);
const raidLeaderRanking = ref<GroupLeaderboardCountEntry[]>(props.leaderboard.rankings.raid_leaders);
const hostRanking = ref<GroupLeaderboardCountEntry[]>(props.leaderboard.rankings.hosts);
const rankingCache = new Map<string, GroupLeaderboardCountEntry[]>([
	['overall:all_time:10', overallRanking.value],
	['raid_leaders:all_time:10', raidLeaderRanking.value],
	['hosts:all_time:10', hostRanking.value],
]);
const rankingRequestIds = {
	overall: 0,
	raid_leaders: 0,
	hosts: 0,
};
const rankingLoading = reactive({
	overall: false,
	raid_leaders: false,
	hosts: false,
});
const skeletonRows = Array.from({ length: 10 }, (_, index) => index);
let cooldownTimer: number | undefined;

const numberFormatter = computed(() => new Intl.NumberFormat(locale.value));
const percentFormatter = computed(() => new Intl.NumberFormat(locale.value, {
	minimumFractionDigits: 0,
	maximumFractionDigits: 1,
}));

const formatNumber = (value: number) => numberFormatter.value.format(value);
const formatPercent = (value: number) => `${percentFormatter.value.format(value)}%`;
const formatCacheDateTime = (date: string | null) => {
	if (!date) {
		return t("groups.leaderboard.cache.unknown");
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
const formatDate = (date: string | null) => {
	if (!date) {
		return t("groups.leaderboard.common.no_date");
	}

	return createDateTimeFormatter(locale.value, {
		day: "numeric",
		month: "short",
		year: "numeric",
	}).format(new Date(date));
};

const characterWorld = (entry: GroupLeaderboardCountEntry | GroupLeaderboardHostSuccessEntry) => [
	entry.character.world,
	entry.character.datacenter,
].filter(Boolean).join(" / ");

const topParticipant = computed(() => props.leaderboard.rankings.overall[0] ?? null);
const topHostSuccess = computed(() => props.leaderboard.rankings.host_success[0] ?? null);
const periodTabs = computed(() => [
	{ label: t('groups.leaderboard.periods.all_time'), value: 'all_time' },
	{ label: t('groups.leaderboard.periods.past_6_months'), value: 'past_6_months' },
	{ label: t('groups.leaderboard.periods.past_30_days'), value: 'past_30_days' },
]);
const limitTabs = computed(() => [
	{ label: t('groups.leaderboard.limits.top_10'), value: '10' },
	{ label: t('groups.leaderboard.limits.top_100'), value: '100' },
	{ label: t('groups.leaderboard.limits.all'), value: 'all' },
]);
const loadRanking = async (
	category: keyof typeof rankingRequestIds,
	period: GroupLeaderboardPeriod,
	limit: LeaderboardLimit,
	apply: (entries: GroupLeaderboardCountEntry[]) => void,
) => {
	const key = `${category}:${period}:${limit}`;
	const cached = rankingCache.get(key);
	const requestId = ++rankingRequestIds[category];

	if (cached) {
		rankingLoading[category] = false;
		apply(cached);
		return;
	}

	rankingLoading[category] = true;
	let entries: GroupLeaderboardCountEntry[];

	try {
		const response = await axios.get(route('groups.dashboard.leaderboard.ranking', props.group.slug), {
			params: { category, period, limit },
		});
		entries = response.data.data as GroupLeaderboardCountEntry[];
	} catch {
		if (requestId === rankingRequestIds[category]) {
			rankingLoading[category] = false;
		}

		return;
	}

	if (requestId !== rankingRequestIds[category]) {
		return;
	}

	rankingCache.set(key, entries);
	apply(entries);
	rankingLoading[category] = false;
};

watch([overallPeriod, overallLimit], ([period, limit]) => {
	void loadRanking('overall', period, limit, entries => { overallRanking.value = entries; });
});
watch([raidLeaderPeriod, raidLeaderLimit], ([period, limit]) => {
	void loadRanking('raid_leaders', period, limit, entries => { raidLeaderRanking.value = entries; });
});
watch([hostPeriod, hostLimit], ([period, limit]) => {
	void loadRanking('hosts', period, limit, entries => { hostRanking.value = entries; });
});
watch(() => props.leaderboard.generated_at, () => {
	rankingCache.clear();
	rankingCache.set('overall:all_time:10', props.leaderboard.rankings.overall);
	rankingCache.set('raid_leaders:all_time:10', props.leaderboard.rankings.raid_leaders);
	rankingCache.set('hosts:all_time:10', props.leaderboard.rankings.hosts);

	void loadRanking('overall', overallPeriod.value, overallLimit.value, entries => { overallRanking.value = entries; });
	void loadRanking('raid_leaders', raidLeaderPeriod.value, raidLeaderLimit.value, entries => { raidLeaderRanking.value = entries; });
	void loadRanking('hosts', hostPeriod.value, hostLimit.value, entries => { hostRanking.value = entries; });
});
const summaryCards = computed(() => [
	{
		key: "participations",
		label: t("groups.leaderboard.summary.total_participations"),
		value: formatNumber(props.leaderboard.summary.total_participations),
		icon: "i-lucide-users",
	},
	{
		key: "ranked",
		label: t("groups.leaderboard.summary.ranked_participants"),
		value: formatNumber(props.leaderboard.summary.ranked_participants),
		icon: "i-lucide-trophy",
	},
	{
		key: "raid_leaders",
		label: t("groups.leaderboard.summary.raid_leaders"),
		value: formatNumber(props.leaderboard.summary.raid_leader_participations),
		icon: "i-lucide-flag",
	},
	{
		key: "hosts",
		label: t("groups.leaderboard.summary.hosts"),
		value: formatNumber(props.leaderboard.summary.host_participations),
		icon: "i-lucide-handshake",
	},
]);

const rankTone = (rank: number) => {
	if (rank === 1) {
		return "text-warning";
	}

	if (rank === 2) {
		return "text-info";
	}

	if (rank === 3) {
		return "text-success";
	}

	return "text-muted";
};

const successTone = (rate: number) => {
	if (rate >= 80) {
		return "success";
	}

	if (rate >= 50) {
		return "warning";
	}

	return "error";
};

const hostSuccessFormulaHint = computed(() => t("groups.leaderboard.host_success.formula_hint"));
const refreshAvailableAtMs = computed(() => (
	props.leaderboard_cache.refresh_available_at
		? new Date(props.leaderboard_cache.refresh_available_at).getTime()
		: null
));
const refreshCooldownRemainingSeconds = computed(() => {
	if (!refreshAvailableAtMs.value) {
		return 0;
	}

	return Math.max(0, Math.ceil((refreshAvailableAtMs.value - nowMs.value) / 1000));
});
const canRefreshLeaderboard = computed(() => props.leaderboard_cache.can_refresh || refreshCooldownRemainingSeconds.value === 0);
const refreshButtonLabel = computed(() => {
	if (isRefreshing.value) {
		return t("groups.leaderboard.cache.refreshing");
	}

	if (!canRefreshLeaderboard.value) {
		return t("groups.leaderboard.cache.cooldown", {
			time: formatCooldown(refreshCooldownRemainingSeconds.value),
		});
	}

	return t("groups.leaderboard.cache.refresh");
});
const refreshButtonTitle = computed(() => t("groups.leaderboard.cache.tooltip", {
	cached: formatCacheDateTime(props.leaderboard_cache.cached_at),
	expires: formatCacheDateTime(props.leaderboard_cache.expires_at),
}));

const refreshLeaderboard = () => {
	if (!canRefreshLeaderboard.value || isRefreshing.value) {
		return;
	}

	isRefreshing.value = true;

	router.post(route("groups.dashboard.leaderboard.refresh", props.group.slug), {}, {
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
			:title="t('groups.leaderboard.title')"
			:subtitle="t('groups.leaderboard.subtitle', { group: group.name })"
		>
			<UButton
				color="neutral"
				variant="soft"
				icon="i-lucide-refresh-cw"
				:label="refreshButtonLabel"
				:title="refreshButtonTitle"
				:loading="isRefreshing"
				:disabled="!canRefreshLeaderboard || isRefreshing"
				@click="refreshLeaderboard"
			/>
		</PageHeader>

		<div class="mt-4 space-y-6">
			<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
				<UCard
					v-for="card in summaryCards"
					:key="card.key"
					class="dark:bg-elevated/25"
					:ui="{ body: 'p-4 sm:p-4' }"
				>
					<div class="flex items-center justify-between gap-3">
						<div>
							<p class="text-xs font-semibold uppercase tracking-wide text-muted">
								{{ card.label }}
							</p>
							<p class="mt-2 text-2xl font-semibold text-toned">
								{{ card.value }}
							</p>
						</div>
						<div class="flex size-10 shrink-0 items-center justify-center rounded-sm bg-primary/10 text-primary">
							<UIcon :name="card.icon" class="size-5" />
						</div>
					</div>
				</UCard>
			</div>

			<div class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,0.95fr)]">
				<UCard class="overflow-hidden dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
					<div class="border-b border-default px-5 py-4">
						<p class="font-semibold text-md">
							{{ t("groups.leaderboard.featured.mvp_title") }}
						</p>
						<p class="text-sm text-muted">
							{{ t("groups.leaderboard.featured.mvp_subtitle") }}
						</p>
					</div>

					<div v-if="topParticipant" class="grid gap-5 p-5 md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center">
						<div class="relative">
							<img
								v-if="topParticipant.character.avatar_url"
								:src="topParticipant.character.avatar_url"
								:alt="topParticipant.character.name"
								class="size-20 rounded-sm object-cover"
							>
							<div v-else class="flex size-20 items-center justify-center rounded-sm bg-primary/10 text-xl font-semibold text-primary">
								{{ topParticipant.character.name.slice(0, 2) }}
							</div>
							<div class="absolute -right-2 -top-2 flex size-8 items-center justify-center rounded-sm bg-warning text-sm font-bold text-white">
								#1
							</div>
						</div>

						<div class="min-w-0">
							<p class="truncate text-2xl font-semibold text-highlighted">
								{{ topParticipant.character.name }}
							</p>
							<p v-if="characterWorld(topParticipant)" class="text-sm text-muted">
								{{ characterWorld(topParticipant) }}
							</p>
							<p class="mt-3 text-sm text-muted">
								{{ t("groups.leaderboard.featured.last_seen", { date: formatDate(topParticipant.latest_activity_at) }) }}
							</p>
						</div>

						<div class="rounded-sm border border-default bg-muted/10 px-5 py-4 text-right">
							<p class="text-3xl font-semibold text-toned">
								{{ formatNumber(topParticipant.count) }}
							</p>
							<p class="text-xs font-semibold uppercase tracking-wide text-muted">
								{{ t("groups.leaderboard.common.participations") }}
							</p>
						</div>
					</div>

					<div v-else class="p-5 text-sm text-muted">
						{{ t("groups.leaderboard.empty.overall") }}
					</div>
				</UCard>

				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-5 sm:p-5' }">
					<div class="flex items-start justify-between gap-4">
						<div>
							<div class="flex items-center gap-2">
								<p class="font-semibold text-md">
									{{ t("groups.leaderboard.featured.host_success_title") }}
								</p>
								<UTooltip :text="hostSuccessFormulaHint">
									<UButton
										color="neutral"
										variant="ghost"
										size="xs"
										icon="i-lucide-circle-help"
										:aria-label="t('groups.leaderboard.host_success.formula_label')"
									/>
								</UTooltip>
							</div>
							<p class="text-sm text-muted">
								{{ t("groups.leaderboard.featured.host_success_subtitle") }}
							</p>
						</div>
						<UBadge
							color="neutral"
							variant="subtle"
							:label="t('groups.leaderboard.host_success.minimum')"
						/>
					</div>

					<div v-if="topHostSuccess" class="mt-6 space-y-4">
						<div class="flex items-center justify-between gap-3">
							<div class="flex min-w-0 items-center gap-3">
								<img
									v-if="topHostSuccess.character.avatar_url"
									:src="topHostSuccess.character.avatar_url"
									:alt="topHostSuccess.character.name"
									class="size-12 rounded-sm object-cover"
								>
								<div v-else class="flex size-12 items-center justify-center rounded-sm bg-primary/10 font-semibold text-primary">
									{{ topHostSuccess.character.name.slice(0, 2) }}
								</div>
								<div class="min-w-0">
									<p class="truncate font-semibold text-toned">
										{{ topHostSuccess.character.name }}
									</p>
									<p class="text-xs text-muted">
										{{ t("groups.leaderboard.host_success.record", {
											successes: formatNumber(topHostSuccess.successful_runs),
											total: formatNumber(topHostSuccess.hosted_runs),
										}) }}
									</p>
								</div>
							</div>
							<p class="text-3xl font-semibold text-highlighted">
								{{ formatPercent(topHostSuccess.performance_score) }}
							</p>
						</div>

						<div class="h-2 overflow-hidden rounded-sm bg-muted">
							<div
								class="h-full rounded-sm bg-success"
								:style="{ width: `${topHostSuccess.performance_score}%` }"
							/>
						</div>
						<div class="flex flex-wrap gap-2 text-xs text-muted">
							<span>{{ t("groups.leaderboard.host_success.raw_success", { rate: formatPercent(topHostSuccess.success_rate) }) }}</span>
							<span>{{ t("groups.leaderboard.host_success.documented", { count: formatNumber(topHostSuccess.documented_successes) }) }}</span>
							<span>{{ t("groups.leaderboard.host_success.auto", { count: formatNumber(topHostSuccess.auto_successes) }) }}</span>
						</div>
					</div>

					<div v-else class="mt-6 text-sm text-muted">
						{{ t("groups.leaderboard.empty.host_success") }}
					</div>
				</UCard>
			</div>

			<div class="grid gap-6 xl:grid-cols-3">
				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
					<template #header>
						<div>
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div>
									<p class="font-semibold text-md">
										{{ t("groups.leaderboard.boards.overall.title") }}
									</p>
									<p class="text-sm text-muted">
										{{ t("groups.leaderboard.boards.overall.subtitle") }}
									</p>
								</div>
								<UTabs
									v-model="overallLimit"
									:items="limitTabs"
									:content="false"
									variant="pill"
									size="xs"
									class="shrink-0"
								/>
							</div>
							<UTabs
								v-model="overallPeriod"
								:items="periodTabs"
								:content="false"
								variant="link"
								size="sm"
								class="mt-3 w-full"
							/>
						</div>
					</template>

					<div v-if="rankingLoading.overall" class="divide-y divide-default">
						<div
							v-for="row in skeletonRows"
							:key="row"
							class="grid grid-cols-[2.5rem_minmax(0,1fr)_4rem] items-center gap-3 px-4 py-3"
						>
							<USkeleton class="h-6 w-8" />
							<div class="flex items-center gap-3">
								<USkeleton class="size-9 shrink-0" />
								<div class="w-full space-y-2">
									<USkeleton class="h-4 w-36 max-w-full" />
									<USkeleton class="h-3 w-24 max-w-full" />
								</div>
							</div>
							<USkeleton class="h-7 w-14" />
						</div>
					</div>
					<div
						v-else-if="overallRanking.length > 0"
						class="max-h-[38rem] divide-y divide-default overflow-y-auto overscroll-contain [scrollbar-gutter:stable]"
					>
						<div
							v-for="entry in overallRanking"
							:key="entry.character.id ?? entry.character.name"
							class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3"
						>
							<p class="text-lg font-semibold" :class="rankTone(entry.rank)">
								#{{ entry.rank }}
							</p>
							<div class="flex min-w-0 items-center gap-3">
								<img
									v-if="entry.character.avatar_url"
									:src="entry.character.avatar_url"
									:alt="entry.character.name"
									class="size-9 rounded-sm object-cover"
								>
								<div v-else class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-primary/10 text-xs font-semibold text-primary">
									{{ entry.character.name.slice(0, 2) }}
								</div>
								<div class="min-w-0">
									<p class="truncate font-medium text-toned">
										{{ entry.character.name }}
									</p>
									<p class="truncate text-xs text-muted">
										{{ characterWorld(entry) || formatDate(entry.latest_activity_at) }}
									</p>
								</div>
							</div>
							<div class="text-right">
								<p class="font-semibold text-toned">
									{{ formatNumber(entry.count) }}
								</p>
								<p class="text-xs text-muted">
									{{ t("groups.leaderboard.common.runs") }}
								</p>
							</div>
						</div>
					</div>
					<div v-else class="p-4 text-sm text-muted">
						{{ t("groups.leaderboard.empty.overall") }}
					</div>
				</UCard>

				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
					<template #header>
						<div>
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div>
									<p class="font-semibold text-md">
										{{ t("groups.leaderboard.boards.raid_leaders.title") }}
									</p>
									<p class="text-sm text-muted">
										{{ t("groups.leaderboard.boards.raid_leaders.subtitle") }}
									</p>
								</div>
								<UTabs
									v-model="raidLeaderLimit"
									:items="limitTabs"
									:content="false"
									variant="pill"
									size="xs"
									class="shrink-0"
								/>
							</div>
							<UTabs
								v-model="raidLeaderPeriod"
								:items="periodTabs"
								:content="false"
								variant="link"
								size="sm"
								class="mt-3 w-full"
							/>
						</div>
					</template>

					<div v-if="rankingLoading.raid_leaders" class="divide-y divide-default">
						<div
							v-for="row in skeletonRows"
							:key="row"
							class="grid grid-cols-[2.5rem_minmax(0,1fr)_4rem] items-center gap-3 px-4 py-3"
						>
							<USkeleton class="h-6 w-8" />
							<div class="space-y-2">
								<USkeleton class="h-4 w-36 max-w-full" />
								<USkeleton class="h-3 w-24 max-w-full" />
							</div>
							<USkeleton class="h-7 w-14" />
						</div>
					</div>
					<div
						v-else-if="raidLeaderRanking.length > 0"
						class="max-h-[38rem] divide-y divide-default overflow-y-auto overscroll-contain [scrollbar-gutter:stable]"
					>
						<div
							v-for="entry in raidLeaderRanking"
							:key="entry.character.id ?? entry.character.name"
							class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3"
						>
							<p class="text-lg font-semibold" :class="rankTone(entry.rank)">
								#{{ entry.rank }}
							</p>
							<div class="min-w-0">
								<p class="truncate font-medium text-toned">
									{{ entry.character.name }}
								</p>
								<p class="truncate text-xs text-muted">
									{{ characterWorld(entry) || formatDate(entry.latest_activity_at) }}
								</p>
							</div>
							<div class="text-right">
								<p class="font-semibold text-toned">
									{{ formatNumber(entry.count) }}
								</p>
								<p class="text-xs text-muted">
									{{ t("groups.leaderboard.common.runs") }}
								</p>
							</div>
						</div>
					</div>
					<div v-else class="p-4 text-sm text-muted">
						{{ t("groups.leaderboard.empty.raid_leaders") }}
					</div>
				</UCard>

				<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
					<template #header>
						<div>
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div>
									<p class="font-semibold text-md">
										{{ t("groups.leaderboard.boards.hosts.title") }}
									</p>
									<p class="text-sm text-muted">
										{{ t("groups.leaderboard.boards.hosts.subtitle") }}
									</p>
								</div>
								<UTabs
									v-model="hostLimit"
									:items="limitTabs"
									:content="false"
									variant="pill"
									size="xs"
									class="shrink-0"
								/>
							</div>
							<UTabs
								v-model="hostPeriod"
								:items="periodTabs"
								:content="false"
								variant="link"
								size="sm"
								class="mt-3 w-full"
							/>
						</div>
					</template>

					<div v-if="rankingLoading.hosts" class="divide-y divide-default">
						<div
							v-for="row in skeletonRows"
							:key="row"
							class="grid grid-cols-[2.5rem_minmax(0,1fr)_4rem] items-center gap-3 px-4 py-3"
						>
							<USkeleton class="h-6 w-8" />
							<div class="space-y-2">
								<USkeleton class="h-4 w-36 max-w-full" />
								<USkeleton class="h-3 w-24 max-w-full" />
							</div>
							<USkeleton class="h-7 w-14" />
						</div>
					</div>
					<div
						v-else-if="hostRanking.length > 0"
						class="max-h-[38rem] divide-y divide-default overflow-y-auto overscroll-contain [scrollbar-gutter:stable]"
					>
						<div
							v-for="entry in hostRanking"
							:key="entry.character.id ?? entry.character.name"
							class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3"
						>
							<p class="text-lg font-semibold" :class="rankTone(entry.rank)">
								#{{ entry.rank }}
							</p>
							<div class="min-w-0">
								<p class="truncate font-medium text-toned">
									{{ entry.character.name }}
								</p>
								<p class="truncate text-xs text-muted">
									{{ characterWorld(entry) || formatDate(entry.latest_activity_at) }}
								</p>
							</div>
							<div class="text-right">
								<p class="font-semibold text-toned">
									{{ formatNumber(entry.count) }}
								</p>
								<p class="text-xs text-muted">
									{{ t("groups.leaderboard.common.runs") }}
								</p>
							</div>
						</div>
					</div>
					<div v-else class="p-4 text-sm text-muted">
						{{ t("groups.leaderboard.empty.hosts") }}
					</div>
				</UCard>
			</div>

			<UCard class="dark:bg-elevated/25" :ui="{ body: 'p-0 sm:p-0' }">
				<template #header>
					<div class="flex items-start justify-between gap-4">
						<div>
							<p class="font-semibold text-md">
								{{ t("groups.leaderboard.host_success.title") }}
							</p>
							<p class="text-sm text-muted">
								{{ t("groups.leaderboard.host_success.subtitle") }}
							</p>
						</div>
						<UBadge
							color="neutral"
							variant="subtle"
							:label="t('groups.leaderboard.host_success.minimum')"
						/>
					</div>
				</template>

				<div v-if="leaderboard.rankings.host_success.length > 0" class="divide-y divide-default">
					<div
						v-for="entry in leaderboard.rankings.host_success"
						:key="entry.character.id ?? entry.character.name"
						class="grid gap-4 px-4 py-4 lg:grid-cols-[3rem_minmax(0,1fr)_13rem_12rem] lg:items-center"
					>
						<p class="text-lg font-semibold" :class="rankTone(entry.rank)">
							#{{ entry.rank }}
						</p>
						<div class="flex min-w-0 items-center gap-3">
							<img
								v-if="entry.character.avatar_url"
								:src="entry.character.avatar_url"
								:alt="entry.character.name"
								class="size-10 rounded-sm object-cover"
							>
							<div v-else class="flex size-10 shrink-0 items-center justify-center rounded-sm bg-primary/10 text-xs font-semibold text-primary">
								{{ entry.character.name.slice(0, 2) }}
							</div>
							<div class="min-w-0">
								<p class="truncate font-medium text-toned">
									{{ entry.character.name }}
								</p>
								<p class="truncate text-xs text-muted">
									{{ characterWorld(entry) || formatDate(entry.latest_activity_at) }}
								</p>
							</div>
						</div>

						<div>
							<UBadge
								:color="successTone(entry.performance_score)"
								variant="subtle"
								:label="formatPercent(entry.performance_score)"
							/>
							<p class="mt-1 text-xs text-muted">
								{{ t("groups.leaderboard.host_success.record", {
									successes: formatNumber(entry.successful_runs),
									total: formatNumber(entry.hosted_runs),
								}) }}
							</p>
							<p class="text-xs text-muted">
								{{ t("groups.leaderboard.host_success.raw_success", { rate: formatPercent(entry.success_rate) }) }}
							</p>
						</div>

						<div>
							<div class="h-2 overflow-hidden rounded-sm bg-muted">
								<div
									class="h-full rounded-sm bg-primary"
									:style="{ width: `${entry.performance_score}%` }"
								/>
							</div>
							<p class="mt-1 text-xs text-muted">
								{{ t("groups.leaderboard.host_success.breakdown", {
									documented: formatNumber(entry.documented_successes),
									auto: formatNumber(entry.auto_successes),
									failed: formatNumber(entry.failed_runs),
								}) }}
							</p>
						</div>
					</div>
				</div>
				<div v-else class="p-4 text-sm text-muted">
					{{ t("groups.leaderboard.empty.host_success") }}
				</div>
			</UCard>
		</div>
	</div>
</template>
