<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import PageHeader from "@/components/PageHeader.vue";
import type {
	GroupLegacyLeaderboardBadge,
	GroupLegacyLeaderboardEntry,
	GroupLegacyLeaderboardGroup,
	GroupLegacyLeaderboardPayload,
} from "@/Types/GroupLegacyLeaderboard";

const props = defineProps<{
	group: GroupLegacyLeaderboardGroup
	legacy_leaderboard: GroupLegacyLeaderboardPayload
}>();

const { t, locale } = useI18n();

const numberFormatter = computed(() => new Intl.NumberFormat(locale.value));
const formatNumber = (value: number) => numberFormatter.value.format(value);
const characterWorld = (entry: GroupLegacyLeaderboardEntry) => [
	entry.character.world,
	entry.character.datacenter,
].filter(Boolean).join(" / ");
const badgeLabel = (badge: GroupLegacyLeaderboardBadge) => t(`groups.legacy_leaderboard.badges.${badge.type}.${badge.key}`);
const badgeTone = (badge: GroupLegacyLeaderboardBadge) => {
	if (badge.type === "participation") {
		return {
			active: "border-neutral-400/35 bg-neutral-500/12 text-neutral-200",
			veteran: "border-sky-400/45 bg-sky-500/15 text-sky-200",
			elite: "border-violet-300/50 bg-violet-500/18 text-violet-100",
		}[badge.key] ?? "border-neutral-400/35 bg-neutral-500/12 text-neutral-200";
	}

	return {
		bronze: "border-orange-400/45 bg-orange-500/14 text-orange-200",
		silver: "border-zinc-300/45 bg-zinc-400/14 text-zinc-100",
		gold: "border-yellow-300/55 bg-yellow-500/16 text-yellow-100",
		platinum: "border-cyan-200/50 bg-cyan-400/14 text-cyan-100",
		diamond: "border-blue-300/55 bg-blue-500/16 text-blue-100",
		legendary: "border-fuchsia-300/55 bg-fuchsia-500/18 text-fuchsia-100",
	}[badge.key] ?? "border-neutral-400/35 bg-neutral-500/12 text-neutral-200";
};
const entryBadges = (entry: GroupLegacyLeaderboardEntry, type: GroupLegacyLeaderboardBadge["type"]) => (
	entry.badges.filter((badge) => badge.type === type)
);

const summaryCards = computed(() => [
	{
		key: "players",
		label: t("groups.legacy_leaderboard.summary.total_players"),
		value: formatNumber(props.legacy_leaderboard.summary.total_players),
		icon: "i-lucide-users",
	},
	{
		key: "participants",
		label: t("groups.legacy_leaderboard.summary.ranked_participants"),
		value: formatNumber(props.legacy_leaderboard.summary.ranked_participants),
		icon: "i-lucide-trophy",
	},
	{
		key: "participations",
		label: t("groups.legacy_leaderboard.summary.total_participations"),
		value: formatNumber(props.legacy_leaderboard.summary.total_participations),
		icon: "i-lucide-footprints",
	},
	{
		key: "raid_leaders",
		label: t("groups.legacy_leaderboard.summary.raid_leader_participations"),
		value: formatNumber(props.legacy_leaderboard.summary.total_raid_leader_participations),
		icon: "i-lucide-flag",
	},
]);

const boards = computed(() => [
	{
		key: "participations",
		badgeType: "participation" as const,
		title: t("groups.legacy_leaderboard.boards.participations.title"),
		subtitle: t("groups.legacy_leaderboard.boards.participations.subtitle"),
		primaryLabel: t("groups.legacy_leaderboard.boards.participations.primary_label"),
		primaryValue: (entry: GroupLegacyLeaderboardEntry) => entry.participation_count,
		secondaryLabel: t("groups.legacy_leaderboard.boards.participations.secondary_label"),
		secondaryValue: (entry: GroupLegacyLeaderboardEntry) => entry.raid_leader_count,
		entries: props.legacy_leaderboard.rankings.participations,
		empty: t("groups.legacy_leaderboard.boards.participations.empty"),
	},
	{
		key: "raid_leaders",
		badgeType: "leader" as const,
		title: t("groups.legacy_leaderboard.boards.raid_leaders.title"),
		subtitle: t("groups.legacy_leaderboard.boards.raid_leaders.subtitle"),
		primaryLabel: t("groups.legacy_leaderboard.boards.raid_leaders.primary_label"),
		primaryValue: (entry: GroupLegacyLeaderboardEntry) => entry.raid_leader_count,
		secondaryLabel: t("groups.legacy_leaderboard.boards.raid_leaders.secondary_label"),
		secondaryValue: (entry: GroupLegacyLeaderboardEntry) => entry.participation_count,
		entries: props.legacy_leaderboard.rankings.raid_leaders,
		empty: t("groups.legacy_leaderboard.boards.raid_leaders.empty"),
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
</script>

<template>
	<div class="w-full dark:[--ui-primary:var(--color-brand-400)]">
		<PageHeader
			:title="t('groups.legacy_leaderboard.title')"
			:subtitle="t('groups.legacy_leaderboard.subtitle', { group: group.name })"
		>
			<UBadge
				color="neutral"
				variant="subtle"
				icon="i-lucide-archive"
				:label="t('groups.legacy_leaderboard.badge')"
			/>
		</PageHeader>

		<div class="mt-4 space-y-6">
			<UAlert
				color="primary"
				variant="subtle"
				icon="i-lucide-sparkles"
				:title="t('groups.legacy_leaderboard.notice.title')"
				:description="t('groups.legacy_leaderboard.notice.description')"
			/>

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

			<div class="grid gap-6 xl:grid-cols-2">
				<UCard
					v-for="board in boards"
					:key="board.key"
					class="dark:bg-elevated/25"
					:ui="{ body: 'p-0 sm:p-0' }"
				>
					<template #header>
						<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
							<div>
								<h2 class="text-lg font-semibold text-toned">
									{{ board.title }}
								</h2>
								<p class="mt-1 text-sm text-muted">
									{{ board.subtitle }}
								</p>
							</div>
							<UBadge
								color="neutral"
								variant="subtle"
								:label="t('groups.legacy_leaderboard.boards.entry_count', { count: formatNumber(board.entries.length) })"
							/>
						</div>
					</template>

					<div
						v-if="board.entries.length > 0"
						class="max-h-[44rem] divide-y divide-default overflow-y-auto"
					>
						<div
							v-for="entry in board.entries"
							:key="`${board.key}-${entry.rank}-${entry.character.id ?? entry.character.name}`"
							class="flex items-center gap-4 px-4 py-3"
						>
							<div
								class="w-11 shrink-0 text-center text-lg font-black tabular-nums"
								:class="rankTone(entry.rank)"
							>
								#{{ entry.rank }}
							</div>

							<div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-sm border border-default bg-muted/20">
								<img
									v-if="entry.character.avatar_url"
									:src="entry.character.avatar_url"
									:alt="entry.character.name"
									class="size-full object-cover"
									loading="lazy"
								>
								<UIcon
									v-else
									name="i-lucide-user"
									class="size-5 text-muted"
								/>
							</div>

							<div class="min-w-0 flex-1">
								<div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
									<div class="min-w-0">
										<p class="truncate font-semibold text-toned">
											{{ entry.character.name }}
										</p>
										<p class="mt-1 truncate text-xs text-muted">
											{{ characterWorld(entry) || t('groups.legacy_leaderboard.common.unknown_world') }}
										</p>
									</div>

									<div
										v-if="entryBadges(entry, board.badgeType).length > 0"
										class="flex shrink-0 flex-wrap gap-1.5 sm:justify-end"
									>
										<span
											v-for="badge in entryBadges(entry, board.badgeType)"
											:key="`${entry.rank}-${badge.type}-${badge.key}`"
											class="inline-flex items-center gap-1.5 rounded-sm border px-2 py-1 text-[11px] font-bold uppercase tracking-wide sm:text-xs"
											:class="badgeTone(badge)"
										>
											<UIcon :name="badge.icon" class="size-3.5 sm:size-4" />
											<span>{{ badgeLabel(badge) }}</span>
										</span>
									</div>
								</div>
							</div>

							<div class="grid shrink-0 grid-cols-2 gap-3 text-right">
								<div>
									<p class="text-xs uppercase tracking-wide text-muted">
										{{ board.primaryLabel }}
									</p>
									<p class="mt-1 text-lg font-semibold text-toned">
										{{ formatNumber(board.primaryValue(entry)) }}
									</p>
								</div>

								<div class="hidden sm:block">
									<p class="text-xs uppercase tracking-wide text-muted">
										{{ board.secondaryLabel }}
									</p>
									<p class="mt-1 text-lg font-semibold text-muted">
										{{ formatNumber(board.secondaryValue(entry)) }}
									</p>
								</div>
							</div>
						</div>
					</div>

					<p v-else class="px-4 py-10 text-center text-sm text-muted">
						{{ board.empty }}
					</p>
				</UCard>
			</div>
		</div>
	</div>
</template>
